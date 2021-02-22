<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;
require_once __DIR__ . '/ecpay/ecpay.php';

function ecpay_credit_MetaData() {
    return array(
        'DisplayName' => 'ECPay - 信用卡',
        'APIVersion' => '1.1', // Use API Version 1.1
        'DisableLocalCredtCardInput' => false,
        'TokenisedStorage' => false,
    );
}

function ecpay_credit_config() {
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => '綠界 - 信用卡',
        ),
        'MerchantID' => array(
            'FriendlyName' => '會員編號',
            'Type' => 'text',
            'Size' => '7',
            'Default' => '',
            'Description' => 'ECPay會員編號。',
        ),
        'HashKey' => array(
            'FriendlyName' => 'HashKey',
            'Type' => 'password',
            'Size' => '16',
            'Default' => '',
            'Description' => '於廠商管理後台->系統開發管理->系統介接設定中取得',
        ),
        'HashIV' => array(
            'FriendlyName' => 'HashIV',
            'Type' => 'password',
            'Size' => '16',
            'Default' => '',
            'Description' => '於廠商管理後台->系統開發管理->系統介接設定中取得',
        ),
        'CreditCheckCode' => array(
            'FriendlyName' => '商家檢查碼',
            'Type' => 'text',
            'Size' => '8',
            'Default' => '',
            'Description' => '於廠商管理後台->信用卡收單->信用卡授權資訊中取得',
        ),
        'InvoicePrefix' => array(
            'FriendlyName' => '帳單前綴',
            'Type' => 'text',
            'Default' => '',
            'Description' => '選填（只能為數字、英文，且與帳單 ID 合併總字數不能超過 20）',
            'Size' => '5',
        ),
        'testMode' => array(
            'FriendlyName' => '測試模式',
            'Type' => 'yesno',
            'Description' => '測試模式',
        ),
    );
}

function ecpay_credit_link($params) {

    # Invoice Variables
    $TimeStamp = time();
    $TradeNo = $params['InvoicePrefix'].$TimeStamp.$params['invoiceid'];
    $amount = $params['amount']; # Format: ##.##
    $TotalAmount = round($amount); # Format: ##

    $Language = ($params['clientdetails']['language']=='english') ? 'ENG' : '';

    # System Variables
    $systemurl = $params['systemurl'];

    $transaction = new ECPay_Pay('Credit');

    # 是否為測試模式
    if ($params['testMode'] == 'on') {
        $transaction->setTestMode();
    } else {
        $transaction->MerchantID = $params['MerchantID'];
        $transaction->HashKey = $params['HashKey'];
        $transaction->HashIV  = $params['HashIV'];
    }

    $transaction->MerchantTradeNo = $TradeNo;
    $transaction->TotalAmount = $TotalAmount;
    $transaction->TradeDesc = $params['description'];
    $transaction->ItemName = $params['description'];
    $transaction->ReturnURL = rtrim($systemurl, '/').'/modules/gateways/callback/ecpay_credit.php';
    $transaction->ClientBackURL = $params['returnurl'];
    $transaction->Language = $Language;
    $transaction->NeedExtraPaidInfo = 'Y';

    return $transaction->GetHTML($params['langpaynow']);
}

function ecpay_credit_refund($params) {
    if ($params['testMode'] == 'on') {
        return array(
            'status' => 'declined',
            'rawdata' => 'Cannot refund in test mode.',
        );
    }
    list($MerchantTradeNo, $TradeNo, $Gwsr) = explode('-', $params['transid']);
    $invoice = Capsule::table('tblinvoices')
            ->where('id', $params['invoiceid'])
            ->first();
    $TradeAmount = round($invoice->total);
    $RefundAmount = round($params['amount']);
    $api = new ECPay_API();
    $api->HashKey = $params['HashKey'];
    $api->HashIV  = $params['HashIV'];
    $api->MerchantID = $params['MerchantID'];
    // query
    $api->CreditRefundId = $Gwsr;
    $api->CreditCheckCode = $params['CreditCheckCode'];
    $api->CreditAmount = $TradeAmount;
    $TradeInfo = $api->QueryTrade();
    $api->reset();
    // process
    $api->MerchantTradeNo = $MerchantTradeNo;
    $api->TradeNo = $TradeNo;
    switch ($TradeInfo['RtnValue']['status']) {
        case '已授權':
            if ($RefundAmount < $TradeAmount) {
                return array(
                    'status' => 'declined',
                    'rawdata' => '尚未關帳，無法部分退款。',
                    'transid' => $params['transid'],
                    'fees' => 0,
                );
            } else {
                $api->TotalAmount = $TradeAmount;
                $Result = $api->GiveUp();
            }
        break;
        case '要關帳':
            if ($RefundAmount < $TradeAmount) {
                $api->TotalAmount = $RefundAmount;
                $Result = $api->Refund();
            } else {
                $api->TotalAmount = $TradeAmount;
                $Result = $api->Cancel();
                if ($CloseResult['RtnCode'] != '1') {
                    break;
                }
                $Result = $api->GiveUp();
            }
        break;
        case '已關帳':
            $api->TotalAmount = $RefundAmount;
            $Result = $api->Refund();
        break;
        default:
            return array(
                'status' => 'declined',
                'rawdata' => $TradeInfo,
                'transid' => $params['transid'],
                'fees' => 0,
            );
    }
    return array(
        'status' => ($Result['RtnCode']=='1')?'success':'declined',
        'rawdata' => $Result,
        'transid' => $Result['TradeNo'],
        'fees' => 0,
    );
}
