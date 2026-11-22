<?php
declare(strict_types=1);
namespace Azaire\PesePay;
if (!defined('WHMCS')) { http_response_code(403); exit; }
require_once __DIR__.'/Policy.php';
require_once __DIR__.'/Api.php';
require_once __DIR__.'/Store.php';
require_once __DIR__.'/Service.php';
require_once __DIR__.'/Settings.php';

// Test mode uses PesePay's sandbox. Attempts are stamped environment=sandbox|production; the keys
// entered belong to the current mode, so an attempt from the other mode cannot be verified with them.
function config(?string $environment=null): array
{
    $p=\getGatewayVariables('pesepaydirect');
    if (empty($p['type'])) { throw new \RuntimeException('PesePay Direct is not activated.'); }
    $p['environment']=empty($p['testMode']) ? 'production' : 'sandbox';
    if ($environment !== null && $environment !== $p['environment']) { throw new \RuntimeException('This payment was made in '.($environment==='sandbox'?'test':'live').' mode. Switch PesePay Direct back to that mode to check it.'); }
    $p['integrationKey']=(string)($p['integrationKey'] ?? '');
    $p['encryptionKey']=(string)($p['encryptionKey'] ?? '');
    $p['systemurl']=rtrim((string)($p['systemurl'] ?? ''),'/').'/';
    $url=parse_url($p['systemurl']);
    if (($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['query']) || isset($url['fragment'])) { throw new \RuntimeException('WHMCS System URL must use HTTPS.'); }
    return $p;
}
function store(): Store { return new Store(\WHMCS\Database\Capsule::connection()->getPdo()); }
function ensureInstalled(Store $store): void
{
    try { \WHMCS\Database\Capsule::connection()->select('SELECT id FROM '.Store::TABLE.' LIMIT 0'); }
    catch (\Exception $e) {
        // Schema builder checks existence before installing; other DB failures must propagate.
        if (\WHMCS\Database\Capsule::schema()->hasTable(Store::TABLE)) { throw $e; }
        $store->install();
    }
}
function invoice(int $id): array
{
    $r=\localAPI('GetInvoice',['invoiceid'=>$id]);
    if (($r['result'] ?? '') !== 'success') { throw new \RuntimeException('Invoice not found.'); }
    $client=\WHMCS\Database\Capsule::table('tblclients')->join('tblcurrencies','tblclients.currency','=','tblcurrencies.id')->where('tblclients.id',(int)$r['userid'])->first(['tblcurrencies.code','tblclients.email','tblclients.firstname','tblclients.lastname','tblclients.companyname']);
    if (!$client || !$client->code) { throw new \RuntimeException('Invoice currency not found.'); }
    $name=trim($client->firstname.' '.$client->lastname) ?: (string)$client->companyname;
    return ['client_id'=>(int)$r['userid'],'currency'=>(string)$client->code,'balance'=>$r['balance'],'status'=>$r['status'],'gateway'=>$r['paymentmethod'],
        'email'=>(string)$client->email,'name'=>mb_substr($name,0,100)];
}
function service(Store $store, array $config): Service
{
    return new Service($store,new Api($config['integrationKey'],$config['encryptionKey'],null,$config['environment']==='sandbox'),$config,
        __NAMESPACE__.'\\invoice',
        static function (int $invoiceId,string $transactionId,string $amount): void {
            // Called under the invoice advisory lock; retries recover a ledger write made before a crash.
            $existing=\WHMCS\Database\Capsule::table('tblaccounts')->where('transid',$transactionId)->first();
            if ($existing) {
                if ((int)$existing->invoiceid !== $invoiceId || $existing->gateway !== 'pesepaydirect' || Policy::cents($existing->amountin) !== Policy::cents($amount)) { throw new \RuntimeException('Existing transaction conflicts with this payment.'); }
                return;
            }
            \addInvoicePayment($invoiceId,$transactionId,$amount,'0.00','pesepaydirect');
            $saved=\WHMCS\Database\Capsule::table('tblaccounts')->where('transid',$transactionId)->where('invoiceid',$invoiceId)->exists();
            if (!$saved) { throw new \RuntimeException('WHMCS did not record the payment.'); }
        },
        static function (string $event,int $attempt,string $reason=''): void {
            \logTransaction('pesepaydirect',array_filter(['event'=>$event,'attempt_id'=>$attempt,'reason'=>$reason]),$event);
        });
}
function authorizedClient(): int
{
    $current=new \WHMCS\Authentication\CurrentUser();
    $client=$current->client();
    if (!$client || !$current->user()) { throw new \RuntimeException('Sign in to pay this invoice.'); }
    // The public permission API differs between WHMCS releases. Fail closed for delegated users
    // until their permission adapter is validated against the deployed WHMCS version.
    $owner=$client->owner();
    if (!$owner || (int)$owner->id !== (int)$current->user()->id) { throw new \RuntimeException('Please ask the account owner to complete this payment.'); }
    return (int)$client->id;
}
function assertCsrf(string $provided): void
{
    $expected=$_SESSION['pesepaydirect_csrf'] ?? '';
    if (!is_string($expected) || strlen($expected)!==64 || !hash_equals($expected,$provided)) { throw new \RuntimeException('Checkout session expired. Reload the invoice.'); }
}
function jsonResponse(array $body,int $status=200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private'); header('X-Content-Type-Options: nosniff');
    echo json_encode($body,JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP); exit;
}
