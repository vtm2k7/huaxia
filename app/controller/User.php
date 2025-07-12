<?php
namespace app\controller;

use think\Request;

class User
{
    public function info(Request $request)
    {
        // 从请求 header 里拿 openid
        $openid = $request->header('x-wx-openid');

        if (!$openid) {
            return json(['error' => 'no openid'], 400);
        }

        return json([
            'openid' => $openid
        ]);
    }
}
