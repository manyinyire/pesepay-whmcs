<?php
declare(strict_types=1);
namespace Azaire\PesePay;

final class Service
{
    private Store $store;
    private Api $api;
    private array $config;
    private $invoice;
    private $credit;
    private $log;
    public function __construct(Store $store, Api $api, array $config, callable $invoice, callable $credit, callable $log)
    {
        $this->store=$store; $this->api=$api; $this->config=$config;
        $this->invoice=$invoice; $this->credit=$credit; $this->log=$log;
    }
    public function methods(string $currency, int $cents): array
    {
        if ($this->config['environment'] === 'sandbox') {
            // PesePay's sandbox only supports EcoCash in USD.
            if ($currency !== 'USD') { return []; }
            return [['code'=>'PZW211','name'=>'EcoCash','currency'=>'USD','phone'=>'ecocash','message'=>'Test mode: use 0777777777 for success or 0770000000 for failure.','minimum'=>1,'maximum'=>null]];
        }
        $currencies = in_array(strtoupper($currency), ['ZIG','ZWG'], true) ? ['ZiG','ZWG'] : [$currency];
        $output=[];
        foreach ($currencies as $apiCurrency) {
            foreach ($this->api->methods($apiCurrency) as $row) {
                $code = $row['code'] ?? '';
                $known = Policy::METHODS[$code] ?? null;
                if (!$known || $known['currency'] !== $apiCurrency || empty($row['active']) || !empty($row['redirectRequired'])) { continue; }
                if (empty($this->config['enable_' . $code])) { continue; }
                $supported = true;
                foreach ($row['requiredFields'] ?? [] as $field) {
                    if (empty($field['optional']) && (($field['name'] ?? '') !== 'customerPhoneNumber' || $known['phone'] === '')) { $supported=false; }
                }
                if (!$supported) { continue; }
                $min=Policy::cents($row['minimumAmount']); $max=Policy::cents($row['maximumAmount']);
                if ($cents < $min || ($cents > $max && $known['phone'] !== 'ecocash')) { continue; }
                $output[]=['code'=>$code,'name'=>$known['name'],'currency'=>$apiCurrency,'phone'=>$known['phone'],
                    'message'=>substr((string) ($row['processingPaymentMessage'] ?? ''),0,500), 'minimum'=>$min,'maximum'=>$max];
            }
        }
        return $output;
    }
    public function start(int $invoiceId, int $clientId, string $requestKey, string $method, string $phone): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $requestKey)) { throw new \InvalidArgumentException('Invalid checkout request.'); }
        return $this->store->lock($invoiceId, function () use ($invoiceId,$clientId,$requestKey,$method,$phone) {
            $invoice = ($this->invoice)($invoiceId);
            if ((int) $invoice['client_id'] !== $clientId) { throw new \RuntimeException('Invoice access denied.'); }
            $existing=$this->store->request($requestKey);
            if ($existing) {
                if ((int)$existing['invoice_id'] !== $invoiceId || (int)$existing['client_id'] !== $clientId) { throw new \RuntimeException('Invalid checkout request.'); }
                return $existing;
            }
            $last = $this->store->latest($invoiceId,$this->config['environment']);
            if ($last && !(int)$last['credited'] && !in_array($last['status'],Policy::RETRYABLE,true)) { return $last; }
            $cents=Policy::cents($invoice['balance']);
            if ($invoice['status'] !== 'Unpaid' || $cents <= 0 || $invoice['gateway'] !== 'pesepaydirect') { throw new \RuntimeException('This invoice is not payable with PesePay. Reload the invoice.'); }
            $available=$this->methods($invoice['currency'],$cents);
            $selected=null;
            foreach ($available as $option) { if ($option['code'] === $method) { $selected=$option; break; } }
            if (!$selected) { throw new \InvalidArgumentException('That method is unavailable for this amount and currency.'); }
            $fields=Policy::fields($method,$phone);
            $token=bin2hex(random_bytes(24));
            $callback=$this->config['systemurl'].'modules/gateways/callback/pesepaydirect.php?attempt='.$token;
            $a=$this->store->insert(['token'=>$token,'request_key'=>$requestKey,'invoice_id'=>$invoiceId,'client_id'=>$clientId,
                'amount_cents'=>$cents,'invoice_currency'=>$invoice['currency'],'api_currency'=>$selected['currency'],
                'method'=>$method,'environment'=>$this->config['environment'],'callback_url'=>$callback,
                'status'=>'STARTING','metadata'=>'{}','created_at'=>time()]);
            // Field set mirrors PesePay's official PHP SDK (codevirtus/pesepay 1.0.3) makeSeamlessPayment.
            $customer=array_filter(['email'=>$invoice['email'] ?? null,'phoneNumber'=>$fields->customerPhoneNumber ?? null,'name'=>$invoice['name'] ?? null],static fn($v)=>$v!==null && $v!=='');
            $body=['amountDetails'=>['amount'=>$cents/100,'currencyCode'=>$a['api_currency']],'currencyCode'=>$a['api_currency'],
                'customer'=>$customer ?: new \stdClass(),
                'merchantReference'=>'WHMCS-'.$invoiceId.'-'.$token,'reasonForPayment'=>'Invoice #'.$invoiceId,
                'resultUrl'=>$callback,'returnUrl'=>$this->config['systemurl'].'viewinvoice.php?id='.$invoiceId,
                'paymentMethodCode'=>$method,'paymentMethodRequiredFields'=>$fields,'paymentRequestFields'=>$fields];
            $result=null;
            try {
                $result=$this->api->makePayment($body);
                $reference=$result['referenceNumber'] ?? '';
                // The sandbox returns referenceNumber=null on initiation; the reference is still inside pollUrl.
                if (!is_string($reference) || $reference==='') { $reference=self::pollReference($result['pollUrl'] ?? null); $result['referenceNumber']=$reference; }
                Policy::reference($reference);
                // Persist the reference before further validation, so a malformed response can be reconciled.
                $this->store->update((int)$a['id'],['reference'=>$reference]);
                $a['reference']=$reference;
                Policy::verify($a,$result,$reference);
                // Initiation can never credit an invoice or authorise a new attempt.
                $this->store->update((int)$a['id'],['status'=>'AWAITING_VERIFICATION','metadata'=>json_encode(Policy::metadata($result),JSON_THROW_ON_ERROR)]);
            } catch (\Throwable $e) {
                // A 4xx rejection created no payment, so the customer may retry. Anything else is ambiguous.
                $rejected=$e instanceof ApiError && $e->rejected() && empty($a['reference']);
                $this->store->update((int)$a['id'],['status'=>$rejected?'REJECTED':'UNKNOWN']);
                // When the reply itself was unexpected, log its shape (field names and types only, never values).
                $shape=is_array($result) ? ' Response fields: '.self::shape($result) : '';
                ($this->log)($rejected?'Payment request rejected by PesePay':'Initiation requires reconciliation',(int)$a['id'],self::reason($e).$shape);
            }
            return $this->store->get((int)$a['id']);
        });
    }
    public function refresh(int $id, bool $force=false): array
    {
        $original=$this->store->get($id);
        if (!$original) { throw new \RuntimeException('Payment not found.'); }
        return $this->store->lock((int)$original['invoice_id'], function () use ($id,$force) {
            $a=$this->store->get($id);
            if ((int)$a['credited']) { return $a; }
            if ($a['environment'] !== $this->config['environment']) { throw new \RuntimeException('Payment belongs to a different PesePay environment.'); }
            $reference=$a['reference'] ?: $a['candidate_reference'];
            if (!$reference || (!$force && time()-(int)$a['checked_at']<8)) { return $a; }
            $this->store->update($id,['checked_at'=>time()]);
            $result=$this->api->check($reference);
            $status=Policy::verify($a,$result,$reference);
            $metadata=array_merge(json_decode($a['metadata'],true) ?: [],Policy::metadata($result));
            $this->store->update($id,['reference'=>$reference,'status'=>$status,'metadata'=>json_encode($metadata,JSON_THROW_ON_ERROR),'needs_check'=>0]);
            if ($status === 'SUCCESS') {
                $invoice=($this->invoice)((int)$a['invoice_id']);
                if ((int)$invoice['client_id'] !== (int)$a['client_id'] || $invoice['currency'] !== $a['invoice_currency']) { throw new \RuntimeException('Invoice ownership or currency changed; manual reconciliation required.'); }
                if (!in_array($invoice['status'],['Unpaid','Paid','Payment Pending'],true)) { throw new \RuntimeException('Invoice status requires manual reconciliation.'); }
                $test=$a['environment']==='sandbox';
                // Test mode records the payment too (UAT end-to-end), with an unmistakable transaction ID.
                ($this->credit)((int)$a['invoice_id'],($test?'PesePay-TEST-':'PesePay-').$reference,Policy::decimal((int)$a['amount_cents']));
                $this->store->update($id,['credited'=>1]);
                ($this->log)($test?'Test payment recorded (no real money)':'Payment recorded',$id);
            }
            return $this->store->get($id);
        });
    }
    public function otp(int $id, string $otp): array
    {
        if (!preg_match('/^[0-9]{6}$/D',$otp)) { throw new \InvalidArgumentException('Enter the six-digit SMS code.'); }
        $a=$this->store->get($id);
        if (!$a) { throw new \RuntimeException('Payment not found.'); }
        $this->store->lock((int)$a['invoice_id'],function () use ($id,$otp) {
            $a=$this->store->get($id);
            if ($a['environment'] !== $this->config['environment'] || $a['environment'] !== 'production') { throw new \RuntimeException('O’mari environment mismatch.'); }
            if (!in_array($a['method'],['PZW216','PZW217'],true) || !$a['reference'] || (int)$a['otp_submitted'] || (int)$a['credited'] || in_array($a['status'],Policy::FAILURES,true)) { throw new \RuntimeException('The OTP step is unavailable. Check the payment status.'); }
            // Write before sending: a timeout must not trigger a second debit attempt.
            $this->store->update($id,['otp_submitted'=>1,'needs_check'=>1]);
            try { $this->api->chargeOtp($a['reference'],$otp); }
            catch (\Throwable $e) { ($this->log)('OTP outcome requires reconciliation',$id,self::reason($e)); }
        });
        return $this->refresh($id,true);
    }
    // Log-safe failure reason: PesePay's sanitized message, or only the exception type for anything else.
    public static function reason(\Throwable $e): string
    {
        if ($e instanceof ApiError) return $e->getMessage();
        if ($e instanceof \JsonException) return 'PesePay returned an unreadable response';
        $known=['PesePay connection failed; the payment outcome is unknown.','Missing encrypted response.','Invalid encrypted response.','Cannot decrypt PesePay response.','Invalid PesePay response.','Invalid transaction reference.',
            'Transaction reference mismatch.','Payment does not belong to this attempt.','Payment amount or currency mismatch.','Invalid payment status.','Invalid payment amount.'];
        return in_array($e->getMessage(),$known,true) ? $e->getMessage() : get_class($e);
    }
    // Reference only, taken from a PesePay-hosted check-payment URL. The URL itself is never fetched.
    public static function pollReference($url): string
    {
        if (!is_string($url)) return '';
        $parts=parse_url($url);
        if (($parts['scheme'] ?? '')!=='https' || !in_array($parts['host'] ?? '',['api.pesepay.com','api.test.sandbox.pesepay.com'],true)
            || !str_ends_with($parts['path'] ?? '','/v1/payments/check-payment') || isset($parts['user']) || isset($parts['port'])) return '';
        parse_str($parts['query'] ?? '',$query);
        return is_string($query['referenceNumber'] ?? null) ? $query['referenceNumber'] : '';
    }
    public static function shape(array $data, int $depth=0): string
    {
        $parts=[];
        foreach (array_slice($data,0,30,true) as $key=>$value) {
            $name=preg_replace('/[^A-Za-z0-9_]/','',(string)$key);
            if (is_array($value)) $parts[]=$name.($depth<1 ? '{'.self::shape($value,$depth+1).'}' : '{…}');
            else $parts[]=$name.':'.(is_string($value) ? 'string('.strlen($value).')' : gettype($value));
        }
        return implode(',',$parts);
    }
    public static function view(array $a): array
    {
        $paid=(bool)$a['credited']; $status=$a['status'];
        $terminal=in_array($status,Policy::FAILURES,true);
        return ['id'=>(int)$a['id'],'status'=>$status,'paid'=>$paid,'test'=>$a['environment']==='sandbox',
            'retryable'=>!$paid && in_array($status,Policy::RETRYABLE,true),
            'terminal'=>$paid || $terminal,'method'=>$a['method'],'reference'=>$a['reference'],
            'needsOtp'=>!$paid && !$terminal && $a['reference'] && !(int)$a['otp_submitted'] && in_array($a['method'],['PZW216','PZW217'],true),
            'metadata'=>json_decode($a['metadata'],true) ?: []];
    }
}
