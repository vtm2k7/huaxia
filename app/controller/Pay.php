<?php

namespace app\controller;

use app\model\Order;
use think\facade\Request;
use think\facade\Log;

class Pay extends Base {
    const TRADE_PREFIX = '2025HX';
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
        $outTradeNo = self::TRADE_PREFIX . $payid;

        // 1. 插入订单
        Order::create([
            'openid' => $this->openid,
            'out_trade_no' => $outTradeNo,
            'total_fee' => $fee,
            'product_name' => $body["paytext"] ?? '微信支付.',
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
            'body' => $body["paytext"] ?? "微信支付.",
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
        Log::info('----unifiedOrder----' .  json_encode($param));

        return $this->sendRequest('http://api.weixin.qq.com/_/pay/unifiedOrder', $param);
    }

    /*
    * 接受微信的回调
    */
    public function notify() {
        $data = json_decode(file_get_contents('php://input'), true);

        Log::info('----notify----' . json_encode($data));

        // 必须是支付成功才处理
        if (($data['resultCode'] ?? '') !== 'SUCCESS') {
            return response('fail', 400);
        }

        $openid = $data['openid'];
        $outTradeNo = $data['outTradeNo'] ?? '';
        $transactionId = $data['transactionId'] ?? '';

        if (!$outTradeNo || !$transactionId) {
            return response('fail', 400); // 参数不完整
        }

        $order = Order::where('out_trade_no', $outTradeNo)->find();
        if (!$order) {
            return response('fail', 404); // 订单不存在
        }

        // 已处理过
       /* if ($order->pay_status == 1) {
            return response('success.1'); // ✅ 告诉微信“我已经处理好了，不用再来了”
        }*/

        // 开始处理
        try {
            // 更新远程维护记录
            $targetUrl = 'http://huaxia.ad-wizard.cn/mini/updateFee';
            // 我的remoteRequest会强奸一个openid，所以这里回调要换个名字
            $remote = $this->remoteRequest($targetUrl, [
                'openid2' => $openid,
                'transaction_id' => $transactionId
            ]);
            $remoteStr = $remote->getContent();
            $remoteRst = json_decode($remoteStr, true);
            if ($remoteRst['code'] == 200) {
                $order->save([
                    'pay_status' => 1,
                    'transaction_id' => $transactionId,
                    'pay_time' => time(),
                    'update_time' => time(),
                ]);
                // ✅ 成功处理
                return response('success.2');
            } else if ($remoteRst['code'] == 304) {
                // 已经更新过了，也算成功
                return response('success.3');
            } else {
                // ❌ 数据库保存失败，让微信重试
                Log::error("交费记录远端更新失败：" . $remoteRst['msg']);
                echo "交费记录远端更新失败：" . $remoteRst['msg'];
                return response('fail.2', 500); //
            }
        } catch (\Throwable $e) {
            Log::error("支付回调保存失败：" . $e->getMessage());
            return response('fail.1', 500); // ❌ 数据库保存失败，让微信重试
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
