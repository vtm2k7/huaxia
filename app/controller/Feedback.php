<?php

namespace app\controller;

use think\Request;
use think\facade\Db;

class Feedback extends Base {
    public function index() {
        $data = $this->jsonBody;

        if (empty($data['content'])) {
            return json(['error' => '内容不能为空'], 400);
        }

        $insertData = [
            'openid' => $this->openid,
            'content' => $data['content'],
            'create_time' => time(),
            'del_flg' => 0,
        ];

        // 远程推送发邮件
        $t = 'feedback';
        $str = $this->openid.'_'.$t.'_.';
        $key = md5($str);
        $targetUrl = 'http://huaxia.ad-wizard.cn/mini/mail?t='.$t.'&key='.$key;
        $this->remoteRequest($targetUrl, $insertData);

        // 插入数据库
        $rst['code'] = 200;
        try {
            Db::name('feedback')->insertGetId($insertData);
        } catch (\Exception $e) {
            $rst['code'] = 300;
            $rst['msg'] = '数据库写入失败';// . $e->getMessage()
        }
        return json($rst);
    }
}
