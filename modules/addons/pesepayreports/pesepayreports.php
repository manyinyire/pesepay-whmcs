<?php
if (!defined('WHMCS')) { http_response_code(403); exit; }
function pesepayreports_config()
{
    return ['name'=>'PesePay Transactions & Reports','description'=>'Transactions, reconciliation visibility and currency-separated reports for PesePay Direct.','version'=>'0.3.0-rc1','author'=>'Azaire','fields'=>[
        'allowStatusCheck'=>['FriendlyName'=>'Allow provider status checks','Type'=>'yesno','Description'=>'Read-only PesePay checks for admins allowed to access this addon. Never records invoice payments.','Default'=>''],
        'allowExport'=>['FriendlyName'=>'Allow CSV exports','Type'=>'yesno','Description'=>'Export up to 5,000 filtered attempts. Restrict addon access to appropriate admin roles.','Default'=>'on'],
    ]];
}
function pesepayreports_activate()
{
    // Reporting reads existing gateway tables. Activation never changes customer data or payment configuration.
    return ['status'=>'success','description'=>'Reporting enabled. Grant access only to the intended admin roles.'];
}
function pesepayreports_deactivate()
{
    return ['status'=>'success','description'=>'Reporting disabled. Transaction records have been retained.'];
}
function pesepayreports_output($vars)
{
    if (empty($_SESSION['adminid'])) { echo 'Administrator authentication required.'; return; }
    // WHMCS enforces the addon's configured admin-role access before invoking this output function.
    require_once __DIR__.'/../../gateways/pesepaydirect/lib/Policy.php';
    require_once __DIR__.'/../../gateways/pesepaydirect/lib/Settings.php';
    require_once __DIR__.'/lib/Reports.php';
    require_once __DIR__.'/lib/Page.php';
    try {
        $pdo=\WHMCS\Database\Capsule::connection()->getPdo();
        $reports=new \Azaire\PesePay\Admin\Reports($pdo);
        $gateway=static function(): array {
            require_once ROOTDIR.'/includes/gatewayfunctions.php';
            try { return (array)\getGatewayVariables('pesepaydirect'); } catch (\Throwable $e) { return []; }
        };
        \Azaire\PesePay\Admin\Page::render($reports,$vars,$gateway);
    } catch (\Throwable $e) {
        \logActivity('PesePay reports error: '.get_class($e));
        echo '<div class="alert alert-warning">Reports are unavailable. Check that the PesePay Direct gateway table has been installed. No client or invoice data was changed.</div>';
    }
}
