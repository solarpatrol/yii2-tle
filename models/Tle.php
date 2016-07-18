<?php

namespace solarpatrol\tle\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

class Tle extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%tle}}';
    }

    public function behaviors()
    {
        return [[
            'class' => TimestampBehavior::className(),
            'createdAtAttribute' => null,
            'updatedAtAttribute' => 'updated_at'
        ]];
    }
}