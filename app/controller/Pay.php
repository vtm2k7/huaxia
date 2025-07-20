<?php

namespace app\controller;

use app\model\Order;
use think\facade\Request;
use think\facade\Log;

class Pay extends Base {
    private $mchid = '1718031075'; // 子商户ID

    /*
     * 统一下单程序
     */
    public function unifiedOrder() {

        $mock = env('IS_MOCK_PAY', false);

        $header = $this->headers;
        $body = $this->jsonBody;

        $fee = 1;
        $payid = $body["payid"] ?? uniqid();
        $outTradeNo = '2025HX' . $payid;

        // 1. 插入订单
        Order::create([
            'openid' => $this->openid,
            'out_trade_no' => $outTradeNo,
            'total_fee' => $fee,
            'product_name' => $body["paytext"] ?? '测试微信支付',
            'create_time' => time(),
        ]);

        // 模拟模式（跳过远程调用）
        if ($mock == 'true') {
            return json([
                'mock' => true,
                'message' => '模拟支付成功',
                'out_trade_no' => $outTradeNo,
                'prepay_id' => 'MOCK_PREPAY_' . uniqid()
            ]);
        }

        // 2. 调用微信统一下单
        $param = [
            'body' => $body["paytext"] ?? "测试微信支付",
            'openid' => $this->openid,
            'out_trade_no' => $outTradeNo,
            'spbill_create_ip' => $header['x-forwarded-for'] ?? '',
            'env_id' => $header['x-wx-env'] ?? '',
            'sub_mch_id' => $this->mchid,
            'total_fee' => $fee,
            'callback_type' => 2,
            'container' => [
                'service' => 'pay',
                'path' => '/pay/notify'
            ]
        ];

        return $this->sendRequest('http://api.weixin.qq.com/_/pay/unifiedOrder', $param);
    }


    /*
     * 接受微信的回调
     */
    public function notify() {
        $data = json_decode(file_get_contents('php://input'), true);
        Log::info('支付回调：' . json_encode($data));

        $outTradeNo = $data['out_trade_no'] ?? '';
        $transactionId = $data['transaction_id'] ?? '';
        $totalFee = $data['total_fee'] ?? 0;

        if ($outTradeNo && $transactionId) {
            // 更新订单
            Order::where('out_trade_no', $outTradeNo)->update([
                'pay_status' => 1,
                'pay_time' => time(),
                'transaction_id' => $transactionId,
                'update_time' => time(),
            ]);
        }

        // 必须返回 success，否则微信会重试
        return response('success');
    }

    /*
     * 模拟回调
     */
    public function mockNotify() {
        $body = $this->jsonBody;

        // 你要测试的 out_trade_no，例如传 "2021WERUNabc123"
        $outTradeNo = $body['out_trade_no'] ?? null;
        $transactionId = $body['transaction_id'] ?? ('MOCKTXN_' . uniqid());
        $totalFee = $body['total_fee'] ?? 2;

        if (!$outTradeNo) {
            return json(['error' => '缺少 out_trade_no'], 400);
        }

        // 模拟支付成功回调行为
        Order::where('out_trade_no', $outTradeNo)->update([
            'pay_status' => 1,
            'transaction_id' => $transactionId,
            'pay_time' => time(),
            'update_time' => time(),
        ]);

        return json([
            'mock' => true,
            'msg' => '模拟支付成功通知已触发',
            'out_trade_no' => $outTradeNo,
            'transaction_id' => $transactionId
        ]);
    }

    /*
     * 订单查询
     */
    public function queryOrder() {
        $body = $this->jsonBody;

        $param = [
            'out_trade_no' => '2021WERUN' . ($body["payid"] ?? ''),
            'sub_mch_id' => $this->mchid
        ];

        return $this->sendRequest('http://api.weixin.qq.com/_/pay/queryorder', $param);
    }

    /*
     * 关闭订单
     */
    public function closeOrder() {
        $body = $this->jsonBody;

        $param = [
            'out_trade_no' => '2021WERUN' . ($body["payid"] ?? ''),
            'sub_mch_id' => $this->mchid
        ];

        return $this->sendRequest('http://api.weixin.qq.com/_/pay/closeorder', $param);
    }

    /*
     * 发起退款
     */
    public function refund() {
        $header = $this->headers;
        $body = $this->jsonBody;

        $param = [
            'body' => $body["paytext"] ?? "测试微信支付",
            'out_trade_no' => '2021WERUN' . ($body["payid"] ?? ''),
            'out_refund_no' => 'R2021WERUN' . ($body["payid"] ?? ''),
            'env_id' => $header['x-wx-env'] ?? '',
            'sub_mch_id' => $this->mchid,
            'total_fee' => $body["fee"] ?? 2,
            'refund_fee' => $body["refundfee"] ?? 2,
            'refund_desc' => $body["refundtext"] ?? "测试退款",
            'callback_type' => 2,
            'container' => [
                'service' => 'pay',
                'path' => '/'
            ]
        ];

        return $this->sendRequest('http://api.weixin.qq.com/_/pay/refund', $param);
    }

    public function queryRefund() {
        $body = Request::post();

        $param = [
            'out_trade_no' => '2021WERUN' . ($body["payid"] ?? ''),
            'sub_mch_id' => $this->mchid
        ];

        return $this->sendRequest('http://api.weixin.qq.com/_/pay/queryrefund', $param);
    }

    // 可选：统一的处理器入口（仅判断 payid 是否存在）
    public function index() {
        $body = Request::post();

        if (empty($body["payid"]) && empty($body["transactionId"])) {
            return response('没有收到订单ID');
        }

        return json([
            'errcode' => 0,
            'errmsg' => 'ok'
        ]);
    }

    /*
     * 微信支付请求转发
     */
    private function sendRequest(string $url, array $param): string {
        Log::info('----pay url----' . $url);
        Log::info('----pay body----' . json_encode($param));

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($param),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        Log::info('----pay result----' . $response);

        return $response ?: '没有打开开放接口服务，请打开后重新部署此项目';
    }
}
