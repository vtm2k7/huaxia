<?php
namespace app\model;

use think\Model;

class Feedback extends Model
{
    // 对应表名
    protected $name = 'feedback';

    // 主键字段
    protected $pk = 'id';

    // 不启用自动时间戳（你使用的是 int 类型）
    public $autoWriteTimestamp = false;

    // 字段类型转换
    protected $type = [
        'id'            => 'integer',
        'openid'        => 'string',
        'content'       => 'string',
        'create_time'   => 'integer',
        'update_time'   => 'integer',
        'del_flg'       => 'integer',
    ];
}
