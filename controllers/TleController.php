<?php

namespace solarpatrol\tle\controllers;

use yii\web\Controller;

class TleController extends Controller
{
    public function actions()
    {
        return [
            'request' => 'solarpatrol\tle\actions\RequestAction'
        ];
    }
}
