<?php
namespace app\controller;

use think\Request;
use think\Response;

class Base {
    protected $openid = null;
    protected $headers = null;
    protected $jsonBody = null;

    public function __construct(Request $request) {
        $this->headers = array_change_key_case($request->header(), CASE_LOWER);
        $this->openid = $request->header('x-wx-openid');
        if (!$this->openid)
            return json(['error' => 'no openid'], 400);

        // 统一处理 JSON 请求体（兼容 application/json）
        $contentType = strtolower($request->contentType() ?? '');
        if ($contentType === 'application/json') {
            $raw = $request->getInput(); // 这是 TP6 的推荐写法
            $this->jsonBody = json_decode($raw, true) ?? [];
        } else {
            // 表单或其它情况
            $this->jsonBody = $request->post();
        }
    }

    /**
     * 私有封装的转发 JSON 请求
     *
     * @param string $url
     * @param array $body
     * @return Response
     */
    protected function remoteRequest(string $url, array $body = []): Response {

        // 强行插入一个openId
        $body['openid'] = $this->openid;

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
