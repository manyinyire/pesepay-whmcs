<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/init.php';
require_once dirname(__DIR__,3).'/includes/gatewayfunctions.php';
require_once dirname(__DIR__,3).'/includes/invoicefunctions.php';
require_once __DIR__.'/lib/bootstrap.php';
use Azaire\PesePay\{Policy,Service};
use function Azaire\PesePay\{authorizedClient,assertCsrf,config,store,service,invoice,ensureInstalled,jsonResponse};
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { jsonResponse(['error'=>'POST required.'],405); }
    $clientId=authorizedClient();
    assertCsrf(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '');
    $invoiceId=filter_var($_POST['invoice'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$invoiceId) { jsonResponse(['error'=>'Invalid invoice.'],400); }
    $invoice=invoice($invoiceId);
    if ($invoice['client_id'] !== $clientId) { jsonResponse(['error'=>'Invoice access denied.'],403); }
    $action=$_POST['action'] ?? '';
    $cfg=config(); $store=store(); ensureInstalled($store);
    // Release PHP's session lock before network requests; callback/cron uses DB locks.
    session_write_close();
    if ($action==='load') {
        $last=$store->latest($invoiceId,$cfg['environment']);
        $available=[];
        if ($invoice['status']==='Unpaid' && $invoice['gateway']==='pesepaydirect' && Policy::cents($invoice['balance'])>0) {
            $available=service($store,$cfg)->methods($invoice['currency'],Policy::cents($invoice['balance']));
        }
        jsonResponse(['methods'=>$available,'amount'=>$invoice['balance'],'currency'=>$invoice['currency'],'test'=>$cfg['environment']==='sandbox','attempt'=>$last?Service::view($last):null]);
    }
    if ($action==='start') {
        $a=service($store,$cfg)->start($invoiceId,$clientId,(string)($_POST['requestKey']??''),(string)($_POST['method']??''),(string)($_POST['phone']??''));
    } elseif (in_array($action,['status','otp'],true)) {
        $a=$store->get((int)($_POST['attempt']??0));
        if (!$a || (int)$a['invoice_id']!==$invoiceId || (int)$a['client_id']!==$clientId) { jsonResponse(['error'=>'Payment access denied.'],403); }
        $worker=service($store,config($a['environment']));
        $a=$action==='otp' ? $worker->otp((int)$a['id'],(string)($_POST['otp']??'')) : $worker->refresh((int)$a['id']);
    } else { jsonResponse(['error'=>'Unknown action.'],400); }
    jsonResponse(['attempt'=>Service::view($a)]);
} catch (\InvalidArgumentException $e) {
    jsonResponse(['error'=>$e->getMessage()],422);
} catch (\Throwable $e) {
    // Only module-owned messages are shown; never leak DB errors, file paths, or provider payloads.
    $safe=$e instanceof \RuntimeException && !$e instanceof \PDOException ? $e->getMessage() : 'Payment could not be checked. Please contact support with your invoice number.';
    jsonResponse(['error'=>$safe],503);
}
