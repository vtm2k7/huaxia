<?php

namespace app\controller;

use think\Request;
use think\facade\Db;

class Bury extends Base {
    public function index() {

        $data = $this->jsonBody;

        // 校验必填字段
        if (empty($data['name']) || empty($data['mobile']) || empty($data['burialName']) || empty($data['burialNum']) || empty($data['want_day']) || empty($data['want_time'])) {
            return json(['error' => '参数不完整'], 400);
        }
        
        $insertData = [
            'openid' => $this->openid,
            'name' => $data['name'],
            'mobile' => $data['mobile'],
            'b_name' => $data['burialName'],
            'b_tomb' => $data['burialNum'],
            'want_day' => $data['want_day'],
            'want_time' => $data['want_time'],
            'create_time' => time(),
            'update_time' => time(),
            'del_flg' => 0,
        ];

        // 远程推送发邮件
        $t = 'bury';
        $str = $this->openid.'_'.$t.'_.';
        $key = md5($str);
        $targetUrl = 'http://huaxia.ad-wizard.cn/mini/mail?t='.$t.'&key='.$key;
        $this->remoteRequest($targetUrl, $insertData);

        // 插入数据库
        $rst['code'] = 200;
        try {
            Db::name('bury')->insertGetId($insertData);
        } catch (\Exception $e) {
            $rst['code'] = 300;
            $rst['msg'] = '数据库写入失败';// . $e->getMessage()
        }
        return json($rst);
    }

    public function getUsedSlots() {
        $data = $this->jsonBody;

        if (empty($data['want_day'])) {
            return json(['error' => '缺少参数 want_day'], 400);
        }

        $wantDay = $data['want_day'];

        // 查出当天各时段已预约人数
        $list = Db::name('bury')
            ->field('want_time, count(*) as used')
            ->where('want_day', $wantDay)
            ->where('del_flg', 0)
            ->group('want_time')
            ->select()
            ->toArray();

        return json([
            'code' => 200,
            'data' => $list
        ]);
    }
}
