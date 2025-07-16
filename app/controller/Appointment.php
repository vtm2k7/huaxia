<?php
namespace app\controller;

use think\Request;
use think\facade\Db;

class Appointment
{
    public function index(Request $request)
    {

        // 从 header 里取 openid
        $openid = $request->header('x-wx-openid');

        if (!$openid) {
            return json(['error' => 'no openid'], 400);
        }

        // 获取 JSON 请求体
        $data = $request->post();

        // 校验必填字段
        if (empty($data['name']) || empty($data['mobile']) || empty($data['services']) || empty($data['want_day']) || empty($data['want_time'])) {
            return json(['error' => '参数不完整'], 400);
        }

        // services 拼成逗号分隔字符串
        $services = is_array($data['services']) ? implode(',', $data['services']) : $data['services'];

        $insertData = [
            'openid' => $openid,
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
        try {
            $id = Db::name('appointment')->insertGetId($insertData);
            return json([
                'success' => true,
                'id' => $id
            ]);
        } catch (\Exception $e) {
            return json(['error' => '数据库写入失败', 'msg' => $e->getMessage()], 500);
        }
    }
}
