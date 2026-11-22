<?php
declare(strict_types=1);
namespace WHMCS\Exception\Module { class InvalidConfiguration extends \Exception {} }
namespace {
use Azaire\PesePay\{Policy,Settings,Store};
use Azaire\PesePay\Admin\{Page,Reports};
define('WHMCS',true);
foreach (['Policy','Store','Settings'] as $class) { require_once __DIR__.'/../modules/gateways/pesepaydirect/lib/'.$class.'.php'; }
require_once __DIR__.'/../modules/gateways/pesepaydirect.php';
require_once __DIR__.'/../modules/addons/pesepayreports/lib/Reports.php';
require_once __DIR__.'/../modules/addons/pesepayreports/lib/Page.php';
set_error_handler(static function ($severity,$message,$file,$line) { throw new ErrorException($message,0,$severity,$file,$line); });
$activity=[];
function logActivity(string $m): void { global $activity; $activity[]=$m; }
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
function seed(): Reports {
    $pdo=connection(); $store=new Store($pdo); $store->install(); $pdo->exec('TRUNCATE TABLE '.Store::TABLE);
    $now=time(); $n=0;
    $add=static function(array $over) use ($store,$now,&$n) {
        $n++;
        $store->insert($over+['token'=>'t'.$n,'request_key'=>'r'.$n,'invoice_id'=>100+$n,'client_id'=>7,'amount_cents'=>1000,'invoice_currency'=>'USD','api_currency'=>'USD','method'=>'PZW211','environment'=>'production','reference'=>'REF'.$n,'callback_url'=>'https://x.test/cb','status'=>'PENDING','metadata'=>'{}','credited'=>0,'created_at'=>$now-60]);
    };
    $add(['status'=>'SUCCESS','credited'=>1,'amount_cents'=>1250]);
    $add(['status'=>'SUCCESS','credited'=>1,'amount_cents'=>500,'created_at'=>$now-86400]);
    $add(['status'=>'DECLINED']);
    $add(['status'=>'PENDING']);
    $add(['status'=>'PENDING','created_at'=>$now-7200]);            // stale pending -> attention
    $add(['status'=>'UNKNOWN','reference'=>null]);                   // no reference -> attention
    $add(['status'=>'SUCCESS','credited'=>1,'invoice_currency'=>'ZWG','api_currency'=>'ZiG','method'=>'PZW201','amount_cents'=>9900]);
    $add(['status'=>'SUCCESS','credited'=>1,'environment'=>'sandbox','amount_cents'=>777]);   // test-mode row
    $add(['status'=>'PENDING','reference'=>'=HYPERLINK("http://evil")<script>']);
    return new Reports($pdo);
}
function render(array $get,array $vars=[],array $post=[],array $gateway=[]): string {
    $_GET=$get; $_POST=$post; $_SERVER['REQUEST_METHOD']=$post?'POST':'GET';
    ob_start(); Page::render(seed(),$vars+['modulelink'=>'addonmodules.php?module=pesepayreports'],fn()=>$gateway); return (string)ob_get_clean();
}
$_SESSION=['adminid'=>3];
$tests=[];
$tests['filters validate input and default to live payments, last 30 days']=function() {
    $f=Reports::filters([]); ok($f['to']===gmdate('Y-m-d')); ok($f['mode']==='live');
    foreach ([['mode'=>'sandbox'],['outcome'=>'x'],['method'=>'PZW1'],['currency'=>'US$'],['invoice'=>'0'],['from'=>'2026-02-30'],['from'=>'2026-05-01','to'=>'2026-04-01'],['from'=>'2024-01-01','to'=>'2026-01-01'],['reference'=>[]]] as $bad) throws(fn()=>Reports::filters($bad));
};
$tests['outcomes separate success, failed, pending and attention; test payments kept apart']=function() {
    $r=seed(); $f=fn($o)=>Reports::filters(['outcome'=>$o]);
    ok($r->page($f(''),1)['total']===8,'all');
    ok($r->page($f('success'),1)['total']===3,'success');
    ok($r->page($f('failed'),1)['total']===1,'failed');
    ok($r->page($f('pending'),1)['total']===3,'pending');
    ok($r->page($f('attention'),1)['total']===2,'attention');
    ok($r->page(Reports::filters(['invoice'=>'103']),1)['rows'][0]['status']==='DECLINED','invoice filter');
    ok($r->page(Reports::filters(['mode'=>'test']),1)['total']===1,'test mode isolated');
    ok((int)$r->summary(Reports::filters(['mode'=>'test']))[0]['collected_cents']===777);
};
$tests['summary totals are per currency']=function() {
    $rows=array_column(seed()->summary(Reports::filters([])),null,'currency');
    ok((int)$rows['USD']['collected_cents']===1750); ok((int)$rows['USD']['successes']===2); ok((int)$rows['USD']['failures']===1);
    ok((int)$rows['ZWG']['collected_cents']===9900); ok(count($rows)===2);
};
$tests['wallet bars are scaled to the largest wallet']=function() {
    $html=render(['view'=>'reports']);
    preg_match_all('/class="ppr-hbar" style="width:([0-9.]+)%"/',$html,$m);
    ok($m[1]===['100','14.3'],'got '.json_encode($m[1]));
};
$tests['daily and breakdown group correctly']=function() {
    $r=seed(); $days=$r->daily(Reports::filters(['currency'=>'USD']));
    ok(count($days)===2); ok(array_sum(array_map(fn($d)=>(int)$d['collected_cents'],$days))===1750);
    $b=$r->breakdown(Reports::filters([])); ok(array_sum(array_column($b,'attempts'))==8);
};
$tests['reconciliation health counts unresolved attempts']=function() {
    $h=array_column(seed()->health(),null,'environment');
    ok((int)$h['production']['unresolved']===4); ok((int)$h['production']['missing_reference']===1); ok(!isset($h['sandbox']));
};
$tests['CSV export neutralizes formulas and omits secrets']=function() {
    $csv=seed()->csv(Reports::filters([]));
    ok(str_contains($csv,"'=HYPERLINK")); ok(!str_contains($csv,"\n=")); ok(!str_contains($csv,'metadata')); ok(str_contains($csv,'12.50')); ok(!str_contains($csv,'7.77'),'test row in live export');
    ok(Reports::csvCell(' =1')==="' =1"); ok(Reports::csvCell('@x')==="'@x"); ok(Reports::csvCell('REF1')==='REF1');
};
$tests['transactions page escapes provider data; test view is labelled']=function() {
    $html=render([]);
    ok(!str_contains($html,'evil")<script>'),'unescaped'); ok(str_contains($html,'&lt;script&gt;')); ok(str_contains($html,'8 matching attempts'));
    ok(str_contains($html,'configgateways.php'),'link to key settings'); ok(!str_contains($html,'TEST payments'));
    ok(str_contains(render(['mode'=>'test']),'TEST payments — no real money'));
};
$tests['export and status-check require valid token and enabled option']=function() {
    global $activity; $activity=[];
    ok(str_contains(render([],['allowExport'=>'on'],['action'=>'export','ppr_token'=>'bad']),'Session expired'));
    $token=$_SESSION['pesepayreports_csrf'][3];
    ok(str_contains(render([],['allowExport'=>'on'],['action'=>'export','ppr_token'=>$token]),'data:text/csv'));
    ok(str_contains(render([],['allowExport'=>''],['action'=>'export','ppr_token'=>$token]),'disabled'));
    ok(str_contains(render([],[],['action'=>'check','attempt'=>'1','ppr_token'=>$token]),'disabled'));
    ok(count($activity)===1);
};
$tests['attempt detail shows the mode and offers clearing only without a reference']=function() {
    ok(str_contains(render(['attempt'=>'1']),'Attempt #1')); ok(!str_contains(render(['attempt'=>'1']),'Clear attempt'));
    ok(str_contains(render(['attempt'=>'8']),'Test (no real money)')); ok(str_contains(render(['attempt'=>'99']),'not found'));
    ok(str_contains(render(['attempt'=>'6']),'Clear attempt and allow a new payment'));
};
$tests['clearing an unacknowledged attempt needs confirmation and is logged']=function() {
    global $activity; $activity=[]; render([]); $token=$_SESSION['pesepayreports_csrf'][3];
    ok(str_contains(render(['attempt'=>'6'],[],['action'=>'clear','attempt'=>'6','ppr_token'=>$token]),'Confirm that you checked'));
    $html=render(['attempt'=>'6'],[],['action'=>'clear','attempt'=>'4','confirm'=>'yes','ppr_token'=>$token]);
    ok(str_contains($html,'cannot be cleared'),'attempt with reference');
    $_GET=['attempt'=>'6']; $_POST=['action'=>'clear','attempt'=>'6','confirm'=>'yes','ppr_token'=>$token]; $_SERVER['REQUEST_METHOD']='POST';
    $r=seed(); ob_start(); Page::render($r,['modulelink'=>'x'],fn()=>[]); $html=(string)ob_get_clean();
    ok(str_contains($html,'Attempt #6 cleared')); ok($r->detail(6)['status']==='CLEARED'); ok(in_array('CLEARED',Policy::RETRYABLE,true));
    ok(count($activity)===1 && str_contains($activity[0],'cleared'));
};
$key='0123456789abcdef0123456789abcdef';
$tests['settings view reports key status without revealing keys']=function() use ($key) {
    $html=render(['view'=>'settings'],[],[],['type'=>'Invoices','integrationKey'=>'int-SECRET-1','encryptionKey'=>$key,'enable_PZW211'=>'on']);
    ok(!str_contains($html,'int-SECRET-1') && !str_contains($html,$key),'key leaked');
    ok(str_contains($html,'EcoCash USD')); ok(!str_contains($html,'missing or invalid')); ok(!str_contains($html,'Test mode is ON'));
    ok(str_contains(render(['view'=>'settings'],[],[],['type'=>'Invoices','testMode'=>'on','integrationKey'=>'abc','encryptionKey'=>$key]),'Test mode is ON'));
    $html=render(['view'=>'settings'],[],[],['type'=>'Invoices','integrationKey'=>'abc','encryptionKey'=>'short']);
    ok(str_contains($html,'Must be exactly 32 characters (currently 5)') && str_contains($html,'missing or invalid') && !str_contains($html,'short<'));
    ok(str_contains(render(['view'=>'settings']),'not activated'));
};
$tests['saving gateway settings rejects invalid keys for admins']=function() use ($key) {
    ok(Settings::validate(['integrationKey'=>'abc','encryptionKey'=>$key])===[]);
    ok(Settings::validate(['integrationKey'=>'abc','encryptionKey'=>str_repeat('*',8)])===[],'masked unchanged value accepted');
    ok(count(Settings::validate([]))===2,'both keys required');
    ok(count(Settings::validate(['integrationKey'=>"a b",'encryptionKey'=>$key]))===1);
    throws(fn()=>pesepaydirect_config_validate(['integrationKey'=>'abc','encryptionKey'=>'short']),'exactly 32');
    pesepaydirect_config_validate(['integrationKey'=>'abc','encryptionKey'=>$key]);
};
$passed=0;
foreach ($tests as $name=>$test) {
    try { $test(); echo 'PASS '.$name."\n"; $passed++; }
    catch (Throwable $e) { fwrite(STDERR,'FAIL '.$name.': '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine()."\n"); exit(1); }
}
echo $passed." tests passed.\n";
}
