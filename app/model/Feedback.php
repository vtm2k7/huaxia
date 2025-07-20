<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class Appointment extends Model {
    // 指定表名（TP 默认类名小写绑定，可省略）
    protected $name = 'order';

    // 主键（默认是 id，也可省略）
    protected $pk = 'id';

    // 关闭自动时间戳（你是 int 类型）
    protected $autoWriteTimestamp = false;

    // 启用逻辑删除字段（del_flg = 1 表示软删除）
    protected $defaultSoftDelete = 1;

    // 字段类型转换（可选）
    protected $type = [
        'id' => 'integer',
        'total_fee' => 'integer',
        'pay_status' => 'integer',
        'pay_time' => 'integer',
        'create_time' => 'integer',
        'update_time' => 'integer',
        'del_flg' => 'integer',
    ];

    // 默认字段值（插入时可自动补全）
    protected $insert = [
        'pay_status' => 0,
        'create_time' => 0,
        'update_time' => 0,
        'del_flg' => 0,
    ];

    /**
     * 设置逻辑删除字段（需要 TP 支持软删除插件或你自己处理）
     * 若不使用插件，这里保留 del_flg 字段逻辑判断即可
     */
}
