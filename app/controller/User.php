<?php

namespace app\controller;

class User extends Base {

    /*
     * 绑定
     */
    public function binding() {
        $targetUrl = 'http://huaxia.ad-wizard.cn/mini/binding';
        return $this->remoteRequest($targetUrl, $this->jsonBody);
    }

    /*
     * 解绑
     */
    public function unbinding() {
        $targetUrl = 'http://huaxia.ad-wizard.cn/mini/unbinding';
        return $this->remoteRequest($targetUrl, $this->jsonBody);
    }

}