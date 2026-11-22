<?php
declare(strict_types=1);
namespace Azaire\PesePay;

// Inspects gateway credentials without ever returning their values.
final class Settings
{
    public static function masked(string $value): bool { return $value !== '' && strspn($value, '*') === strlen($value); }
    public static function keyProblem(string $kind, string $value): ?string
    {
        if ($value === '') return 'Not set';
        if (self::masked($value)) return null; // WHMCS can resubmit an unchanged password field masked.
        if (preg_match('/[\x00-\x20\x7f]/', $value)) return 'Contains spaces or line breaks';
        if ($kind === 'encryption' && strlen($value) !== 32) return 'Must be exactly 32 characters (currently '.strlen($value).')';
        return null;
    }
    public static function status(array $params): array
    {
        $integration = self::keyProblem('integration', (string)($params['integrationKey'] ?? ''));
        $encryption = self::keyProblem('encryption', (string)($params['encryptionKey'] ?? ''));
        $methods = [];
        foreach (Policy::METHODS as $code => $m) { if (!empty($params['enable_'.$code])) $methods[] = $m['name'].' '.$m['currency']; }
        return ['activated'=>!empty($params['type']),'test'=>!empty($params['testMode']),'integration'=>$integration ?? 'Set','encryption'=>$encryption ?? 'Set','ready'=>$integration === null && $encryption === null,'methods'=>$methods];
    }
    // Messages for WHMCS's save-time validation.
    public static function validate(array $params): array
    {
        $errors = [];
        foreach (['integration'=>'integrationKey','encryption'=>'encryptionKey'] as $kind => $field) {
            $problem = self::keyProblem($kind, (string)($params[$field] ?? ''));
            if ($problem !== null) $errors[] = ucfirst($kind).' key: '.$problem.'.';
        }
        return $errors;
    }
}
