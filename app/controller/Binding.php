<?php
namespace app\controller;

use think\Request;

class Binding extends Base {

    /*
     * 转发绑定请求
     */
    public function index(Request $request) {
        $targetUrl = 'http://huaxia.ad-wizard.cn/mini/binding';
        return $this->remoteRequest($targetUrl, $this->jsonBody);
    }
}
