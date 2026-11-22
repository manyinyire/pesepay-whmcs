<?php
declare(strict_types=1);
use Azaire\PesePay\{Api,Policy,Store,Service};
foreach (['Policy','Api','Store','Service'] as $class) { require_once __DIR__.'/../modules/gateways/pesepaydirect/lib/'.$class.'.php'; }
set_error_handler(static function ($severity,$message,$file,$line) { throw new ErrorException($message,0,$severity,$file,$line); });
function ok($condition,string $message='Assertion failed'): void { if (!$condition) { throw new RuntimeException($message); } }
function throws(callable $fn,string $contains=''): void {
    try { $fn(); } catch (Throwable $e) { ok($contains==='' || str_contains($e->getMessage(),$contains),'Wrong exception: '.$e->getMessage()); return; }
    throw new RuntimeException('Expected an exception.');
}
function connection(): PDO {
    $dsn=getenv('PESEPAY_TEST_DSN');
    if (!$dsn || !str_contains($dsn,'dbname=pesepay_test')) { throw new RuntimeException('Set PESEPAY_TEST_DSN to an isolated MySQL database named pesepay_test.'); }
    return new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
}
class Fixture {
    public Store $store; public Api $api; public Service $service; public array $requests=[]; public array $results=[]; public array $ledger=[];
    public array $invoice=['client_id'=>7,'currency'=>'USD','balance'=>'10.00','status'=>'Unpaid','gateway'=>'pesepaydirect'];
    public array $config; public string $failure=''; public int $makes=0; public int $otpCalls=0; public int $credits=0;
    public string $reject=''; public bool $nullReference=false;
    public function __construct(string $environment='production') {
        $pdo=connection(); $this->store=new Store($pdo); $this->store->install(); $pdo->exec('TRUNCATE TABLE '.Store::TABLE);
        $this->config=['environment'=>$environment,'systemurl'=>'https://billing.example.test/','enable_PZW211'=>'on','enable_PZW212'=>'on','enable_PZW216'=>'on','enable_PZW201'=>'on','enable_PZW217'=>'on','enable_PZW210'=>'on'];
        $this->api=new Api('test-integration','0123456789abcdef0123456789abcdef',function($method,$url,$headers,$body) {
            $this->requests[]=[$method,$url,$headers,$body];
            if (str_contains($url,'payment-methods/')) {
                return [200,json_encode(array_map(static fn($code,$m)=>['code'=>$code,'active'=>true,'redirectRequired'=>false,'minimumAmount'=>0.01,'maximumAmount'=>500,'requiredFields'=>$m['phone']?[['name'=>'customerPhoneNumber','optional'=>false]]:[]],array_keys(Policy::METHODS),array_values(Policy::METHODS)))];
            }
            if (str_contains($url,'make-payment')) {
                $this->makes++;
                if ($this->reject!=='') { return [(int)$this->reject,json_encode(['message'=>'Invalid customer phone 0777123456'])]; }
                $input=$this->api->decrypt(json_decode($body,true)['payload']);
                $ref='REF-'.$this->makes;
                $result=['referenceNumber'=>$ref,'resultUrl'=>$input['resultUrl'],'amountDetails'=>$input['amountDetails'],'transactionStatus'=>'PENDING','transactionMetadata'=>['otpReference'=>'otp-ref']];
                $this->results[$ref]=$result;
                if ($this->nullReference) { $result['referenceNumber']=null; $result['pollUrl']='https://api.pesepay.com/api/payments-engine/v1/payments/check-payment?referenceNumber='.$ref; }
                if ($this->failure==='timeout') { throw new RuntimeException('Connection timeout'); }
                if ($this->failure==='success-on-initiate') { $result['transactionStatus']='SUCCESS'; }
                return [200,json_encode(['payload'=>$this->api->encrypt($result)])];
            }
            if (str_contains($url,'/omari/')) { $this->otpCalls++; throw new RuntimeException('OTP response lost'); }
            parse_str(parse_url($url,PHP_URL_QUERY),$query);
            return [200,json_encode(['payload'=>$this->api->encrypt($this->results[$query['referenceNumber']])])];
        },$environment==='sandbox');
        $this->service=new Service($this->store,$this->api,$this->config,fn($id)=>$this->invoice,function($id,$ref,$amount) { $this->credits++; $this->ledger[$ref]=[$id,$amount]; },static function($event,$attempt) {});
    }
    public function start(string $method='PZW211',?string $request=null): array { return $this->service->start(42,7,$request??bin2hex(random_bytes(16)),$method,'0771234567'); }
    public function success(array $a): array { $this->results[$a['reference']]['transactionStatus']='SUCCESS'; return $this->service->refresh((int)$a['id'],true); }
}
$tests=[];
$tests['money is exact and rejects ambiguous values']=function() {
    ok(Policy::cents('0.01')===1); ok(Policy::cents('10.10')===1010); ok(Policy::cents('10.1000')===1010); ok(Policy::decimal(1010)==='10.10');
    foreach (['-1','1e3','NaN','10.001',null,[],true] as $bad) { throws(fn()=>Policy::cents($bad)); }
};
$tests['encryption interoperates with an independent OpenSSL vector']=function() {
    $api=new Api('integration','0123456789abcdef0123456789abcdef');
    // Fixture generated independently with Node crypto AES-256-CBC, PKCS7, IV=first 16 key bytes.
    $vector=trim(file_get_contents(__DIR__.'/crypto-vector.txt'));
    ok($api->encrypt(['amountDetails'=>['amount'=>10,'currencyCode'=>'USD']])===$vector);
    ok($api->decrypt($vector)['amountDetails']['amount']===10);
    throws(fn()=>$api->decrypt('not base64!')); throws(fn()=>new Api('integration','short'));
};
$tests['provider phone formats and empty-object fields']=function() {
    ok(Policy::fields('PZW216','0771234567')->customerPhoneNumber==='263771234567');
    ok(Policy::fields('PZW211','0777777777')->customerPhoneNumber==='0777777777');
    ok(json_encode(Policy::fields('PZW212',''))==='{}'); throws(fn()=>Policy::fields('PZW211','0711234567'));
};
$tests['initiation never credits, even for an immediate success response']=function() {
    $f=new Fixture(); $f->failure='success-on-initiate'; $a=$f->start(); ok($f->credits===0); ok(!(int)$a['credited']);
    ok(str_contains($f->requests[1][1],'/api/payments-engine/v2/payments/make-payment'));
};
$tests['server invoice balance is the charged amount']=function() {
    $f=new Fixture(); $f->invoice['balance']='3.17'; $a=$f->start(); ok((int)$a['amount_cents']===317);
    ok(Policy::cents($f->results[$a['reference']]['amountDetails']['amount'])===317);
};
$tests['duplicate start and new clicks reuse pending payment']=function() {
    $f=new Fixture(); $key=bin2hex(random_bytes(16)); $a=$f->start('PZW211',$key); $b=$f->start('PZW211',$key); $c=$f->start();
    ok($a['id']===$b['id'] && $b['id']===$c['id']); ok($f->makes===1);
};
$tests['lost initiation response blocks double charging and callback can recover']=function() {
    $f=new Fixture(); $f->failure='timeout'; $a=$f->start(); ok($a['status']==='UNKNOWN'); $f->start(); ok($f->makes===1);
    $f->store->queue((int)$a['id'],'REF-1'); $f->results['REF-1']['transactionStatus']='SUCCESS';
    $paid=$f->service->refresh((int)$a['id'],true); ok((int)$paid['credited']===1 && $f->credits===1);
};
$tests['verified success credits once across repeated callbacks and polls']=function() {
    $f=new Fixture(); $a=$f->start(); $f->success($a); $f->store->queue((int)$a['id'],$a['reference']); $f->service->refresh((int)$a['id'],true);
    ok($f->credits===1); ok($f->ledger['PesePay-'.$a['reference']]===[42,'10.00']);
};
$tests['poll throttling avoids repeated provider requests']=function() {
    $f=new Fixture(); $a=$f->start(); $f->service->refresh((int)$a['id']); $count=count($f->requests); $f->service->refresh((int)$a['id']); ok(count($f->requests)===$count);
};
$tests['partial payment does not settle or permit a second payment']=function() {
    $f=new Fixture(); $a=$f->start(); $f->results[$a['reference']]['transactionStatus']='PARTIALLY_PAID'; $b=$f->service->refresh((int)$a['id'],true);
    ok(!$f->credits && !Service::view($b)['terminal']); $f->start(); ok($f->makes===1);
};
$tests['amount currency callback URL and reference mismatches fail closed']=function() {
    foreach (['amount','currency','callback','reference'] as $field) {
        $f=new Fixture(); $a=$f->start(); $r=&$f->results[$a['reference']]; $r['transactionStatus']='SUCCESS';
        if ($field==='amount') $r['amountDetails']['amount']=0.01;
        if ($field==='currency') $r['amountDetails']['currencyCode']='ZWG';
        if ($field==='callback') $r['resultUrl']='https://billing.example.test/other';
        if ($field==='reference') $r['referenceNumber']='WRONG';
        throws(fn()=>$f->service->refresh((int)$a['id'],true)); ok($f->credits===0);
        unset($r);
    }
};
$tests['unrecognized statuses never settle or allow retry']=function() {
    $f=new Fixture(); $a=$f->start(); $f->results[$a['reference']]['transactionStatus']='NEW_PROVIDER_STATE';
    $b=$f->service->refresh((int)$a['id'],true); ok(!$f->credits && !Service::view($b)['retryable']);
};
$tests['decline allows explicit retry but replayed request cannot create one']=function() {
    $f=new Fixture(); $key=bin2hex(random_bytes(16)); $a=$f->start('PZW211',$key); $f->results[$a['reference']]['transactionStatus']='DECLINED';
    $f->service->refresh((int)$a['id'],true); $f->start('PZW211',$key); ok($f->makes===1); $f->start(); ok($f->makes===2);
};
$tests['uncertain terminal statuses block retry']=function() {
    foreach (['FAILED','TIME_OUT','SERVICE_UNAVAILABLE','ERROR'] as $status) {
        $f=new Fixture(); $a=$f->start(); $f->results[$a['reference']]['transactionStatus']=$status; $f->service->refresh((int)$a['id'],true); $f->start(); ok($f->makes===1);
    }
};
$tests['production request matches the official SDK field set and headers']=function() {
    $f=new Fixture(); $f->invoice+=['email'=>'client@example.test','name'=>'Jane Client']; $a=$f->start();
    $make=array_values(array_filter($f->requests,fn($r)=>str_contains($r[1],'make-payment')))[0];
    ok($make[1]==='https://api.pesepay.com/api/payments-engine/v2/payments/make-payment');
    ok(in_array('Authorization: test-integration',$make[2],true) && in_array('key: test-integration',$make[2],true),'both auth headers');
    $body=$f->api->decrypt(json_decode($make[3],true)['payload']);
    ok($body['currencyCode']==='USD' && $body['customer']===['email'=>'client@example.test','phoneNumber'=>'0771234567','name'=>'Jane Client']);
    ok($body['paymentRequestFields']===$body['paymentMethodRequiredFields']);
    $b=$f->success($a); ok((int)$b['credited']===1 && $f->credits===1,'production credits the invoice');
};
$tests['a 4xx rejection allows retry and logs only a sanitized reason']=function() {
    $f=new Fixture(); $f->reject='400'; $logs=[];
    $service=new Service($f->store,$f->api,$f->config,fn()=>$f->invoice,function() {},function($e,$id,$reason='') use (&$logs) { $logs[]=[$e,$reason]; });
    $a=$service->start(42,7,bin2hex(random_bytes(16)),'PZW211','0771234567');
    ok($a['status']==='REJECTED' && $a['reference']===null);
    ok(str_contains($logs[0][1],'HTTP 400') && str_contains($logs[0][1],'Invalid customer phone') && !str_contains($logs[0][1],'0777123456'),'reason sanitized');
    $f->reject=''; $b=$service->start(42,7,bin2hex(random_bytes(16)),'PZW211','0771234567'); ok((int)$b['id']!==(int)$a['id'],'retry permitted');
};
$tests['a 5xx during initiation stays unknown and blocks a second charge']=function() {
    $f=new Fixture(); $f->reject='500'; $a=$f->start(); ok($a['status']==='UNKNOWN');
    $f->reject=''; $b=$f->start(); ok((int)$b['id']===(int)$a['id'] && $f->makes===1,'no second charge');
};
$tests['reference is recovered from a PesePay pollUrl when referenceNumber is null']=function() {
    $base='https://api.test.sandbox.pesepay.com/payments-engine/v1/payments/check-payment?referenceNumber=';
    ok(Service::pollReference($base.'20261122114926323-802D5575')==='20261122114926323-802D5575');
    foreach ([null,'http://api.pesepay.com/api/payments-engine/v1/payments/check-payment?referenceNumber=X','https://evil.test/v1/payments/check-payment?referenceNumber=X','https://api.pesepay.com/other?referenceNumber=X','https://u@api.pesepay.com/api/payments-engine/v1/payments/check-payment?referenceNumber=X'] as $bad) ok(Service::pollReference($bad)==='','rejected '.json_encode($bad));
    $f=new Fixture(); $f->nullReference=true; $a=$f->start(); ok($a['reference']==='REF-1' && $a['status']==='AWAITING_VERIFICATION');
};
$tests['reconciliation only picks up the current mode']=function() {
    $f=new Fixture(); $a=$f->start(); $f->store->update((int)$a['id'],['environment'=>'sandbox']);
    ok(count($f->store->due('production'))===0); ok(count($f->store->due('sandbox'))===1);
};
$tests['test mode uses the sandbox URL and records a clearly labelled payment']=function() {
    $f=new Fixture('sandbox');
    ok(array_column($f->service->methods('USD',100),'code')===['PZW211']); ok($f->service->methods('ZWG',100)===[]);
    $a=$f->start(); ok($a['environment']==='sandbox');
    ok(str_starts_with(array_values(array_filter($f->requests,fn($r)=>str_contains($r[1],'make-payment')))[0][1],'https://api.test.sandbox.pesepay.com/payments-engine/v2/'));
    $b=$f->success($a); ok((int)$b['credited']===1 && $f->credits===1); ok(array_keys($f->ledger)===['PesePay-TEST-'.$a['reference']]);
    ok(\Azaire\PesePay\Service::view($b)['test']===true);
};
$tests['OTP timeout cannot resend the debit and sends no integration header']=function() {
    $f=new Fixture(); $a=$f->start('PZW216'); $f->service->otp((int)$a['id'],'123456');
    throws(fn()=>$f->service->otp((int)$a['id'],'123456')); ok($f->otpCalls===1 && $f->credits===0);
    $otp=array_values(array_filter($f->requests,fn($r)=>str_contains($r[1],'/omari/')))[0];
    ok(!str_contains(implode(' ',$otp[2]),'test-integration')); ok(!isset(json_decode($otp[3],true)['payload']));
};
$tests['cross-client, paid and wrong-gateway invoices cannot initiate']=function() {
    $f=new Fixture(); throws(fn()=>$f->service->start(42,8,str_repeat('a',32),'PZW211','0771234567'));
    $f->invoice['status']='Paid'; throws(fn()=>$f->start()); $f->invoice['status']='Unpaid'; $f->invoice['gateway']='payzw'; throws(fn()=>$f->start()); ok($f->makes===0);
};
$tests['invoice ownership or currency changes prevent settlement']=function() {
    foreach (['client_id'=>99,'currency'=>'EUR','status'=>'Cancelled'] as $field=>$value) {
        $f=new Fixture(); $a=$f->start(); $f->invoice[$field]=$value; throws(fn()=>$f->success($a)); ok($f->credits===0);
    }
};
$tests['callback queue cannot replace a known reference']=function() {
    $f=new Fixture(); $a=$f->start(); $f->store->queue((int)$a['id'],'FOREIGN'); $b=$f->store->get((int)$a['id']); ok($b['candidate_reference']===null);
};
$tests['reconciliation finds payments without a delivered callback']=function() {
    $f=new Fixture(); $a=$f->start(); $due=$f->store->due(); ok(count($due)===1); $f->success($due[0]); ok(count($f->store->due())===0);
};
$tests['HTTP errors, redirects and unencrypted responses are rejected']=function() {
    foreach ([[302,'{}'],[500,'{}'],[200,'{"transactionStatus":"SUCCESS"}']] as $response) {
        $api=new Api('test','0123456789abcdef0123456789abcdef',fn()=>$response); throws(fn()=>$api->check('REF-1'));
    }
};
$tests['provider pollUrl cannot redirect authenticated requests']=function() {
    $f=new Fixture(); $a=$f->start(); $f->results[$a['reference']]['pollUrl']='https://attacker.invalid/steal'; $f->service->refresh((int)$a['id'],true);
    $request=end($f->requests); ok(str_starts_with($request[1],'https://api.pesepay.com/api/payments-engine/v1/payments/check-payment?'));
};
$tests['environment mismatch cannot verify using the wrong merchant keys']=function() {
    $f=new Fixture(); $a=$f->start(); $f->store->update((int)$a['id'],['environment'=>'sandbox']); throws(fn()=>$f->service->refresh((int)$a['id'],true),'environment');
};

