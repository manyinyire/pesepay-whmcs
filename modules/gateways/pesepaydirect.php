<?php
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }
function pesepaydirect_MetaData()
{
    return ['DisplayName'=>'PesePay Direct','APIVersion'=>'1.1','DisableLocalCreditCardInput'=>true,'TokenisedStorage'=>false];
}
function pesepaydirect_config()
{
    $fields=[
        'FriendlyName'=>['Type'=>'System','Value'=>'PesePay Direct'],
        'testMode'=>['FriendlyName'=>'Test mode','Type'=>'yesno','Default'=>'','Description'=>'Uses PesePay\'s sandbox with test keys: no real money moves, but invoices ARE marked paid. For UAT only — never enable on the live site.'],
        'integrationKey'=>['FriendlyName'=>'Integration key','Type'=>'password','Size'=>'60','Description'=>'Live key from your PesePay application, or the test key when Test mode is on.'],
        'encryptionKey'=>['FriendlyName'=>'Encryption key','Type'=>'password','Size'=>'60','Description'=>'Exactly 32 characters, supplied with the integration key.'],
    ];
    foreach (['PZW211'=>'EcoCash USD','PZW201'=>'EcoCash ZiG','PZW212'=>'InnBucks USD','PZW216'=>'O’mari USD','PZW217'=>'O’mari ZiG','PZW210'=>'PayGo ZiG'] as $code=>$name) {
        $fields['enable_'.$code]=['FriendlyName'=>$name,'Type'=>'yesno','Default'=>$code==='PZW211'?'on':'','Description'=>'Enable after validating this method on your merchant account.'];
    }
    return $fields;
}
function pesepaydirect_config_validate($params)
{
    // Called by WHMCS when an administrator saves the gateway settings.
    require_once __DIR__.'/pesepaydirect/lib/Policy.php';
    require_once __DIR__.'/pesepaydirect/lib/Settings.php';
    $errors=\Azaire\PesePay\Settings::validate((array)$params);
    if (!$errors) return;
    $message=implode(' ',$errors);
    if (class_exists('\WHMCS\Exception\Module\InvalidConfiguration')) throw new \WHMCS\Exception\Module\InvalidConfiguration($message);
    throw new \InvalidArgumentException($message);
}
function pesepaydirect_link($params)
{
    $escape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
    if (empty($_SESSION['pesepaydirect_csrf'])) { $_SESSION['pesepaydirect_csrf']=bin2hex(random_bytes(32)); }
    $base=rtrim($params['systemurl'],'/').'/modules/gateways/pesepaydirect/';
    $id=(int)$params['invoiceid'];
    return '<link rel="stylesheet" href="'.$escape($base).'assets/checkout.css">'
        .'<section class="ppd" data-pesepay-direct data-endpoint="'.$escape($base).'checkout.php" data-invoice="'.$id.'" data-csrf="'.$escape($_SESSION['pesepaydirect_csrf']).'" aria-label="PesePay payment">'
        .'<div class="ppd-head"><h3>Pay with PesePay</h3><p class="ppd-amount" hidden>Amount due <strong class="ppd-amount-value"></strong></p></div>'
        .'<p class="ppd-status" role="status" aria-live="polite">Loading payment options…</p>'
        // Named "Wallet" so it is never confused with WHMCS's own "Payment Method" (gateway) selector.
        .'<div class="ppd-options" hidden><fieldset class="ppd-wallets"><legend>Wallet</legend><div class="ppd-wallet-list"></div></fieldset>'
        .'<p class="ppd-single" hidden></p>'
        .'<label class="ppd-phone-label">Mobile number<input class="ppd-phone" type="tel" autocomplete="tel" placeholder="0771234567"></label>'
        .'<p class="ppd-hint"></p><button type="button" class="ppd-pay">Pay now</button></div>'
        .'<div class="ppd-wait" hidden><p class="ppd-reference"></p><p class="ppd-code"></p>'
        .'<img class="ppd-qr" hidden alt="Scan this code in your payment app" referrerpolicy="no-referrer">'
        .'<div class="ppd-otp" hidden><label>SMS verification code<input class="ppd-otp-value" inputmode="numeric" autocomplete="one-time-code" maxlength="6"></label><button type="button" class="ppd-submit-otp">Confirm payment</button></div>'
        .'<div class="ppd-loader" hidden aria-hidden="true"><span class="ppd-spinner"></span><span>Waiting for payment confirmation…</span></div>'
        .'<button type="button" class="ppd-retry" hidden>Try another payment</button></div>'
        .'<p class="ppd-alt">Prefer to pay by bank transfer? Choose it under <strong>Payment Method</strong> on this invoice.</p></section>'
        .'<script src="'.$escape($base).'assets/checkout.js" defer></script>';
}
// No refund/capture/subscription functions: never advertise an operation this module cannot perform.
