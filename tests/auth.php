<?php
declare(strict_types=1);
namespace WHMCS\Authentication {
    final class CurrentUser {
        public static $client=null; public static $user=null;
        public function client() { return self::$client; }
        public function user() { return self::$user; }
    }
}
namespace {
    define('WHMCS',true);
    require_once __DIR__.'/../modules/gateways/pesepaydirect/lib/bootstrap.php';
    require_once __DIR__.'/../modules/gateways/pesepaydirect.php';
    function check(bool $value): void { if (!$value) throw new \RuntimeException('Auth assertion failed'); }
    function denied(callable $fn): void { try { $fn(); } catch (\RuntimeException $e) { return; } throw new \RuntimeException('Access should have been denied'); }
    use WHMCS\Authentication\CurrentUser;
    use function Azaire\PesePay\{authorizedClient,assertCsrf};
    denied(fn()=>authorizedClient());
    CurrentUser::$user=(object)['id'=>7];
    CurrentUser::$client=new class { public int $id=42; public function owner() { return (object)['id'=>8]; } };
    denied(fn()=>authorizedClient());
    CurrentUser::$user=(object)['id'=>8]; check(authorizedClient()===42);
    $_SESSION=[]; denied(fn()=>assertCsrf('')); $_SESSION['pesepaydirect_csrf']=str_repeat('a',64);
    assertCsrf(str_repeat('a',64)); denied(fn()=>assertCsrf(str_repeat('b',64)));
    $html=pesepaydirect_link(['systemurl'=>'https://billing.example.test/" onmouseover="x','invoiceid'=>'42','secretKey'=>'DO-NOT-RENDER']);
    check(!str_contains($html,'DO-NOT-RENDER')); check(!str_contains($html,'/" onmouseover="x')); check(str_contains($html,'data-invoice="42"'));
    $fields=pesepaydirect_config(); check(!isset($fields['environment'])); check($fields['testMode']['Type']==='yesno' && $fields['testMode']['Default']==='');
    check($fields['integrationKey']['Type']==='password' && $fields['encryptionKey']['Type']==='password'); check(!function_exists('pesepaydirect_refund'));
    echo "PASS guest rejection, owner authorization, delegated-user rejection, CSRF, output escaping, secret masking and safe gateway defaults\n";
}
