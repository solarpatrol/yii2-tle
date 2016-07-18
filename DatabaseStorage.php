<?php

namespace solarpatrol\tle;

class DatabaseStorage extends Storage
{
    protected $db;

    public function init()
    {
        // TODO: add option to choose database
        $this->db = \Yii::$app->db;
        parent::init();
    }

    public function exists($id, $timestamp)
    {
        return Tle::find()
            ->where([
                'satellite_id' => $id,
                'epoch_time' => gmdate('c', $timestamp)
            ])
            ->exists();
    }

    public function add($id, $line1, $line2)
    {
        $tle = new Tle([
            'satellite_id' => $id,
            'epoch_time' => gmdate('c', $this->getEpochTimestamp($line1)),
            'line_1' => $line1,
            'line_2' => $line2
        ]);
        return $tle->save(false);
    }

    public function getRange($id, $startTimestamp, $endTimestamp)
    {
        $tles = Tle::find()
            ->select([
                'id' => 'satellite_id',
                'timestamp' => 'epoch_time',
                'line1' => 'line_1',
                'line2' => 'line_2',
            ])
            ->where(['satellite_id' => $id])
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

    public function remove($id, $timestamp)
    {
        $tle = Tle::find()
            ->where([
                'satellite_id' => $id,
                'epoch_time' => gmdate('c', $timestamp)
            ])
            ->one();
        return $tle->delete() !== false ? true : false;
    }
}