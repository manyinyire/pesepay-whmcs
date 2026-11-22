<?php
declare(strict_types=1);
namespace Azaire\PesePay;

final class Policy
{
    public const FAILURES = ['FAILED','TERMINATED','TIME_OUT','CLOSED','CLOSED_PERIOD_ELAPSED','INSUFFICIENT_FUNDS','CANCELLED','ERROR','DECLINED','AUTHORIZATION_FAILED','SERVICE_UNAVAILABLE','REVERSED','REJECTED','CLEARED'];
    // REJECTED: PesePay refused the request (4xx). CLEARED: an admin confirmed in the merchant panel that nothing was charged.
    public const RETRYABLE = ['TERMINATED','CLOSED','CLOSED_PERIOD_ELAPSED','INSUFFICIENT_FUNDS','CANCELLED','DECLINED','AUTHORIZATION_FAILED','REVERSED','REJECTED','CLEARED'];
    public const METHODS = [
        'PZW211' => ['name'=>'EcoCash','currency'=>'USD','phone'=>'ecocash'],
        'PZW201' => ['name'=>'EcoCash','currency'=>'ZiG','phone'=>'ecocash'],
        'PZW212' => ['name'=>'InnBucks','currency'=>'USD','phone'=>''],
        'PZW216' => ['name'=>'O’mari','currency'=>'USD','phone'=>'omari'],
        'PZW217' => ['name'=>'O’mari','currency'=>'ZWG','phone'=>'omari'],
        'PZW210' => ['name'=>'PayGo','currency'=>'ZiG','phone'=>''],
    ];
    public static function cents($value): int
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) { throw new \InvalidArgumentException('Invalid payment amount.'); }
        $text = (string) $value;
        if (!preg_match('/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2})0*)?$/D', $text, $m)) { throw new \InvalidArgumentException('Invalid payment amount.'); }
        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }
    public static function decimal(int $cents): string { return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT); }
    public static function reference(string $reference): void
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $reference)) { throw new \InvalidArgumentException('Invalid transaction reference.'); }
    }
    public static function fields(string $method, string $phone): object
    {
        $kind = self::METHODS[$method]['phone'] ?? null;
        if ($kind === null) { throw new \InvalidArgumentException('Unsupported payment method.'); }
        if ($kind === '') { return (object) []; }
        $phone = preg_replace('/[\s()+-]/', '', $phone);
        if ($kind === 'omari' && preg_match('/^07[0-9]{8}$/D', $phone)) { $phone = '263' . substr($phone, 1); }
        $regex = $kind === 'omari' ? '/^2637[0-9]{8}$/D' : '/^(?:0|263)7[78][0-9]{7}$/D';
        if (!preg_match($regex, $phone)) { throw new \InvalidArgumentException('Enter a valid ' . self::METHODS[$method]['name'] . ' mobile number.'); }
        return (object) ['customerPhoneNumber' => $phone];
    }
    public static function currencyMatches(string $invoice, string $provider): bool
    {
        return $invoice === $provider || (in_array(strtoupper($invoice), ['ZIG','ZWG'], true) && in_array($provider, ['ZiG','ZWG'], true));
    }
    public static function verify(array $attempt, array $result, string $reference): string
    {
        self::reference($reference);
        if (($result['referenceNumber'] ?? null) !== $reference || ($attempt['reference'] && $attempt['reference'] !== $reference)) { throw new \RuntimeException('Transaction reference mismatch.'); }
        if (($result['resultUrl'] ?? null) !== $attempt['callback_url']) { throw new \RuntimeException('Payment does not belong to this attempt.'); }
        if (self::cents($result['amountDetails']['amount'] ?? null) !== (int) $attempt['amount_cents'] || ($result['amountDetails']['currencyCode'] ?? null) !== $attempt['api_currency']) { throw new \RuntimeException('Payment amount or currency mismatch.'); }
        $status = $result['transactionStatus'] ?? null;
        if (!is_string($status) || !preg_match('/^[A-Z_]{1,40}$/D', $status)) { throw new \RuntimeException('Invalid payment status.'); }
        return $status;
    }
    public static function metadata(array $result): array
    {
        $output = [];
        foreach (['qrCode','qrCodeUrl','otpReference'] as $key) {
            $value = $result['transactionMetadata'][$key] ?? null;
            if (is_string($value) && strlen($value) <= 4096) { $output[$key] = $value; }
        }
        return $output;
    }
}
