<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
use Azaire\PesePay\{Api,Policy};
require_once __DIR__.'/../modules/gateways/pesepaydirect/lib/Policy.php';
require_once __DIR__.'/../modules/gateways/pesepaydirect/lib/Api.php';
$options=getopt('', ['integration-file:','encryption-file:','action:','state:','callback-base:','failure']);
try {
    $key=rtrim(file_get_contents($options['integration-file']),"\r\n");
    $encryption=rtrim(file_get_contents($options['encryption-file']),"\r\n");
    $api=new Api($key,$encryption,null,true);
    $file=$options['state'];
    if ($options['action']==='initiate') {
        if (file_exists($file)) { throw new RuntimeException('State file already exists. Check that attempt before creating another.'); }
        $token=bin2hex(random_bytes(24));
        $callback=rtrim($options['callback-base'],'/').'/modules/gateways/callback/pesepaydirect.php?attempt='.$token;
        $expected=['reference'=>null,'callback_url'=>$callback,'amount_cents'=>100,'api_currency'=>'USD'];
        // Save first. A network error must never lead to a blind retry.
        file_put_contents($file,json_encode(['expected'=>$expected,'outcome'=>'UNKNOWN'],JSON_PRETTY_PRINT)); chmod($file,0600);
        $result=$api->makePayment(['amountDetails'=>['amount'=>1,'currencyCode'=>'USD'],
            'merchantReference'=>'WHMCS-SANDBOX-'.$token,'reasonForPayment'=>'WHMCS sandbox integration test',
            'resultUrl'=>$callback,'returnUrl'=>rtrim($options['callback-base'],'/').'/',
            'paymentMethodCode'=>'PZW211','paymentMethodRequiredFields'=>(object)['customerPhoneNumber'=>isset($options['failure'])?'0770000000':'0777777777']]);
        $reference=$result['referenceNumber']??''; Policy::reference($reference);
        $expected['reference']=$reference;
        file_put_contents($file,json_encode(['expected'=>$expected,'outcome'=>'AWAITING_VERIFICATION'],JSON_PRETTY_PRINT));
    } else {
        $state=json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR);
        $expected=$state['expected'];
        if (!$expected['reference']) { throw new RuntimeException('No reference returned; reconcile in the merchant panel before retrying.'); }
        $result=$api->check($expected['reference']);
    }
    $status=Policy::verify($expected,$result,$expected['reference']);
    $safe=['reference'=>$expected['reference'],'status'=>$status,'amount'=>$result['amountDetails']['amount'],'currency'=>$result['amountDetails']['currencyCode'],'verifiedCallbackUrl'=>true,'action'=>$options['action']];
    file_put_contents($file,json_encode(['expected'=>$expected,'outcome'=>$status,'verification'=>$safe],JSON_PRETTY_PRINT));
    echo json_encode($safe,JSON_PRETTY_PRINT)."\n";
} catch (Throwable $e) { fwrite(STDERR,'Sandbox check: '.$e->getMessage()."\n"); exit(1); }
