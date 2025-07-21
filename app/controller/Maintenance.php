<?php

namespace app\controller;

class Maintenance extends Base {

    /*
     * 历史交费记录
     */
    public function history() {
        $targetUrl = 'http://huaxia.ad-wizard.cn/mini/history';
        return $this->remoteRequest($targetUrl, $this->jsonBody);
    }

}
