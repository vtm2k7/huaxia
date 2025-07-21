<?php
namespace app\controller;

use think\Request;

class Binding extends Base {

    /*
     * 绑定
     */
    public function index(Request $request) {
        $targetUrl = 'http://huaxia.ad-wizard.cn/mini/binding';
        return $this->remoteRequest($targetUrl, $this->jsonBody);
    }

    /*
     * 解绑
     */
    public function release(Request $request) {
        $targetUrl = 'http://huaxia.ad-wizard.cn/mini/unbinding';
        return $this->remoteRequest($targetUrl, $this->jsonBody);
    }

}
