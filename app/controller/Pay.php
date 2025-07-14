<?php
namespace app\controller;

use think\Request;

class Pay
{
    /**
     * 微信云托管方式统一下单
     */
    public function unifiedOrder(Request $request)
    {
        // 从 header 拿 openid（云托管会自动注入）
        $openid = $request->header('x-wx-openid');

        if (!$openid) {
            return json(['error' => 'No openid found'], 400);
        }

        // 准备请求数据
        $payload = [
            "openid" => $openid,
            "body" => "测试商品",
            "out_trade_no" => uniqid("order_"),
            "spbill_create_ip" => "127.0.0.1",
            "sub_mch_id" => "1718031075",               // 必填：你的商户号
            "total_fee" => 1,                             // 单位：分
            "env_id" => "prod-6glx2ljba18e7b40",               // 必填：你的云托管环境ID
            "callback_type" => 2,
            "container" => [
                "service" => "thinkphp-nginx-dq0y",           // 必填：你的云托管服务名
                "path" => "/paycallback"                  // 必填：你接收支付回调的路径
            ]
        ];

        // 发起请求到云托管封装的微信支付接口
        $url = "http://api.weixin.qq.com/_/pay/unifiedOrder";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            curl_close($ch);
            return json(['error' => $error_msg], 500);
        }
        curl_close($ch);

        $result = json_decode($response, true);

        return json($result);
    }

    public function paycallback(Request $request)
    {
        $data = $request->post();

        // 这里写你自己更新订单状态的逻辑
        file_put_contents('/tmp/pay_callback.log', json_encode($data));

        // 云托管要求返回
        return json(['errcode' => 0]);
    }
}
