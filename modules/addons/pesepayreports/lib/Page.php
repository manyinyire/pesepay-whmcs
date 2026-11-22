<?php
declare(strict_types=1);
namespace Azaire\PesePay\Admin;
use Azaire\PesePay\{Policy,Settings};

final class Page
{
    // Provider statuses that need an administrator's eye (mirrors Reports::ATTENTION, minus verified SUCCESS).
    private const UNCERTAIN=['UNKNOWN','STARTING','TIME_OUT','ERROR','SERVICE_UNAVAILABLE'];
    public static function e($v): string { return htmlspecialchars((string)$v,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8'); }
    private static function date($value): string { return $value ? gmdate('Y-m-d H:i:s',(int)$value).' UTC' : 'Not checked'; }
    private static function short($value): string { return $value ? gmdate('j M Y, H:i',(int)$value) : '—'; }
    public static function render(Reports $reports,array $vars,callable $gateway): void
    {
        $admin=(int)$_SESSION['adminid'];
        if (empty($_SESSION['pesepayreports_csrf'][$admin])) $_SESSION['pesepayreports_csrf'][$admin]=bin2hex(random_bytes(32));
        $csrf=$_SESSION['pesepayreports_csrf'][$admin];
        echo self::styles();
        try { $f=Reports::filters($_GET); }
        catch (\InvalidArgumentException $e) { echo '<div class="ppr"><div class="ppr-alert ppr-alert-critical">'.self::icon('critical').self::e($e->getMessage()).'</div></div>'; return; }
        $base=['module'=>'pesepayreports','from'=>$f['from'],'to'=>$f['to'],'mode'=>$f['mode'],'outcome'=>$f['outcome'],'method'=>$f['method'],'currency'=>$f['currency'],'invoice'=>$f['invoice'],'reference'=>$f['reference']];
        $url=static fn(array $extra=[])=>'addonmodules.php?'.http_build_query(array_merge($base,$extra));
        $view=is_string($_GET['view']??null) && in_array($_GET['view'],['transactions','reports','health','settings'],true) ? $_GET['view'] : 'transactions';
        $tab=$view==='transactions' && $f['outcome']==='attention' ? 'attention' : $view;

        echo '<div class="ppr">';
        echo '<header class="ppr-head"><div><h2>PesePay transactions &amp; reports</h2><p class="ppr-sub">Payments made through PesePay Direct. Dates are UTC. Nothing on this page changes an invoice.</p></div>';
        if ($view!=='settings' && $view!=='health') {
            echo '<div class="ppr-seg" role="group" aria-label="Payment mode">';
            foreach (['live'=>'Live','test'=>'Test'] as $m=>$label) echo '<a href="'.self::e($url(['view'=>$view,'mode'=>$m,'page'=>1])).'"'.($f['mode']===$m?' class="is-on" aria-current="true"':'').'>'.$label.'</a>';
            echo '</div>';
        }
        echo '</header>';
        echo '<nav class="ppr-tabs">';
        foreach (['transactions'=>['Transactions',['view'=>'transactions','outcome'=>'']],'reports'=>['Reports',['view'=>'reports']],'attention'=>['Needs attention',['view'=>'transactions','outcome'=>'attention']],'health'=>['Reconciliation',['view'=>'health']],'settings'=>['Gateway settings',['view'=>'settings']]] as $key=>[$label,$params]) {
            echo '<a href="'.self::e($url($params)).'"'.($tab===$key?' class="is-on" aria-current="page"':'').'>'.$label.'</a>';
        }
        echo '</nav>';
        if ($f['mode']==='test' && $view!=='settings' && $view!=='health') echo '<div class="ppr-alert ppr-alert-warning">'.self::icon('warning').'<span><strong>TEST payments — no real money.</strong> These were made in Test mode against PesePay\'s sandbox and are never included in live totals.</span></div>';

        if ($view==='settings') { self::settings(Settings::status($gateway())); echo self::keysNote().'</div>'; return; }
        if (($_SERVER['REQUEST_METHOD']??'')==='POST') {
            $provided=$_POST['ppr_token']??'';
            if (!is_string($provided) || !hash_equals($csrf,$provided)) { echo '<div class="ppr-alert ppr-alert-critical">'.self::icon('critical').'Session expired. Reload this page.</div></div>'; return; }
            try {
                if (($_POST['action']??'')==='export' && !empty($vars['allowExport'])) {
                    $csv=$reports->csv($f);
                    echo '<div class="ppr-alert ppr-alert-good">'.self::icon('good').'<span>Your export is ready. <a class="ppr-btn ppr-btn-primary" download="pesepay-'.self::e($f['mode']).'-'.self::e($f['from']).'-'.self::e($f['to']).'.csv" href="data:text/csv;charset=utf-8;base64,'.base64_encode($csv).'">Download CSV</a></span></div>';
                    \logActivity('PesePay reports: CSV prepared by administrator '.$admin.'.');
                } elseif (($_POST['action']??'')==='clear') {
                    $id=filter_var($_POST['attempt']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                    if (!$id || ($_POST['confirm']??'')!=='yes') throw new \InvalidArgumentException('Confirm that you checked the PesePay merchant panel first.');
                    if (!$reports->clear($id)) throw new \InvalidArgumentException('This attempt cannot be cleared. Only attempts without a PesePay reference can be.');
                    \logActivity('PesePay attempt '.$id.' cleared as not charged by administrator '.$admin.'.');
                    echo '<div class="ppr-alert ppr-alert-good">'.self::icon('good').'Attempt #'.$id.' cleared. The client can now start a new payment.</div>';
                } elseif (($_POST['action']??'')==='check' && !empty($vars['allowStatusCheck'])) {
                    $id=filter_var($_POST['attempt']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                    if (!$id) throw new \InvalidArgumentException('Invalid attempt.');
                    self::checkProvider($id,$admin);
                } else { throw new \InvalidArgumentException('Action is disabled or unavailable.'); }
            } catch (\Throwable $e) {
                echo '<div class="ppr-alert ppr-alert-warning">'.self::icon('warning').self::e($e instanceof \InvalidArgumentException ? $e->getMessage() : 'The operation could not be completed. Check credentials, connectivity and the attempt details. No invoice payment was recorded.').'</div>';
            }
        }
        $detail=filter_var($_GET['attempt']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($detail) {
            $row=$reports->detail($detail);
            echo '<p class="ppr-back"><a href="'.self::e($url(['view'=>'transactions','attempt'=>null])).'">← All transactions</a></p>';
            if (!$row) { echo '<div class="ppr-empty">Attempt not found.</div></div>'; return; }
            self::detail($row,$vars,$csrf,$url(['attempt'=>$detail])); echo '</div>'; return;
        }
        if ($view==='health') { self::health($reports->health()); echo '</div>'; return; }

        self::filters($f,$view,$url);
        self::tiles($reports->summary($f),$reports->count(array_merge($f,['outcome'=>'attention'])),$url);
        if ($view==='reports') {
            self::daily($reports->daily($f),$f);
            self::breakdown($reports->breakdown($f));
        } else {
            $page=$reports->page($f,max(1,(int)($_GET['page']??1)));
            echo '<section class="ppr-card ppr-flush"><div class="ppr-card-head"><h3>'.($f['outcome']==='attention'?'Payments needing attention':'Transactions').'</h3><span class="ppr-muted">'.(int)$page['total'].' matching attempts · an invoice can have several</span></div>';
            echo '<div class="ppr-scroll"><table class="ppr-table"><thead><tr><th>Started</th><th>Attempt</th><th>Invoice</th><th>Wallet</th><th class="num">Amount</th><th>Status</th><th>PesePay reference</th></tr></thead><tbody>';
            foreach ($page['rows'] as $row) {
                $link=self::e($url(['attempt'=>$row['id']]));
                echo '<tr><td class="nowrap">'.self::short($row['created_at']).'</td><td><a href="'.$link.'">#'.(int)$row['id'].'</a></td><td><a href="invoices.php?action=edit&amp;id='.(int)$row['invoice_id'].'">#'.(int)$row['invoice_id'].'</a></td><td>'.self::e(self::wallet($row['method'])).'</td><td class="num nowrap">'.self::money($row['invoice_currency'],(int)$row['amount_cents']).'</td><td>'.self::badge($row).'</td><td class="ppr-ref">'.self::e($row['reference']?:'Not returned').'</td></tr>';
            }
            if (!$page['rows']) echo '<tr><td colspan="7"><div class="ppr-empty">No attempts match these filters.</div></td></tr>';
            echo '</tbody></table></div>';
            echo '<div class="ppr-card-foot"><span class="ppr-muted">Page '.(int)$page['page'].' of '.(int)$page['pages'].'</span><span class="ppr-pager">';
            echo $page['page']>1 ? '<a class="ppr-btn" href="'.self::e($url(['page'=>$page['page']-1])).'">← Previous</a>' : '<span class="ppr-btn is-disabled">← Previous</span>';
            echo $page['page']<$page['pages'] ? '<a class="ppr-btn" href="'.self::e($url(['page'=>$page['page']+1])).'">Next →</a>' : '<span class="ppr-btn is-disabled">Next →</span>';
            echo '</span></div></section>';
        }
        if (!empty($vars['allowExport'])) echo '<form class="ppr-export" method="post" action="'.self::e($url(['view'=>$view])).'"><input type="hidden" name="ppr_token" value="'.self::e($csrf).'"><input type="hidden" name="action" value="export"><button class="ppr-btn" type="submit">Prepare CSV export</button><span class="ppr-muted">Uses the current filters, up to 5,000 attempts. Never includes keys or phone numbers.</span></form>';
        echo self::keysNote().'</div>'.self::script();
    }
    private static function keysNote(): string
    {
        return '<p class="ppr-foot-note">Integration and encryption keys are set under <a href="configgateways.php">Setup &gt; Payment Gateways &gt; PesePay Direct</a>.</p>';
    }
    private static function filters(array $f,string $view,callable $url): void
    {
        echo '<form class="ppr-card ppr-filters" method="get" action="addonmodules.php"><input type="hidden" name="module" value="pesepayreports"><input type="hidden" name="view" value="'.self::e($view).'"><input type="hidden" name="mode" value="'.self::e($f['mode']).'">';
        echo '<label>Outcome<select name="outcome">';
        foreach ([''=>'All outcomes','success'=>'Paid','failed'=>'Failed / declined','pending'=>'Pending','attention'=>'Needs attention'] as $value=>$label) echo '<option value="'.$value.'"'.($f['outcome']===$value?' selected':'').'>'.$label.'</option>';
        echo '</select></label>';
        foreach (['from'=>'From','to'=>'Through'] as $name=>$label) echo '<label>'.$label.'<input type="date" name="'.$name.'" value="'.self::e($f[$name]).'"></label>';
        echo '<label>Wallet<select name="method"><option value="">All wallets</option>';
        foreach (Policy::METHODS as $code=>$m) echo '<option value="'.$code.'"'.($f['method']===$code?' selected':'').'>'.self::e($m['name'].' '.$m['currency']).'</option>';
        echo '</select></label>';
        foreach (['currency'=>['Currency','USD'],'invoice'=>['Invoice','e.g. 1401'],'reference'=>['PesePay reference','Exact reference']] as $name=>[$label,$hint]) echo '<label>'.$label.'<input type="text" name="'.$name.'" value="'.self::e($f[$name]).'" placeholder="'.self::e($hint).'"></label>';
        echo '<div class="ppr-filter-actions"><button class="ppr-btn ppr-btn-primary" type="submit">Apply</button><a class="ppr-btn ppr-btn-ghost" href="'.self::e('addonmodules.php?'.http_build_query(['module'=>'pesepayreports','view'=>$view,'mode'=>$f['mode']])).'">Reset</a></div></form>';
    }
    private static function tiles(array $rows,int $attention,callable $url): void
    {
        $attempts=0; $successes=0; $failures=0;
        foreach ($rows as $r) { $attempts+=(int)$r['attempts']; $successes+=(int)$r['successes']; $failures+=(int)$r['failures']; }
        echo '<section class="ppr-tiles">';
        if (!$rows) echo '<div class="ppr-tile ppr-tile-wide"><span class="ppr-tile-label">Collected</span><span class="ppr-tile-value">—</span><span class="ppr-tile-note">No payments in this period</span></div>';
        // Amounts are never added across currencies: one tile per currency.
        foreach ($rows as $r) echo '<div class="ppr-tile ppr-tile-wide"><span class="ppr-tile-label">Collected · '.self::e($r['currency']).'</span><span class="ppr-tile-value">'.self::money($r['currency'],(int)$r['collected_cents']).'</span><span class="ppr-tile-note">'.(int)$r['successes'].' paid of '.(int)$r['attempts'].' attempts</span></div>';
        echo '<div class="ppr-tile"><span class="ppr-tile-label">Success rate</span><span class="ppr-tile-value">'.($attempts?number_format(100*$successes/$attempts,0).'%':'—').'</span><span class="ppr-tile-note">'.$successes.' of '.$attempts.' attempts, pending included</span></div>';
        echo '<div class="ppr-tile"><span class="ppr-tile-label">Failed / declined</span><span class="ppr-tile-value">'.$failures.'</span><span class="ppr-tile-note">'.($failures?self::icon('critical').'Not charged':'None in this period').'</span></div>';
        echo '<a class="ppr-tile ppr-tile-link" href="'.self::e($url(['view'=>'transactions','outcome'=>'attention'])).'"><span class="ppr-tile-label">Needs attention</span><span class="ppr-tile-value">'.$attention.'</span><span class="ppr-tile-note">'.($attention?self::icon('warning').'Review these payments →':self::icon('good').'All clear').'</span></a>';
        echo '</section>';
    }
    private static function daily(array $rows,array $f): void
    {
        $byCurrency=[];
        foreach ($rows as $r) $byCurrency[$r['currency']][(int)$r['day_bucket']]=$r;
        $first=intdiv((int)$f['start'],86400); $last=intdiv((int)$f['end']-1,86400);
        echo '<section class="ppr-card"><div class="ppr-card-head"><h3>Daily collections</h3><span class="ppr-muted">Paid amount by the day the payment started</span></div>';
        if (!$byCurrency) { echo '<div class="ppr-empty">No payments in this period.</div></section>'; return; }
        ksort($byCurrency);
        // One chart per currency: amounts in different currencies never share an axis.
        foreach ($byCurrency as $currency=>$days) echo self::chart((string)$currency,$days,$first,$last);
        echo '<details class="ppr-table-toggle"><summary>Show as table</summary><div class="ppr-scroll"><table class="ppr-table"><thead><tr><th>Date (UTC)</th><th>Currency</th><th class="num">Attempts</th><th class="num">Paid</th><th class="num">Collected</th></tr></thead><tbody>';
        foreach ($rows as $r) echo '<tr><td>'.gmdate('j M Y',(int)$r['day_bucket']*86400).'</td><td>'.self::e($r['currency']).'</td><td class="num">'.(int)$r['attempts'].'</td><td class="num">'.(int)$r['successes'].'</td><td class="num">'.self::money($r['currency'],(int)$r['collected_cents']).'</td></tr>';
        echo '</tbody></table></div></details></section>';
    }
    private static function chart(string $currency,array $days,int $first,int $last): string
    {
        $count=max(1,$last-$first+1);
        // Wide viewBox so axis text renders near 11px on typical admin widths.
        $W=1100; $H=240; $L=56; $R=12; $T=14; $B=28; $pw=$W-$L-$R; $ph=$H-$T-$B;
        $max=0; foreach ($days as $d) $max=max($max,(int)$d['collected_cents']);
        [$top,$step]=self::niceScale($max);
        $slot=$pw/$count; $bar=min(24,max(2,$slot-2));
        $svg='<svg class="ppr-chart" viewBox="0 0 '.$W.' '.$H.'" role="img" aria-label="Daily collections in '.self::e($currency).'">';
        for ($v=0;$v<=$top;$v+=$step) {
            $y=$T+$ph-($top?$v/$top:0)*$ph;
            $svg.='<line class="grid" x1="'.$L.'" x2="'.($W-$R).'" y1="'.round($y,1).'" y2="'.round($y,1).'"/><text class="axis" x="'.($L-8).'" y="'.round($y+4,1).'" text-anchor="end">'.self::e(self::compact($v)).'</text>';
        }
        $every=max(1,(int)ceil($count/8));
        for ($i=0;$i<$count;$i++) {
            $day=$first+$i; $x=$L+$i*$slot+($slot-$bar)/2; $d=$days[$day]??null; $cents=$d?(int)$d['collected_cents']:0;
            if ($cents>0 && $top>0) {
                $h=max(2,$cents/$top*$ph); $y=$T+$ph-$h; $r=min(4,$bar/2,$h);
                // Rounded data-end, square at the baseline.
                $svg.='<path class="bar" d="M'.round($x,1).','.round($T+$ph,1).'V'.round($y+$r,1).'Q'.round($x,1).','.round($y,1).' '.round($x+$r,1).','.round($y,1).'H'.round($x+$bar-$r,1).'Q'.round($x+$bar,1).','.round($y,1).' '.round($x+$bar,1).','.round($y+$r,1).'V'.round($T+$ph,1).'Z"/>';
            }
            $tip=gmdate('D j M Y',$day*86400).'|'.self::money($currency,$cents).'|'.($d?(int)$d['successes'].' paid of '.(int)$d['attempts'].' attempts':'No attempts');
            $svg.='<rect class="hit" x="'.round($L+$i*$slot,1).'" y="'.$T.'" width="'.round($slot,1).'" height="'.$ph.'" data-tip="'.self::e($tip).'"/>';
            if ($i%$every===0) $svg.='<text class="axis" x="'.round($x+$bar/2,1).'" y="'.($H-8).'" text-anchor="middle">'.gmdate('j M',$day*86400).'</text>';
        }
        $svg.='<line class="base" x1="'.$L.'" x2="'.($W-$R).'" y1="'.($T+$ph).'" y2="'.($T+$ph).'"/></svg>';
        return '<figure class="ppr-figure"><figcaption>'.self::e($currency).'</figcaption><div class="ppr-chart-wrap">'.$svg.'<div class="ppr-tip" hidden></div></div></figure>';
    }
    // Clean axis: 0 to a round maximum in 4 steps (values in cents).
    private static function niceScale(int $max): array
    {
        if ($max<=0) return [100,25];
        $raw=$max/4; $mag=10**floor(log10($raw)); $norm=$raw/$mag;
        $nice=$norm<=1?1:($norm<=2?2:($norm<=2.5?2.5:($norm<=5?5:10)));
        $step=(int)max(1,round($nice*$mag)); return [$step*(int)ceil($max/$step),$step];
    }
    private static function compact(int $cents): string
    {
        $v=$cents/100;
        if ($v>=1000000) return rtrim(rtrim(number_format($v/1000000,1),'0'),'.').'M';
        if ($v>=1000) return rtrim(rtrim(number_format($v/1000,1),'0'),'.').'K';
        return $v==floor($v) ? number_format($v,0) : number_format($v,2);
    }
    private static function breakdown(array $rows): void
    {
        $wallets=[];
        foreach ($rows as $r) { $k=self::wallet($r['method']).' · '.$r['currency']; $wallets[$k]=($wallets[$k]??0)+(int)$r['attempts']; }
        arsort($wallets); $max=max(1,...array_values($wallets ?: [1]));
        echo '<div class="ppr-grid-2"><section class="ppr-card"><div class="ppr-card-head"><h3>Attempts by wallet</h3></div>';
        if (!$wallets) echo '<div class="ppr-empty">No attempts in this period.</div>';
        else { echo '<ul class="ppr-hbars">'; foreach ($wallets as $label=>$n) echo '<li><span class="ppr-hbar-label">'.self::e($label).'</span><span class="ppr-hbar-track"><span class="ppr-hbar" style="width:'.round(100*$n/$max,1).'%"></span></span><span class="ppr-hbar-value">'.$n.'</span></li>'; echo '</ul>'; }
        echo '</section><section class="ppr-card ppr-flush"><div class="ppr-card-head"><h3>Outcomes by wallet</h3></div><div class="ppr-scroll"><table class="ppr-table"><thead><tr><th>Wallet</th><th>Status</th><th class="num">Attempts</th></tr></thead><tbody>';
        foreach ($rows as $r) echo '<tr><td>'.self::e(self::wallet($r['method']).' · '.$r['currency']).'</td><td>'.self::badge(['credited'=>$r['status']==='SUCCESS'?1:0,'status'=>$r['status'],'created_at'=>time()]).'</td><td class="num">'.(int)$r['attempts'].'</td></tr>';
        if (!$rows) echo '<tr><td colspan="3"><div class="ppr-empty">No attempts in this period.</div></td></tr>';
        echo '</tbody></table></div></section></div>';
    }
    private static function detail(array $a,array $vars,string $csrf,string $url): void
    {
        $test=$a['environment']==='sandbox';
        echo '<section class="ppr-card"><div class="ppr-detail-head"><div><span class="ppr-muted">'.self::e(self::wallet($a['method'])).' · '.($test?'Test payment':'Live payment').' · '.self::e(self::short($a['created_at'])).'</span><h3>Attempt #'.(int)$a['id'].'</h3></div><div class="ppr-detail-amount">'.self::money($a['invoice_currency'],(int)$a['amount_cents']).self::badge($a).'</div></div>';
        $fields=['Invoice'=>'<a href="invoices.php?action=edit&amp;id='.(int)$a['invoice_id'].'">#'.(int)$a['invoice_id'].'</a>','Client'=>'<a href="clientssummary.php?userid='.(int)$a['client_id'].'">Client #'.(int)$a['client_id'].'</a>',
            'Mode'=>self::e($test?'Test (no real money)':'Live'),'Wallet'=>self::e(self::wallet($a['method']).' ('.$a['method'].')'),'Sent to PesePay as'=>self::e($a['api_currency']),'Provider status'=>'<code>'.self::e($a['status']).'</code>',
            'Recorded in WHMCS'=>self::e($a['credited']?($test?'Yes — test payment':'Yes'):'No'),'PesePay reference'=>'<code>'.self::e($a['reference']?:'Not returned').'</code>','Callback reference'=>'<code>'.self::e($a['candidate_reference']?:'None').'</code>',
            'Started'=>self::e(self::date($a['created_at'])),'Last checked'=>self::e(self::date($a['checked_at'])),'Queued for a check'=>self::e($a['needs_check']?'Yes':'No'),'O’mari code sent'=>self::e($a['otp_submitted']?'Yes':'No')];
        echo '<dl class="ppr-dl">'; foreach ($fields as $label=>$value) echo '<div><dt>'.self::e($label).'</dt><dd>'.$value.'</dd></div>'; echo '</dl>';
        echo '<div class="ppr-actions"><a class="ppr-btn" href="invoices.php?action=edit&amp;id='.(int)$a['invoice_id'].'">Open invoice</a><a class="ppr-btn" href="clientssummary.php?userid='.(int)$a['client_id'].'">Open client</a>';
        if (!empty($vars['allowStatusCheck']) && ($a['reference']||$a['candidate_reference'])) echo '<form method="post" action="'.self::e($url).'"><input type="hidden" name="ppr_token" value="'.self::e($csrf).'"><input type="hidden" name="action" value="check"><input type="hidden" name="attempt" value="'.(int)$a['id'].'"><button class="ppr-btn ppr-btn-primary" type="submit">Check status with PesePay</button></form>';
        echo '</div>';
        if (!empty($vars['allowStatusCheck']) && ($a['reference']||$a['candidate_reference'])) echo '<p class="ppr-muted ppr-small">The status check is read-only: it shows PesePay\'s verified result without changing this record or the invoice.</p>';
        echo '</section>';
        if (!$a['reference']&&!$a['candidate_reference']&&!$a['credited']&&in_array($a['status'],['UNKNOWN','STARTING'],true)) {
            echo '<section class="ppr-card ppr-card-warning"><div class="ppr-alert ppr-alert-warning">'.self::icon('warning').'<span>PesePay never returned a reference for this attempt, so the client cannot start another payment for this invoice. Check the PesePay merchant panel (and the WHMCS gateway log for the reason). If no payment was taken, clear it below.</span></div>';
            echo '<form class="ppr-clear" method="post" action="'.self::e($url).'"><input type="hidden" name="ppr_token" value="'.self::e($csrf).'"><input type="hidden" name="action" value="clear"><input type="hidden" name="attempt" value="'.(int)$a['id'].'"><label class="ppr-check"><input type="checkbox" name="confirm" value="yes" required> I checked the PesePay merchant panel and no payment was taken for this attempt</label><button class="ppr-btn ppr-btn-warning" type="submit">Clear attempt and allow a new payment</button></form></section>';
        }
    }
    private static function health(array $rows): void
    {
        echo '<section class="ppr-card"><div class="ppr-card-head"><h3>Unresolved payments</h3><span class="ppr-muted">All dates · live and test shown separately</span></div>';
        if (!$rows) echo '<div class="ppr-alert ppr-alert-good">'.self::icon('good').'No unresolved attempts. Every payment has a final outcome.</div>';
        else {
            echo '<div class="ppr-tiles">';
            foreach ($rows as $r) {
                $mode=$r['environment']==='sandbox'?'Test':'Live';
                echo '<div class="ppr-tile ppr-tile-wide"><span class="ppr-tile-label">'.$mode.' · unresolved</span><span class="ppr-tile-value">'.(int)$r['unresolved'].'</span><span class="ppr-tile-note">'.((int)$r['missing_reference']?self::icon('warning').(int)$r['missing_reference'].' without a PesePay reference':'All have a PesePay reference').'</span></div>';
                echo '<div class="ppr-tile"><span class="ppr-tile-label">'.$mode.' · oldest</span><span class="ppr-tile-value ppr-tile-value-sm">'.self::e(self::short($r['oldest'])).'</span><span class="ppr-tile-note">Last check '.self::e(self::short($r['latest_check'])).'</span></div>';
            }
            echo '</div>';
            echo '<table class="ppr-table"><thead><tr><th>Mode</th><th class="num">Unresolved</th><th class="num">Missing reference</th><th>Oldest attempt</th><th>Latest check among these attempts</th></tr></thead><tbody>';
            foreach ($rows as $r) echo '<tr><td>'.($r['environment']==='sandbox'?'Test':'Live').'</td><td class="num">'.(int)$r['unresolved'].'</td><td class="num">'.(int)$r['missing_reference'].'</td><td>'.self::date($r['oldest']).'</td><td>'.self::date($r['latest_check']).'</td></tr>';
            echo '</tbody></table>';
        }
        echo '<p class="ppr-muted ppr-small">The background job checks pending payments every minute. An old check time can mean a stalled job, missing keys or an unresolved PesePay error — see the WHMCS gateway log. Never mark an unknown payment as paid by hand.</p></section>';
    }
    private static function settings(array $s): void
    {
        echo '<section class="ppr-card"><div class="ppr-card-head"><h3>Gateway settings</h3><a class="ppr-btn ppr-btn-primary" href="configgateways.php">Set or change keys</a></div>';
        if (!$s['activated']) { echo '<div class="ppr-alert ppr-alert-warning">'.self::icon('warning').'<span>PesePay Direct is not activated. Activate it under <a href="configgateways.php">Setup &gt; Payment Gateways</a>, then enter the keys supplied by PesePay.</span></div></section>'; return; }
        if ($s['test']) echo '<div class="ppr-alert ppr-alert-warning">'.self::icon('warning').'<span><strong>Test mode is ON.</strong> Payments use PesePay\'s sandbox: no real money is taken, but invoices are marked paid. Turn it off before using this on the live site.</span></div>';
        if (!$s['ready']) echo '<div class="ppr-alert ppr-alert-critical">'.self::icon('critical').'<span>PesePay keys are missing or invalid. Clients cannot pay with PesePay until they are set.</span></div>';
        $check=static fn(string $label,string $value,bool $ok)=>'<li>'.self::icon($ok?'good':'critical').'<span class="ppr-check-label">'.self::e($label).'</span><span class="ppr-check-value">'.self::e($value).'</span></li>';
        echo '<ul class="ppr-checklist">'.$check('Mode',$s['test']?'Test (PesePay sandbox)':'Live',true).$check('Integration key',$s['integration'],$s['integration']==='Set').$check('Encryption key',$s['encryption'],$s['encryption']==='Set').$check('Enabled wallets',$s['methods']?implode(', ',$s['methods']):'None',(bool)$s['methods']).'</ul>';
        echo '<p class="ppr-muted ppr-small">Keys are entered under <strong>Setup &gt; Payment Gateways &gt; Manage Existing Gateways &gt; PesePay Direct</strong> and stored encrypted by WHMCS. This page only shows whether each key is present and valid; key values are never displayed. Enter test keys while Test mode is on, and live keys when it is off. Keep previous keys until every pending attempt made with them is resolved.</p></section>';
    }
    private static function wallet(string $code): string { $m=Policy::METHODS[$code]??null; return $m ? $m['name'] : $code; }
    private static function money(string $currency,int $cents): string { return '<span class="ppr-money">'.self::e($currency).' '.number_format($cents/100,2).'</span>'; }
    private static function badge(array $a): string
    {
        $status=(string)$a['status'];
        if (!empty($a['credited'])) [$tone,$label]=['good','Paid'];
        elseif ($status==='REJECTED') [$tone,$label]=['critical','Rejected'];
        elseif ($status==='CLEARED') [$tone,$label]=['neutral','Cleared'];
        elseif (in_array($status,Reports::FAILURES,true)) [$tone,$label]=['critical','Failed'];
        elseif (in_array($status,self::UNCERTAIN,true) || $status==='SUCCESS' || (int)($a['created_at']??time())<time()-1800) [$tone,$label]=['warning','Needs attention'];
        else [$tone,$label]=['pending','Pending'];
        $raw=ucfirst(strtolower(str_replace('_',' ',$status)));
        // The provider's own status is shown only when it adds something to the badge.
        $redundant=$raw===$label || ($label==='Paid' && $status==='SUCCESS');
        return '<span class="ppr-badge ppr-badge-'.$tone.'">'.self::icon($tone).$label.'</span>'.($redundant?'':'<span class="ppr-badge-raw">'.self::e($raw).'</span>');
    }
    // Status always ships with an icon and a label, never colour alone.
    private static function icon(string $tone): string
    {
        $paths=['good'=>'<path d="M4 8.5l2.5 2.5L12 5.5"/>','critical'=>'<path d="M5 5l6 6M11 5l-6 6"/>','warning'=>'<path d="M8 4v5M8 11.5v.5"/>','pending'=>'<circle cx="8" cy="8" r="4.5"/><path d="M8 5.5V8l1.8 1.2"/>','neutral'=>'<path d="M4.5 8h7"/>'];
        return '<svg class="ppr-icon ppr-icon-'.$tone.'" viewBox="0 0 16 16" aria-hidden="true">'.($paths[$tone]??$paths['neutral']).'</svg>';
    }
    private static function checkProvider(int $id,int $admin): void
    {
        $last=(int)($_SESSION['pesepayreports_check_at'][$id]??0);
        if (time()-$last<15) throw new \InvalidArgumentException('Wait 15 seconds before checking this attempt again.');
        $_SESSION['pesepayreports_check_at'][$id]=time();
        $root=dirname(__DIR__,4);
        require_once $root.'/includes/gatewayfunctions.php';
        require_once $root.'/modules/gateways/pesepaydirect/lib/bootstrap.php';
        $store=\Azaire\PesePay\store(); $a=$store->get($id);
        if (!$a) throw new \InvalidArgumentException('Attempt not found.');
        $reference=$a['reference']?:$a['candidate_reference'];
        if (!$reference) throw new \InvalidArgumentException('No provider reference is available.');
        $cfg=\Azaire\PesePay\config($a['environment']);
        $api=new \Azaire\PesePay\Api($cfg['integrationKey'],$cfg['encryptionKey'],null,$a['environment']==='sandbox');
        \logActivity('PesePay read-only status check requested for attempt '.$id.' by administrator '.$admin.'.');
        $result=$api->check($reference); $status=Policy::verify($a,$result,$reference);
        echo '<div class="ppr-alert ppr-alert-info">'.self::icon('pending').'<span>PesePay currently reports <strong>'.self::e($status).'</strong> for attempt #'.$id.'. Reference, amount and currency match. No invoice or transaction record was changed.</span></div>';
    }
    private static function script(): string
    {
        // Hover tooltip for the daily chart; the table view carries the same numbers.
        return <<<'HTML'
<script>(function(){document.querySelectorAll('.ppr-chart-wrap').forEach(function(wrap){var tip=wrap.querySelector('.ppr-tip');wrap.querySelectorAll('.hit').forEach(function(hit){hit.addEventListener('mouseenter',function(){var p=hit.getAttribute('data-tip').split('|');tip.innerHTML='';[['ppr-tip-date',p[0]],['ppr-tip-value',p[1]],['ppr-tip-note',p[2]]].forEach(function(x){var s=document.createElement('span');s.className=x[0];s.textContent=x[1];tip.appendChild(s);});tip.hidden=false;var w=wrap.getBoundingClientRect(),b=hit.getBoundingClientRect();var left=b.left-w.left+b.width/2;tip.style.left=Math.min(Math.max(left,70),w.width-70)+'px';hit.classList.add('on');});hit.addEventListener('mouseleave',function(){tip.hidden=true;hit.classList.remove('on');});});});})();</script>
HTML;
    }
    private static function styles(): string
    {
        // Scoped to .ppr so WHMCS admin styling is untouched. WHMCS admin is a light UI, so one light theme.
        return <<<'HTML'
<style>
.ppr{--surface:#fff;--surface-2:#f7f7f5;--border:#e6e5e1;--grid:#ecebe8;--text:#0b0b0b;--text-2:#52514e;--muted:#7a7975;--accent:#2a78d6;--accent-ink:#1c5cab;--accent-soft:#eaf2fc;--good:#0ca30c;--warning:#fab219;--serious:#ec835a;--critical:#d03b3b;
  max-width:1400px;color:var(--text);font-size:14px;line-height:1.5;font-variant-numeric:tabular-nums}
.ppr *{box-sizing:border-box}
.ppr a{color:var(--accent-ink)}
.ppr h2{margin:0;font-size:22px;font-weight:650;letter-spacing:-.01em}
.ppr h3{margin:0;font-size:15px;font-weight:650}
.ppr-head{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin:6px 0 16px}
.ppr-sub,.ppr-muted{color:var(--text-2)}
.ppr-sub{margin:4px 0 0}
.ppr-small{font-size:12.5px;margin:12px 0 0}
.ppr-seg{display:inline-flex;background:var(--surface-2);border:1px solid var(--border);border-radius:9px;padding:3px}
.ppr-seg a{padding:6px 16px;border-radius:7px;color:var(--text-2);font-weight:600;text-decoration:none}
.ppr-seg a.is-on{background:var(--surface);color:var(--text);box-shadow:0 1px 2px rgba(0,0,0,.08)}
.ppr-tabs{display:flex;gap:4px;border-bottom:1px solid var(--border);margin-bottom:16px;overflow-x:auto}
.ppr-tabs a{padding:10px 14px;color:var(--text-2);font-weight:600;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1px;white-space:nowrap}
.ppr-tabs a:hover{color:var(--text)}
.ppr-tabs a.is-on{color:var(--accent-ink);border-bottom-color:var(--accent)}
.ppr-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:18px;margin-bottom:16px}
.ppr-card.ppr-flush{padding:0}
.ppr-card.ppr-flush .ppr-card-head{padding:16px 18px 12px}
.ppr-card-head{display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap;margin-bottom:12px}
.ppr-flush .ppr-card-head{margin:0;border-bottom:1px solid var(--border)}
.ppr-card-foot{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 18px;border-top:1px solid var(--border)}
.ppr-card-warning{border-color:#f3d48a}
.ppr-filters{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;align-items:end;padding:14px 16px}
.ppr-filters label{display:flex;flex-direction:column;gap:4px;margin:0;font-size:12px;font-weight:600;color:var(--text-2)}
.ppr-filters input,.ppr-filters select{height:36px;padding:6px 10px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font-size:13.5px;font-weight:400;width:100%}
.ppr-filters input:focus,.ppr-filters select:focus{outline:2px solid var(--accent);outline-offset:0;border-color:var(--accent)}
.ppr-filter-actions{display:flex;gap:8px}
.ppr-btn{display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 14px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font-weight:600;font-size:13.5px;text-decoration:none;cursor:pointer;white-space:nowrap}
.ppr-btn:hover{background:var(--surface-2);text-decoration:none}
.ppr-btn-primary{background:var(--accent);border-color:var(--accent);color:#fff!important}
.ppr-btn-primary:hover{background:var(--accent-ink)}
.ppr-btn-warning{background:#fff7e6;border-color:#f3d48a;color:#7a4b00}
.ppr-btn-ghost{border-color:transparent;color:var(--text-2)}
.ppr-btn.is-disabled{opacity:.45;cursor:default}
.ppr-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:16px}
.ppr-tile{display:flex;flex-direction:column;gap:2px;background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:14px 16px;color:var(--text);text-decoration:none}
.ppr-tile-wide{background:linear-gradient(180deg,var(--accent-soft),var(--surface) 70%)}
.ppr a.ppr-tile-link{color:var(--text)}
.ppr-tile-link:hover{border-color:var(--accent);text-decoration:none}
.ppr-tile-label{font-size:12.5px;font-weight:600;color:var(--text-2)}
.ppr-tile-value{font-size:28px;font-weight:650;letter-spacing:-.02em;line-height:1.2}
.ppr-tile-value-sm{font-size:18px;line-height:1.6}
.ppr-tile-note{display:flex;align-items:center;gap:5px;font-size:12.5px;color:var(--text-2)}
.ppr-scroll{overflow-x:auto}
.ppr-table{width:100%;border-collapse:collapse;margin:0}
.ppr-table th{padding:10px 14px;background:var(--surface-2);color:var(--text-2);font-size:12px;font-weight:600;text-align:left;border-bottom:1px solid var(--border);white-space:nowrap}
.ppr-table td{padding:11px 14px;border-bottom:1px solid var(--grid);vertical-align:middle}
.ppr-table tbody tr:hover td{background:#fafaf8}
.ppr-table tbody tr:last-child td{border-bottom:0}
.ppr-table .num{text-align:right}
.ppr .nowrap{white-space:nowrap}
.ppr-ref,.ppr code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;color:var(--text-2);background:none;padding:0;overflow-wrap:anywhere}
.ppr-money{font-weight:600;white-space:nowrap}
.ppr-badge{display:inline-flex;align-items:center;gap:5px;padding:2px 10px 2px 7px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap;color:var(--text);border:1px solid}
.ppr-badge-good{background:#ecf8ec;border-color:#bfe5bf}
.ppr-badge-critical{background:#fcefef;border-color:#f1c4c4}
.ppr-badge-warning{background:#fff7e6;border-color:#f3d48a}
.ppr-badge-pending{background:var(--accent-soft);border-color:#c5dbf6}
.ppr-badge-neutral{background:var(--surface-2);border-color:var(--border)}
.ppr-badge-raw{display:block;margin-top:3px;font-size:11.5px;color:var(--muted)}
.ppr-icon{width:14px;height:14px;flex:none;fill:none;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}
.ppr-icon-good{stroke:var(--good)}.ppr-icon-critical{stroke:var(--critical)}.ppr-icon-warning{stroke:#c98500}.ppr-icon-pending{stroke:var(--accent)}.ppr-icon-neutral{stroke:var(--muted)}
.ppr-alert{display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border-radius:10px;border:1px solid;margin-bottom:16px;color:var(--text)}
.ppr-alert .ppr-icon{margin-top:3px}
.ppr-alert-good{background:#ecf8ec;border-color:#bfe5bf}
.ppr-alert-warning{background:#fff7e6;border-color:#f3d48a}
.ppr-alert-critical{background:#fcefef;border-color:#f1c4c4}
.ppr-alert-info{background:var(--accent-soft);border-color:#c5dbf6}
.ppr-alert .ppr-btn{margin-left:8px;height:30px}
.ppr-empty{padding:28px;text-align:center;color:var(--text-2)}
.ppr-figure{margin:0 0 8px}
.ppr-figure figcaption{font-size:12.5px;font-weight:600;color:var(--text-2);margin-bottom:4px}
.ppr-chart-wrap{position:relative}
.ppr-chart{display:block;width:100%;height:auto;overflow:visible}
.ppr-chart .grid{stroke:var(--grid);stroke-width:1}
.ppr-chart .base{stroke:#d5d4cf;stroke-width:1}
.ppr-chart .axis{fill:var(--muted);font-size:11px}
.ppr-chart .bar{fill:var(--accent)}
.ppr-chart .hit{fill:transparent;cursor:crosshair}
.ppr-chart .hit.on{fill:rgba(42,120,214,.07)}
.ppr-tip{position:absolute;top:0;transform:translateX(-50%);pointer-events:none;background:var(--text);color:#fff;border-radius:8px;padding:7px 10px;font-size:12px;line-height:1.4;box-shadow:0 4px 14px rgba(0,0,0,.18);white-space:nowrap;display:flex;flex-direction:column}
.ppr-tip[hidden]{display:none}
.ppr-tip-value{font-size:14px;font-weight:650}
.ppr-tip-date,.ppr-tip-note{color:#c3c2b7}
.ppr-table-toggle{margin-top:8px}
.ppr-table-toggle summary{cursor:pointer;color:var(--accent-ink);font-weight:600;font-size:13px;padding:4px 0}
.ppr-table-toggle .ppr-table{margin-top:8px;border:1px solid var(--border)}
.ppr-grid-2{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:16px;align-items:start}
.ppr-hbars{list-style:none;margin:0;padding:0;display:grid;gap:10px}
.ppr-hbars li{display:grid;grid-template-columns:minmax(110px,30%) 1fr 40px;gap:10px;align-items:center}
.ppr-hbar-label{font-size:13px;color:var(--text)}
.ppr-hbar-track{height:12px;background:var(--surface-2);border-radius:4px;overflow:hidden}
.ppr-hbar{display:block;height:100%;background:var(--accent);border-radius:0 4px 4px 0;min-width:3px}
.ppr-hbar-value{text-align:right;font-weight:600}
.ppr-back{margin:0 0 10px}
.ppr-back a{font-weight:600;text-decoration:none}
.ppr-detail-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;padding-bottom:14px;border-bottom:1px solid var(--border);margin-bottom:14px}
.ppr-detail-head h3{font-size:20px;margin-top:2px}
.ppr-detail-amount{display:flex;flex-direction:column;align-items:flex-end;gap:6px;font-size:24px}
.ppr-detail-amount .ppr-badge-raw{text-align:right}
.ppr-dl{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px 24px;margin:0}
.ppr-dl dt{font-size:12px;font-weight:600;color:var(--text-2);margin:0}
.ppr-dl dd{margin:2px 0 0;overflow-wrap:anywhere}
.ppr-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:18px}
.ppr-actions form{margin:0}
.ppr-clear{display:flex;flex-wrap:wrap;gap:12px;align-items:center}
.ppr-check{display:flex;gap:8px;align-items:center;font-weight:500;margin:0}
.ppr-checklist{list-style:none;margin:0;padding:0;border:1px solid var(--border);border-radius:10px}
.ppr-checklist li{display:grid;grid-template-columns:20px 160px 1fr;gap:10px;align-items:center;padding:12px 14px;border-bottom:1px solid var(--grid)}
.ppr-checklist li:last-child{border-bottom:0}
.ppr-check-label{font-weight:600}
.ppr-check-value{color:var(--text-2)}
.ppr-export{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin:0 0 8px}
.ppr-foot-note{font-size:12.5px;color:var(--muted);margin:16px 0 0}
@media (max-width:640px){.ppr-head{align-items:flex-start}.ppr-tile-value{font-size:24px}.ppr-checklist li{grid-template-columns:20px 1fr}.ppr-check-value{grid-column:2}.ppr-detail-amount{align-items:flex-start}}
</style>
HTML;
    }
}
