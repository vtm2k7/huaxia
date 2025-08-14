<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
use think\facade\Route;


// 支付相关 - 明确指定POST方法，提高安全性
Route::post('pay/unifiedorder', 'Pay/unifiedOrder');
Route::post('pay/notify', 'Pay/notify');        // 微信回调必须是POST
Route::post('pay/verify', 'Pay/verify');

// 查询类接口可以允许GET
Route::get('pay/query', 'Pay/queryOrder');

// 敏感操作强制POST
Route::post('pay/refund', 'Pay/refund');
Route::post('pay/close', 'Pay/closeOrder');

// 发票相关
Route::post('smb/invoice', 'Smb/invoice');
Route::get('smb/query', 'Smb/query');