<?php

namespace solarpatrol\tle\commands;

use solarpatrol\tle\Module;
use solarpatrol\tle\Storage;
use yii\base\Exception;
use yii\console\Controller;

class UpdateController extends Controller
{
    const DEFAULT_DAYS_COUNT = 5;

    public $startTime;
    public $endTime;

    public function options($actionId)
    {
        $options = parent::options($actionId);
        return array_merge($options, ['startTime', 'endTime']);
    }

    public function actionIndex()
    {
        $module = Module::getInstance();

        $ids = func_get_args();

        if (empty($ids)) {
            $this->stdout(Module::t('Satellites\' NORAD identifiers are not specified.') . "\n");
            return self::EXIT_CODE_NORMAL;
        }

        /* @var Storage $storage */
        $storage = $module->storage;

        $startTimestamp = Storage::timestamp($this->startTime);
        $endTimestamp = Storage::timestamp($this->endTime);

        if ($startTimestamp === $endTimestamp) {
            $startTimestamp = $endTimestamp - self::DEFAULT_DAYS_COUNT * Storage::SECONDS_PER_DAY;
        }

        try {
            $this->stdout(sprintf(
                Module::t('Downloading TLEs for specified satellites and time range %s — %s...'),
                gmdate('Y-m-d H:i:s', $startTimestamp),
                gmdate('Y-m-d H:i:s', $endTimestamp)
            ) . "\n");
            $tles = $storage->update($ids, $startTimestamp, $endTimestamp);

            $this->stdout("\n" . Module::t('Downloaded TLEs:') . "\n");
            foreach ($tles as $tle) {
                $this->stdout(str_repeat('-', Storage::TLE_LINE_LENGTH) . "\n");
                $this->stdout(gmdate('Y-m-d H:i:s', Storage::getEpochTimestamp($tle)) . "\n");
                foreach ($tle as $line) {
                    $this->stdout($line . "\n");
                }
            }
        }
        catch (Exception $e) {
            $this->stdout($e->getMessage() . "\n");
            return self::EXIT_CODE_ERROR;
        }

        return self::EXIT_CODE_NORMAL;
    }
}