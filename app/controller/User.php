<?php
namespace app\controller;

use think\Request;

class User extends Base {
    public function index(Request $request) {
        $targetUrl = 'http://huaxia.ad-wizard.cn/mini/info';
        return $this->remoteRequest($targetUrl, $this->jsonBody);
    }

}
