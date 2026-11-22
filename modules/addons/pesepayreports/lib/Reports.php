<?php
declare(strict_types=1);
namespace Azaire\PesePay\Admin;

final class Reports
{
    private \PDO $pdo;
    private const TABLE='mod_pesepaydirect_attempts';
    public const FAILURES=['FAILED','TERMINATED','CLOSED','CLOSED_PERIOD_ELAPSED','INSUFFICIENT_FUNDS','CANCELLED','DECLINED','AUTHORIZATION_FAILED','REVERSED','REJECTED','CLEARED'];
    public const ATTENTION=['UNKNOWN','STARTING','FAILED','TIME_OUT','ERROR','SERVICE_UNAVAILABLE','SUCCESS'];
    public function __construct(\PDO $pdo) { $this->pdo=$pdo; }
    public static function filters(array $input): array
    {
        $text=static function(string $key,string $default='') use ($input): string {
            if (!isset($input[$key])) return $default;
            if (!is_string($input[$key]) || strlen($input[$key])>128) throw new \InvalidArgumentException('Invalid filter.');
            return trim($input[$key]);
        };
        $from=$text('from',gmdate('Y-m-d',time()-29*86400)); $to=$text('to',gmdate('Y-m-d'));
        $utc=new \DateTimeZone('UTC');
        $start=\DateTimeImmutable::createFromFormat('!Y-m-d',$from,$utc);
        $end=\DateTimeImmutable::createFromFormat('!Y-m-d',$to,$utc);
        if (!$start || !$end || $start->format('Y-m-d')!==$from || $end->format('Y-m-d')!==$to || $start>$end || $end->getTimestamp()-$start->getTimestamp()>366*86400) throw new \InvalidArgumentException('Choose a valid date range of up to 367 days. Dates use UTC.');
        $mode=$text('mode','live'); $outcome=$text('outcome'); $method=$text('method'); $currency=$text('currency');
        if (!in_array($mode,['live','test'],true)) throw new \InvalidArgumentException('Choose live or test payments.');
        if (!in_array($outcome,['','success','failed','pending','attention'],true)) throw new \InvalidArgumentException('Invalid outcome.');
        if ($method!=='' && !preg_match('/^PZW[0-9]{3}$/D',$method)) throw new \InvalidArgumentException('Invalid method.');
        if ($currency!=='' && !preg_match('/^[A-Za-z]{3}$/D',$currency)) throw new \InvalidArgumentException('Invalid currency.');
        $invoice=$text('invoice');
        if ($invoice!=='' && !preg_match('/^[1-9][0-9]{0,9}$/D',$invoice)) throw new \InvalidArgumentException('Invalid invoice ID.');
        return ['from'=>$from,'to'=>$to,'start'=>$start->getTimestamp(),'end'=>$end->modify('+1 day')->getTimestamp(),'mode'=>$mode,'outcome'=>$outcome,'method'=>$method,'currency'=>$currency,'invoice'=>$invoice,'reference'=>$text('reference')];
    }
    private static function quoted(array $items): string { return "'".implode("','",$items)."'"; }
    private function where(array $f): array
    {
        // Live and test (sandbox) payments are never reported together.
        $sql='created_at>=? AND created_at<? AND environment=?'; $args=[$f['start'],$f['end'],$f['mode']==='test'?'sandbox':'production'];
        foreach (['method'=>'method','currency'=>'invoice_currency','invoice'=>'invoice_id','reference'=>'reference'] as $key=>$column) {
            if ($f[$key]!=='') { $sql.=' AND '.$column.'=?'; $args[]=$f[$key]; }
        }
        $failed=self::quoted(self::FAILURES); $attention=self::quoted(self::ATTENTION);
        if ($f['outcome']==='success') $sql.=' AND credited=1';
        if ($f['outcome']==='failed') $sql.=' AND credited=0 AND status IN ('.$failed.')';
        if ($f['outcome']==='attention') $sql.=' AND credited=0 AND (status IN ('.$attention.') OR (created_at<? AND status NOT IN ('.$failed.')))';
        if ($f['outcome']==='attention') $args[]=time()-1800;
        if ($f['outcome']==='pending') $sql.=' AND credited=0 AND status NOT IN ('.$failed.','.self::quoted(['UNKNOWN','STARTING','TIME_OUT','ERROR','SERVICE_UNAVAILABLE','SUCCESS']).')';
        return [$sql,$args];
    }
    public function page(array $f,int $page): array
    {
        [$where,$args]=$this->where($f);
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM '.self::TABLE.' WHERE '.$where); $q->execute($args); $total=(int)$q->fetchColumn();
        $pages=max(1,(int)ceil($total/50)); $page=max(1,min($page,$pages));
        $q=$this->pdo->prepare('SELECT id,invoice_id,client_id,amount_cents,invoice_currency,api_currency,method,environment,reference,status,credited,needs_check,created_at,checked_at FROM '.self::TABLE.' WHERE '.$where.' ORDER BY id DESC LIMIT 50 OFFSET '.(($page-1)*50));
        $q->execute($args); return ['rows'=>$q->fetchAll(\PDO::FETCH_ASSOC),'total'=>$total,'page'=>$page,'pages'=>$pages];
    }
    public function count(array $f): int
    {
        [$where,$args]=$this->where($f);
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM '.self::TABLE.' WHERE '.$where); $q->execute($args); return (int)$q->fetchColumn();
    }
    public function summary(array $f): array
    {
        [$where,$args]=$this->where($f);
        $q=$this->pdo->prepare('SELECT invoice_currency AS currency,COUNT(*) AS attempts,SUM(CASE WHEN credited=1 THEN 1 ELSE 0 END) AS successes,SUM(CASE WHEN credited=1 THEN amount_cents ELSE 0 END) AS collected_cents,SUM(CASE WHEN credited=0 AND status IN ('.self::quoted(self::FAILURES).') THEN 1 ELSE 0 END) AS failures FROM '.self::TABLE.' WHERE '.$where.' GROUP BY invoice_currency ORDER BY invoice_currency');
        $q->execute($args); return $q->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function daily(array $f): array
    {
        [$where,$args]=$this->where($f);
        // Epoch bucketing is UTC and independent of the database/session time zone.
        $q=$this->pdo->prepare('SELECT FLOOR(created_at/86400) AS day_bucket,invoice_currency AS currency,COUNT(*) AS attempts,SUM(CASE WHEN credited=1 THEN 1 ELSE 0 END) AS successes,SUM(CASE WHEN credited=1 THEN amount_cents ELSE 0 END) AS collected_cents FROM '.self::TABLE.' WHERE '.$where.' GROUP BY FLOOR(created_at/86400),invoice_currency ORDER BY day_bucket DESC,invoice_currency');
        $q->execute($args); return $q->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function breakdown(array $f): array
    {
        [$where,$args]=$this->where($f);
        $q=$this->pdo->prepare('SELECT method,status,invoice_currency AS currency,COUNT(*) AS attempts FROM '.self::TABLE.' WHERE '.$where.' GROUP BY method,status,invoice_currency ORDER BY attempts DESC,method,status');
        $q->execute($args); return $q->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function detail(int $id): ?array
    {
        $q=$this->pdo->prepare('SELECT id,invoice_id,client_id,amount_cents,invoice_currency,api_currency,method,environment,reference,candidate_reference,status,credited,otp_submitted,needs_check,created_at,checked_at FROM '.self::TABLE.' WHERE id=?');
        $q->execute([$id]); return $q->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
    // Only for an attempt PesePay never acknowledged, after an admin confirmed nothing was charged.
    public function clear(int $id): bool
    {
        $q=$this->pdo->prepare("UPDATE ".self::TABLE." SET status='CLEARED',needs_check=0 WHERE id=? AND credited=0 AND reference IS NULL AND candidate_reference IS NULL AND status IN ('UNKNOWN','STARTING')");
        $q->execute([$id]); return $q->rowCount()===1;
    }
    public function health(): array
    {
        $q=$this->pdo->query('SELECT environment,COUNT(*) AS unresolved,SUM(CASE WHEN reference IS NULL AND candidate_reference IS NULL THEN 1 ELSE 0 END) AS missing_reference,MIN(created_at) AS oldest,MAX(checked_at) AS latest_check FROM '.self::TABLE.' WHERE credited=0 AND status NOT IN ('.self::quoted(array_diff(self::FAILURES,['FAILED'])).') GROUP BY environment');
        return $q->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function csv(array $f): string
    {
        [$where,$args]=$this->where($f);
        $q=$this->pdo->prepare('SELECT id,invoice_id,client_id,method,reference,invoice_currency,amount_cents,status,credited,created_at,checked_at FROM '.self::TABLE.' WHERE '.$where.' ORDER BY id DESC LIMIT 5001'); $q->execute($args);
        $rows=$q->fetchAll(\PDO::FETCH_ASSOC);
        if (count($rows)>5000) throw new \RuntimeException('Export limited to 5,000 rows. Narrow the filters.');
        $stream=fopen('php://temp','w+');
        fputcsv($stream,['Attempt','Invoice','Client ID','Method','Reference','Currency','Requested amount','Provider status','Verified / recorded','Created UTC','Last checked UTC'],',','"','');
        foreach ($rows as $row) {
            $row['amount_cents']=\Azaire\PesePay\Policy::decimal((int)$row['amount_cents']);
            $row['credited']=$row['credited'] ? ($f['mode']==='test'?'Recorded (test)':'Recorded') : 'Not recorded';
            $row['created_at']=gmdate('Y-m-d H:i:s',(int)$row['created_at']);
            $row['checked_at']=$row['checked_at'] ? gmdate('Y-m-d H:i:s',(int)$row['checked_at']) : '';
            fputcsv($stream,array_map([self::class,'csvCell'],array_values($row)),',','"','');
        }
        rewind($stream); $csv=stream_get_contents($stream); fclose($stream); return $csv;
    }
    public static function csvCell($value): string
    {
        $value=(string)$value;
        // Neutralize spreadsheet formula prefixes, including those hidden behind whitespace.
        if (preg_match('/^[\x00-\x20]*[=+@-]/',$value) || preg_match('/^[\t\r\n]/',$value)) return "'".$value;
        return $value;
    }
}
