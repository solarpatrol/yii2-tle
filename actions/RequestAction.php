<?php

namespace solarpatrol\tle\actions;

use solarpatrol\tle\Module;
use solarpatrol\tle\Storage;
use yii\base\Action;

class RequestAction extends Action
{
    public function run(array $id, $startTime = null, $endTime = null)
    {
        $download = \Yii::$app->request->get('download') ? true : false;
        $ids = is_array($id) ? $id : [$id];

        /* @var Storage $storage */
        $storage = Module::getInstance()->storage;

        $startTimestamp = Storage::timestamp($startTime);
        $endTimestamp = Storage::timestamp($endTime);
        if ($startTime === null || $startTimestamp >= $endTimestamp) {
            $startTimestamp = $endTimestamp - $storage->actualDaysCount * Storage::SECONDS_PER_DAY;
        }

        $tles = $storage->get($ids, $startTimestamp, $endTimestamp);
        if ($download) {
            $foundIds = array_keys($tles);
            $missedIds = array_values(array_diff($ids, $foundIds));
            $storage->update($missedIds, $startTimestamp, $endTimestamp);
            $tles = $storage->get($ids, $startTimestamp, $endTimestamp);
        }

        \Yii::$app->response->format = 'json';
        return $tles;
    }
}
