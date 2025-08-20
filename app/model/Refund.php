<?php

namespace app\model;

use think\Model;

class Refund extends Model {
    protected $pk = 'id';

    // 关联订单
    public function order() {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
