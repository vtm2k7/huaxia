<?php

namespace app\controller;

use app\model\Order;
use think\facade\Request;
use think\facade\Log;

class Pay extends Base {
    const TRADE_PREFIX = '2025HX';
    private $mchid = '1718031075'; // 子商户ID

    /*
     * 用户信息查询验证
     */
    public function verify() {
        $targetUrl = 'http://huaxia.ad-wizard.cn/mini/verify';
        return $this->remoteRequest($targetUrl, $this->jsonBody);
    }

    /*
     * 统一下单程序
     */
    public function unifiedOrder() {

        $header = $this->headers;
        $body = $this->jsonBody;

        // 这里的 fee 是固定的 1 分，实际应用中可以根据业务逻辑调整
        // 例如：$fee = $body['fee'] ?? 1; 这样可以从请求体中获取费用
        $fee = 1;

        $payid = $body["payid"] ?? uniqid();
        $outTradeNo = self::TRADE_PREFIX . $payid;

        $bindingNo = $body['binding_no'];
        $tombId = $body['tomb_id'];
        $tombPath = $body['tomb_path'];

        // 1. 插入订单
        Order::create([
            'openid' => $this->openid,
            'binding_no' => $bindingNo,
            'tomb_id' => $tombId,
            'tomb_path' => $tombPath,
            'out_trade_no' => $outTradeNo,
            'total_fee' => $fee,
            'create_time' => time(),
        ]);

        // 2. 调用微信统一下单
        $param = [
            'body' => "墓地维护费",
            'openid' => $this->openid,
            'out_trade_no' => $outTradeNo,
            'spbill_create_ip' => $header['x-forwarded-for'] ?? '',
            'env_id' => $header['x-wx-env'] ?? '',
            'sub_mch_id' => $this->mchid,
            'total_fee' => $fee,
            'callback_type' => 2,
            'container' => [
                'service' => 'thinkphp-nginx-dq0y',
                'path' => '/pay/notify'
            ]
        ];
        Log::info('----unifiedOrder----' . json_encode($param));

        return $this->sendRequest('http://api.weixin.qq.com/_/pay/unifiedOrder', $param);
    }

    /*
    * 接受微信的回调
    */
    public function notify() {
        $data = json_decode(file_get_contents('php://input'), true);
        Log::info('----notify----' . json_encode($data));

        // 必须是支付成功才处理
        if (($data['resultCode'] ?? '') !== 'SUCCESS')
            return response('fail', 400);

        $openid = $data['subOpenid'];
        $outTradeNo = $data['outTradeNo'] ?? '';
        $transactionId = $data['transactionId'] ?? '';

        // 获取微信支付时间 - 正确解析 yyyyMMddHHmmss 格式
        $timeEnd = $data['timeEnd'] ?? '';
        $payTime = $timeEnd ? $this->parseWechatTime($timeEnd) : time();

        if (!$outTradeNo || !$transactionId)
            return response('fail', 400); // 参数不完整

        $order = Order::where('out_trade_no', $outTradeNo)->find();
        if (!$order)
            return response('fail', 404); // 订单不存在

        // 防止重复处理
        if ($order->pay_status == 1) {
            Log::info('----notify---- 订单已处理过，跳过：' . $outTradeNo);
            return response('success');
        }

        try {
            // 更新订单状态，使用微信回调的支付时间
            $order->save([
                'pay_status' => 1,
                'transaction_id' => $transactionId,
                'pay_time' => $payTime,
                'wechat_pay_time' => $timeEnd, // 保存原始的微信时间字符串
                'update_time' => time(),
            ]);

            Log::info('----notify---- 订单状态已更新：' . $outTradeNo . '，支付时间：' . $timeEnd);
            return response('success');

        } catch (\Throwable $e) {
            Log::error('----notify---- 支付回调处理失败：' . $e->getMessage());
            return response('fail', 500);
        }
    }

    /*
     * 订单查询
     */
    public function queryOrder() {
        $body = $this->jsonBody;

        $param = [
            'out_trade_no' => self::TRADE_PREFIX . ($body["payid"] ?? ''),
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
            'out_trade_no' => self::TRADE_PREFIX . ($body["payid"] ?? ''),
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

    /**
     * 解析微信时间格式 yyyyMMddHHmmss 为时间戳
     */
    private function parseWechatTime($timeEnd) {
        if (strlen($timeEnd) !== 14) {
            Log::error('----notify---- 微信时间格式错误：' . $timeEnd);
            return time();
        }

        try {
            // 解析 yyyyMMddHHmmss 格式
            $year = substr($timeEnd, 0, 4);
            $month = substr($timeEnd, 4, 2);
            $day = substr($timeEnd, 6, 2);
            $hour = substr($timeEnd, 8, 2);
            $minute = substr($timeEnd, 10, 2);
            $second = substr($timeEnd, 12, 2);

            $timestamp = mktime(
                (int)$hour,
                (int)$minute, 
                (int)$second,
                (int)$month,
                (int)$day,
                (int)$year
            );

            Log::info("----notify---- 微信支付时间解析：{$timeEnd} -> {$timestamp} (" . date('Y-m-d H:i:s', $timestamp) . ")");
            
            return $timestamp;

        } catch (\Throwable $e) {
            Log::error('----notify---- 微信时间解析失败：' . $e->getMessage());
            return time();
        }
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
