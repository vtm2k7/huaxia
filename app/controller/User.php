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

    /*
     * 保存用户昵称
     */
    public function saveNickname() {
        $openid = $this->openid;

        $data = $this->jsonBody;
        $nickName = $data['nick_name'];
        // 写入数据库
        $user = User::where('openid', $openid)->find();
        if ($user) {
            $user->nick_name = $nickName;
            $user->save();
        } else {
            User::create([
                'openid' => $openid,
                'nick_name' => $nickName
            ]);
        }

        $rst['code'] = 200;
        return json($rst);
    }

    /*
     * 保存用户头像
     */
    public function saveAvatarUrl() {
        $openid = $this->openid;
        $data = $this->jsonBody;

        $avatarUrl = $data['avatar_url'];


        // 写入数据库
        $user = User::where('openid', $openid)->find();
        if ($user) {
            $user->avatar_url = $avatarUrl;
            $user->save();
        } else {
            User::create([
                'openid' => $openid,
                'avatar_url' => $avatarUrl,
            ]);
        }

        $rst['code'] = 200;
        return json($rst);
    }
}
