<?php

namespace app\controller;

use app\model\Order;
use app\model\Refund;
use think\facade\Db;
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
        $fee = $body['fee'];

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

    /**
     * 发起退款
     */
    public function refund() {
        $body = $this->jsonBody;

        // 只需要订单号即可
        if (empty($body['out_trade_no'])) {
            return json([
                'success' => false,
                'message' => '缺少订单号'
            ], 400);
        }

        // 查询原订单
        $order = Order::where('out_trade_no', $body['out_trade_no'])->find();
        if (!$order) {
            return json([
                'success' => false,
                'message' => '订单不存在'
            ], 404);
        }

        // 检查订单状态
        if ($order->pay_status != 1) {
            return json([
                'success' => false,
                'message' => '订单未支付'
            ], 400);
        }

        // 生成退款单号
        $outRefundNo = 'RF' . self::TRADE_PREFIX . uniqid();

        // 构建退款参数 - 云托管免token调用
        $param = [
            'out_trade_no' => $order->out_trade_no,    // 原订单号
            'out_refund_no' => $outRefundNo,           // 退款单号
            'sub_mch_id' => $this->mchid,              // 子商户号
            'total_fee' => $order->total_fee,          // 原订单金额
            'refund_fee' => $order->total_fee,         // 退款金额（全额退款）
            'callback_type' => 2,                      // 云托管回调
            'container' => [
                'service' => 'thinkphp-nginx-dq0y',
                'path' => '/pay/refundNotify'
            ]
        ];

        Log::info('----refund---- 发起全额退款：' . json_encode([
                'out_trade_no' => $order->out_trade_no,
                'total_fee' => $order->total_fee
            ]));

        return $this->sendRequest('http://api.weixin.qq.com/_/pay/refund', $param);
    }

    /**
     * 接收退款结果通知
     */
    public function refundNotify() {
        $data = json_decode(file_get_contents('php://input'), true);
        Log::info('----refund-notify----' . json_encode($data));

        // 微信云托管的退款回调格式验证
        if (($data['returnCode'] ?? '') !== 'SUCCESS' || ($data['resultCode'] ?? '') !== 'SUCCESS') {
            Log::error('----refund-notify---- 退款未成功：' . json_encode([
                    'returnCode' => $data['returnCode'] ?? '',
                    'resultCode' => $data['resultCode'] ?? ''
                ]));
            return response('fail');
        }

        $outTradeNo = $data['outTradeNo'] ?? '';
        $outRefundNo = $data['outRefundNo'] ?? '';
        $refundFee = $data['refundFee'] ?? 0;
        $successTime = $data['successTime'] ?? date('YmdHis');

        if (!$outTradeNo || !$outRefundNo) {
            Log::error('----refund-notify---- 参数不完整');
            return response('fail');
        }

        // 查询原订单
        $order = Order::where('out_trade_no', $outTradeNo)->find();
        if (!$order) {
            Log::error('----refund-notify---- 订单不存在：' . $outTradeNo);
            return response('fail');
        }

        try {
            Db::startTrans();

            // 1. 更新订单退款状态
            $order->save([
                'refund_status' => 1,
                'refund_time' => $this->parseWechatTime($successTime),
                'refund_fee' => $refundFee,
                'update_time' => time()
            ]);

            // 2. 记录退款详情
            Refund::create([
                'order_id' => $order->id,
                'out_trade_no' => $outTradeNo,
                'out_refund_no' => $outRefundNo,
                'total_fee' => $order->total_fee,
                'refund_fee' => $refundFee,
                'refund_status' => 1,
                'refund_time' => $this->parseWechatTime($successTime),
                'create_time' => time(),
                'update_time' => time()
            ]);

            Db::commit();

            Log::info('----refund-notify---- 退款处理成功：' . json_encode([
                    'out_trade_no' => $outTradeNo,
                    'out_refund_no' => $outRefundNo,
                    'refund_fee' => $refundFee
                ]));

            return response('success');

        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('----refund-notify---- 退款处理失败：' . $e->getMessage());
            return response('fail');
        }
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

            $timestamp = mktime((int)$hour, (int)$minute, (int)$second, (int)$month, (int)$day, (int)$year);

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
