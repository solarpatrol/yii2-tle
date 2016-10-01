<?php

namespace solarpatrol\tle;

use solarpatrol\tle\models\Tle;
use yii\di\Instance;

class DatabaseStorage extends Storage
{
    /**
     * @var string|array|\yii\db\Connection name, configuration or instance of database connection.
     */
    public $db = 'db';

    /**
     * @inheritdoc
     * @throws \yii\base\InvalidConfigException
     */
    public function init()
    {
        parent::init();
        $this->db = Instance::ensure($this->db);
    }

    /**
     * @inheritdoc
     */
    public function exists(&$tle)
    {
        $id = self::getNoradId($tle);
        $timestamp = self::getEpochTimestamp($tle);
        return Tle::find()
            ->where([
                'norad_id' => $id,
                'epoch_time' => gmdate('c', $timestamp)
            ])
            ->exists();
    }

    /**
     * @inheritdoc
     */
    public function add(&$tle)
    {
        if (!($tle instanceof Tle)) {
            $tle = new Tle([
                'norad_id' => self::getNoradId($tle),
                'name' => self::getName($tle),
                'epoch_time' => gmdate('c', self::getEpochTimestamp($tle)),
                'line_1' => $tle[1],
                'line_2' => $tle[2]
            ]);
        }
        return $tle->save(false);
    }

    /**
     * @inheritdoc
     */
    public function get($id, $startTime, $endTime)
    {
        $startTimestamp = self::timestamp($startTime);
        $endTimestamp = self::timestamp($endTime);

        $tles = Tle::find()
            ->select([
                'id' => 'norad_id',
                'timestamp' => 'epoch_time',
                'line1' => 'line_1',
                'line2' => 'line_2',
            ])
            ->where(['norad_id' => $id])
            ->andWhere(['>=', 'epoch_time', gmdate('c', $startTimestamp)])
            ->andWhere(['<=', 'epoch_time', gmdate('c', $endTimestamp)])
            ->orderBy(['epoch_time' => SORT_ASC])
            ->asArray()
            ->all();

        foreach ($tles as $i => $tle) {
            $tles[$i]['timestamp'] = strtotime($tle['timestamp']);
        }

        return $tles;
    }

    /**
     * @inheritdoc
     */
    public function remove(&$tle)
    {
        if (!($tle instanceof Tle)) {
            $id = self::getNoradId($tle);
            $timestamp = self::getEpochTimestamp($tle);
            $tle = Tle::find()
                ->where([
                    'norad_id' => $id,
                    'epoch_time' => gmdate('c', $timestamp)
                ])
                ->one();
        }
        return $tle->delete() !== false ? true : false;
    }
}