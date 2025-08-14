<?php

namespace app\controller;

use think\facade\Db;

class Check extends Base {

    public function baseInfo(){
        $openid = $this->openid;
        $user = Db::name('user')->field('nick_name,avatar_url')->where('openid', $openid)->find();
        $rst['code'] = 200;
        $rst['data'] = $user;
        return json($rst);
    }
}
