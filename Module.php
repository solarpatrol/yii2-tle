<?php

namespace solarpatrol\tle;

use Yii;
use yii\base\BootstrapInterface;
use \yii\base\Module as BaseModule;
use yii\helpers\ArrayHelper;

class Module extends BaseModule implements BootstrapInterface
{
    public $webControllerId = 'tle';
    public $consoleControllerId = 'tle';

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

    public function init()
    {
        parent::init();
        $this->registerTranslations();
    }

    protected function registerTranslations()
    {
        Yii::$app->i18n->translations['tle'] = [
            'class' => 'yii\i18n\PhpMessageSource',
            'basePath' => __DIR__ . '/messages'
        ];
    }

    public static function t($message, $params = [], $language = null)
    {
        return Yii::t('tle', $message, $params, $language);
    }

    public function bootstrap($app)
    {
        if ($app instanceof \yii\web\Application) {
            if ($this->webControllerId && !isset(\Yii::$app->controllerMap[$this->webControllerId])) {
                \Yii::$app->controllerMap[$this->webControllerId] = \solarpatrol\tle\controllers\TleController::className();
            }
        }

        if ($app instanceof \yii\console\Application) {
            $this->controllerNamespace = 'solarpatrol\tle\commands';

            if ($this->consoleControllerId && !isset(\Yii::$app->controllerMap[$this->consoleControllerId])) {
                \Yii::$app->controllerMap[$this->consoleControllerId] = \solarpatrol\tle\commands\TleController::className();
            }
        }
    }
}