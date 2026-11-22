<?php
declare(strict_types=1);
namespace Azaire\PesePay;

final class Api
{
    private string $base;
    private string $key;
    private string $encryptionKey;
    private $transport;
    private const BASE = 'https://api.pesepay.com/api/payments-engine';
    private const SANDBOX = 'https://api.test.sandbox.pesepay.com/payments-engine';
    public function __construct(string $key, string $encryptionKey, ?callable $transport = null, bool $sandbox = false)
    {
        if ($key === '' || preg_match('/[\r\n]/', $key) || strlen($encryptionKey) !== 32) {
            throw new \RuntimeException('PesePay credentials are missing or invalid.');
        }
        $this->key = $key;
        $this->encryptionKey = $encryptionKey;
        $this->base = $sandbox ? self::SANDBOX : self::BASE;
        $this->transport = $transport;
    }
    public function encrypt(array $body): string
    {
        $raw = openssl_encrypt(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'aes-256-cbc', $this->encryptionKey, OPENSSL_RAW_DATA, substr($this->encryptionKey, 0, 16));
        if ($raw === false) { throw new \RuntimeException('Encryption failed.'); }
        return base64_encode($raw);
    }
    public function decrypt(string $payload): array
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || $raw === '' || strlen($raw) % 16 !== 0) { throw new \RuntimeException('Invalid encrypted response.'); }
        $plain = openssl_decrypt($raw, 'aes-256-cbc', $this->encryptionKey, OPENSSL_RAW_DATA, substr($this->encryptionKey, 0, 16));
        if ($plain === false) { throw new \RuntimeException('Cannot decrypt PesePay response.'); }
        $body = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($body)) { throw new \RuntimeException('Invalid PesePay response.'); }
        return $body;
    }
    public function makePayment(array $body): array
    {
        return $this->encrypted('POST', $this->base . '/v2/payments/make-payment', ['payload' => $this->encrypt($body)]);
    }
    public function check(string $reference): array
    {
        Policy::reference($reference);
        // Never fetch a poll URL supplied by a browser, callback, or provider response.
        return $this->encrypted('GET', $this->base . '/v1/payments/check-payment?referenceNumber=' . rawurlencode($reference));
    }
    public function methods(string $currency): array
    {
        // The catalogue is documented as a production endpoint, even for sandbox integrations.
        return $this->request('GET', self::BASE . '/v1/payment-methods/for-currency?currencyCode=' . rawurlencode($currency), null, false);
    }
    public function chargeOtp(string $reference, string $otp): array
    {
        if ($this->base === self::SANDBOX) { throw new \RuntimeException('O’mari is not available in test mode.'); }
        Policy::reference($reference);
        if (!preg_match('/^[0-9]{6}$/D', $otp)) { throw new \InvalidArgumentException('Enter the six-digit SMS code.'); }
        // The O'mari endpoint is intentionally plain JSON and receives no integration key.
        return $this->request('POST', 'https://api.pesepay.com/api/omari/v1/payments/charge', ['referenceNumber' => $reference, 'otp' => $otp], false);
    }
    private function encrypted(string $method, string $url, ?array $body = null): array
    {
        $result = $this->request($method, $url, $body, true);
        if (!isset($result['payload']) || !is_string($result['payload'])) { throw new \RuntimeException('Missing encrypted response.'); }
        return $this->decrypt($result['payload']);
    }
    private function request(string $method, string $url, ?array $body, bool $authenticated): array
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        // PesePay's docs name the Authorization header; its official PHP SDK sends "key". Send both.
        if ($authenticated) { $headers[] = 'Authorization: ' . $this->key; $headers[] = 'key: ' . $this->key; }
        $json = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR);
        if ($this->transport) {
            [$status, $raw] = ($this->transport)($method, $url, $headers, $json);
        } else {
            $ch = curl_init($url);
            $raw = '';
            curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 25,
                CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$raw): int {
                    if (strlen($raw) + strlen($chunk) > 1048576) { return 0; }
                    $raw .= $chunk; return strlen($chunk);
                }]);
            if ($json !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $json); }
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($ok === false) { throw new \RuntimeException('PesePay connection failed; the payment outcome is unknown.'); }
        }
        if ($status < 200 || $status >= 300) {
            // Never automatically retry a debit. Only PesePay's sanitized error message is kept, for the gateway log.
            throw new ApiError((int) $status, self::errorMessage((string) $raw));
        }
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) { throw new \RuntimeException('Invalid PesePay response.'); }
        return $decoded;
    }
    public static function errorMessage(string $raw): string
    {
        $body = json_decode($raw, true);
        $text = '';
        if (is_array($body)) {
            foreach (['message','error','errorMessage','transactionStatusDescription','description'] as $field) {
                if (isset($body[$field]) && is_scalar($body[$field])) { $text = (string) $body[$field]; break; }
            }
            if ($text === '' && isset($body['errors'])) { $text = json_encode($body['errors']) ?: ''; }
        }
        if ($text === '') { $text = 'No error message returned'; }
        // Mask phone numbers and other long digit runs; strip control characters.
        $text = preg_replace('/\d{6,}/', '[number]', $text);
        $text = preg_replace('/[\x00-\x1f\x7f]+/', ' ', $text);
        return mb_substr(trim($text), 0, 300);
    }
}

final class ApiError extends \RuntimeException
{
    public int $status;
    public function __construct(int $status, string $detail)
    {
        $this->status = $status;
        parent::__construct('PesePay request failed (HTTP ' . $status . '): ' . $detail);
    }
    // 4xx means PesePay rejected the request outright, so no payment was created.
    public function rejected(): bool { return $this->status >= 400 && $this->status < 500 && $this->status !== 408 && $this->status !== 429; }
}
