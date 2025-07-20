<?php

namespace app\controller;

use think\Request;
use think\facade\Db;

class Appointment extends Base {
    public function index() {

        $data = $this->jsonBody;

        // 校验必填字段
        if (empty($data['name']) || empty($data['mobile']) || empty($data['services']) || empty($data['want_day']) || empty($data['want_time'])) {
            return json(['error' => '参数不完整'], 400);
        }
        // services 拼成逗号分隔字符串
        $services = is_array($data['services']) ? implode(',', $data['services']) : $data['services'];

        $insertData = [
            'openid' => $this->openid,
            'name' => $data['name'],
            'mobile' => $data['mobile'],
            'services' => $services,
            'want_day' => $data['want_day'],
            'want_time' => $data['want_time'],
            'create_time' => time(),
            'update_time' => time(),
            'del_flg' => 0,
        ];

        // 插入数据库
        $rst['code'] = 200;
        try {
            Db::name('appointment')->insertGetId($insertData);
        } catch (\Exception $e) {
            $rst['code'] = 300;
            $rst['msg'] = '数据库写入失败';// . $e->getMessage()
        }
        return json($rst);
    }
}
