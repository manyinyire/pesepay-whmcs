<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/init.php';
require_once dirname(__DIR__,3).'/includes/gatewayfunctions.php';
require_once __DIR__.'/../pesepaydirect/lib/bootstrap.php';
use Azaire\PesePay\Policy;
use function Azaire\PesePay\{store,config,jsonResponse};
try {
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { jsonResponse(['error'=>'POST required'],405); }
    $token=$_GET['attempt']??'';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{48}$/D',$token)) { jsonResponse(['error'=>'Invalid attempt'],400); }
    $store=store(); $a=$store->token($token);
    if (!$a) { jsonResponse(['accepted'=>true]); }
    $cfg=config($a['environment']);
    $authorization=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??$_SERVER['HTTP_KEY']??'';
    // PesePay documents that callbacks occasionally arrive without the header. A callback only queues a
    // server-side verified status check, so a missing header is accepted; a wrong one is still rejected.
    if ($cfg['integrationKey']==='' || !is_string($authorization) || ($authorization!=='' && !hash_equals($cfg['integrationKey'],$authorization))) { jsonResponse(['error'=>'Unauthorized'],401); }
    $raw=file_get_contents('php://input',false,null,0,65537);
    if (strlen($raw)>65536) { jsonResponse(['error'=>'Payload too large'],413); }
    $data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    $reference=$data['referenceNumber']??'';
    if (!is_string($reference)) { jsonResponse(['error'=>'Invalid reference'],400); }
    Policy::reference($reference);
    // Durable queue before acknowledgment. Do not trust callback status, amounts or pollUrl.
    $store->queue((int)$a['id'],$reference);
    jsonResponse(['accepted'=>true]);
} catch (\InvalidArgumentException | \JsonException $e) { jsonResponse(['error'=>'Invalid callback'],400); }
catch (\Throwable $e) { jsonResponse(['error'=>'Unable to queue callback'],503); }