$tests['concurrent reconciliation workers record only one payment']=function() {
    $f=new Fixture(); $a=$f->start(); $result=$f->results[$a['reference']]; $result['transactionStatus']='SUCCESS';
    $pdo=connection(); $pdo->exec('CREATE TABLE IF NOT EXISTS test_ledger (reference VARCHAR(128) PRIMARY KEY, invoice_id INT NOT NULL)'); $pdo->exec('TRUNCATE TABLE test_ledger'); unset($pdo);
    $pids=[];
    for ($i=0;$i<2;$i++) {
        $pid=pcntl_fork();
        if ($pid===0) {
            try {
                $db=connection(); $store=new Store($db);
                $api=null;
                $api=new Api('test','0123456789abcdef0123456789abcdef',function() use (&$api,$result) { usleep(100000); return [200,json_encode(['payload'=>$api->encrypt($result)])]; });
                $service=new Service($store,$api,$f->config,fn()=>$f->invoice,function($id,$reference,$amount) use ($db) {
                    $q=$db->prepare('INSERT INTO test_ledger (reference,invoice_id) VALUES (?,?)'); $q->execute([$reference,$id]);
                },static function() {});
                $service->refresh((int)$a['id'],true); exit(0);
            } catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
        }
        if ($pid<0) throw new RuntimeException('Cannot fork test worker.');
        $pids[]=$pid;
    }
    foreach ($pids as $pid) { pcntl_waitpid($pid,$status); ok(pcntl_wexitstatus($status)===0,'Concurrent worker failed'); }
    $db=connection(); ok((int)$db->query('SELECT COUNT(*) FROM test_ledger')->fetchColumn()===1);
    ok((int)(new Store($db))->get((int)$a['id'])['credited']===1);
};
$tests['settlement interrupted after ledger write can recover without duplicate entry']=function() {
    $f=new Fixture(); $a=$f->start(); $f->results[$a['reference']]['transactionStatus']='SUCCESS';
    $db=connection(); $db->exec('CREATE TABLE IF NOT EXISTS test_ledger (reference VARCHAR(128) PRIMARY KEY, invoice_id INT NOT NULL)'); $db->exec('TRUNCATE TABLE test_ledger');
    $store=new class($db) extends Store {
        private bool $fail=true;
        public function update(int $id,array $data): void {
            if (isset($data['credited']) && $this->fail) { $this->fail=false; throw new RuntimeException('Simulated crash after ledger write'); }
            parent::update($id,$data);
        }
    };
    $service=new Service($store,$f->api,$f->config,fn()=>$f->invoice,function($id,$reference,$amount) use ($db) {
        $q=$db->prepare('SELECT invoice_id FROM test_ledger WHERE reference=?'); $q->execute([$reference]);
        if ($q->fetchColumn()!==false) return;
        $q=$db->prepare('INSERT INTO test_ledger VALUES (?,?)'); $q->execute([$reference,$id]);
    },static function() {});
    throws(fn()=>$service->refresh((int)$a['id'],true),'Simulated crash');
    $service->refresh((int)$a['id'],true);
    ok((int)$db->query('SELECT COUNT(*) FROM test_ledger')->fetchColumn()===1); ok((int)$store->get((int)$a['id'])['credited']===1);
};

$passed=0;
foreach ($tests as $name=>$test) {
    try { $test(); echo 'PASS '.$name."\n"; $passed++; }
    catch (Throwable $e) { fwrite(STDERR,'FAIL '.$name.': '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine()."\n"); exit(1); }
}
echo $passed." tests passed.\n";
