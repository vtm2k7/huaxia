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

    public function history(){
        $openid = $this->openid;

        // 定义服务类型映射
        $serviceTypes = [
            1 => '落葬服务',
            2 => '购墓咨询',
            3 => '代客祭扫',
            4 => '盆花租摆',
            5 => '墓碑保洁',
            6 => '其他服务'
        ];
        
        $list1 = Db::name('appointment')->where('openid', $openid)->order('create_time', 'desc')->select()->toArray();
        // 处理预约记录
        $list1 = array_map(function($item) use ($serviceTypes) {
            $item['create_time_text'] = date('Y-m-d H:i:s', $item['create_time']);
            $item['service_text'] = $serviceTypes[$item['services']] ?? '未知服务';
            return $item;
        }, $list1);

        $list2 = Db::name('bury')->where('openid', $openid)->order('create_time', 'desc')->select()->toArray();
        // 处理落葬记录，统一添加服务类型
        $list2 = array_map(function($item) {
            $item['create_time_text'] = date('Y-m-d H:i:s', $item['create_time']);
            $item['services'] = 1; // 落葬固定为1
            $item['service_text'] = '落葬';
            return $item;
        }, $list2);

         $finalList = array_merge($list1, $list2);
        // 根据create_time排序
        usort($finalList, function($a, $b) {
            return $b['create_time'] - $a['create_time'];
        });

        $rst['code'] = 200;
        $rst['data'] = $finalList;
        return json($rst);
    }

}
