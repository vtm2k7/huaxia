<?php

namespace app\controller;

class Check extends Base {
    public function userInfo() {
        $targetUrl = 'http://huaxia.ad-wizard.cn/mini/userInfo';
        return $this->remoteRequest($targetUrl, $this->jsonBody);
    }

    public function maintenanceInfo() {
        $targetUrl = 'http://huaxia.ad-wizard.cn/mini/maintenanceInfo';
        return $this->remoteRequest($targetUrl, $this->jsonBody);
    }
}
