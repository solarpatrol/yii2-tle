<?php

namespace solarpatrol\tle;

use \yii\base\Module as BaseModule;
use yii\helpers\ArrayHelper;

class Module extends BaseModule
{
    public function __construct($id, $parent, array $config = [])
    {
        $config = ArrayHelper::merge([
            'components' => [
                'storage' => [
                    'class' => 'solarpatrol\tle\FileStorage'
                ]
            ]
        ], $config);

        parent::__construct($id, $parent, $config);
    }
}