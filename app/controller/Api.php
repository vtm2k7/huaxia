<?php
declare(strict_types=1);

namespace app\controller;

use think\Request;
use think\Response;

class Api {
    public function binder(Request $request): Response {
        $targetUrl = 'http://huaxia.ad-wizard.cn/api2/binder';

        // 获取 header 里的 openid
        $openid = $request->header('x-wx-openid');

        // 获取 JSON 请求体
        $jsonData = $request->getInput();
        $data = json_decode($jsonData, true) ?? [];

        if (!empty($openid)) {
            $data['openid'] = $openid;
        }

        return $this->proxyRequest($targetUrl, $data);
    }

    /**
     * 私有封装的转发 JSON 请求
     *
     * @param string $url
     * @param array $body
     * @return Response
     */
    private function proxyRequest(string $url, array $body = []): Response {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $errorMsg = curl_error($ch);
            curl_close($ch);
            return json([
                'code' => 500,
                'msg' => '转发失败',
                'error' => $errorMsg
            ]);
        }

        curl_close($ch);

        return response($result, $httpCode)->contentType('application/json');
    }
}
