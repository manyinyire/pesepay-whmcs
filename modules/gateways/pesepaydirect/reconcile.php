<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once dirname(__DIR__,3).'/init.php';
require_once dirname(__DIR__,3).'/includes/gatewayfunctions.php';
require_once dirname(__DIR__,3).'/includes/invoicefunctions.php';
require_once __DIR__.'/lib/bootstrap.php';
use function Azaire\PesePay\{store,config,service,ensureInstalled};
$lock=fopen(sys_get_temp_dir().'/pesepaydirect-'.substr(hash('sha256',__DIR__),0,16).'.lock','c');
if (!$lock || !flock($lock,LOCK_EX | LOCK_NB)) { exit(0); }
$started=time();
$store=store(); ensureInstalled($store);
if (in_array('--install',$argv,true)) { echo "PesePay Direct schema ready.\n"; exit; }
if (in_array('--activate',$argv,true)) {
    // WHMCS 9 may not list custom gateways on Setup > Payment Gateways; this activates it the same way that screen would.
    $gateway=new \WHMCS\Module\Gateway(); $gateway->load('pesepaydirect');
    if ($gateway->isActiveGateway('pesepaydirect')) { echo "PesePay Direct is already active.\n"; exit; }
    $gateway->activate();
    echo "PesePay Direct activated. Enter its keys under Setup > Payment Gateways > Manage Existing Gateways.\n"; exit;
}
$errors=0;
// Only the current mode's attempts can be verified with the keys entered.
foreach ($store->due(config()['environment']) as $a) {
    if (time()-$started>50) { break; }
    try {
        $result=service($store,config($a['environment']))->refresh((int)$a['id'],true);
        echo 'Attempt '.(int)$a['id'].': '.$result['status'].((int)$result['credited']?' verified':'')."\n";
    } catch (\Throwable $e) {
        $errors++;
        logTransaction('pesepaydirect',['attempt_id'=>(int)$a['id'],'event'=>'Reconciliation deferred; check credentials and invoice settings','reason'=>\Azaire\PesePay\Service::reason($e)],'Reconciliation deferred');
        fwrite(STDERR,'Attempt '.(int)$a['id']." deferred.\n");
    }
}
exit($errors?1:0);
