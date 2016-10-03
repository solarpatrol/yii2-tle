<?php

namespace solarpatrol\tle;

use solarpatrol\tle\models\Tle;
use yii\base\Exception;
use yii\helpers\FileHelper;

class FileStorage extends Storage
{
    /**
     * @var string path to TLEs' storage directory.
     */
    public $storagePath = '@runtime/tle';

    /**
     * @var int the permission to be set for created directories.
     */
    public $dirMode = 0775;

    /**
     * @var int the permission to be set for created files.
     */
    public $fileMode;

    /**
     * @inheritdoc
     * @throws Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function init()
    {
        $this->storagePath = \Yii::getAlias($this->storagePath);
        if (!is_dir($this->storagePath)) {
            if (!@FileHelper::createDirectory($this->storagePath, $this->dirMode)) {
                throw new Exception('Unable to create TLE storage directory "' . $this->storagePath . '"');
            }
        }
        parent::init();
    }

    /**
     * @inheritdoc
     */
    public function exists(&$tle)
    {
        $id = self::getNoradId($tle);
        $timestamp = self::getEpochTimestamp($tle);
        return is_file($this->getFilePath($id, $timestamp));
    }

    /**
     * @inheritdoc
     */
    public function add(&$tle)
    {
        if ($tle instanceof Tle) {
            $tle = $tle->getTleArray();
        }

        $id = $this->getNoradId($tle);
        $timestamp = $this->getEpochTimestamp($tle);

        $directoryPath = $this->getDirectoryPath($id, $timestamp);
        if (!is_dir($directoryPath)) {
            if (!@FileHelper::createDirectory($directoryPath, $this->dirMode)) {
                throw new Exception('Unable to create directory "' . $directoryPath . "'");
            }
        }

        return $this->write($this->getFilePath($id, $timestamp), $tle);
    }

    /**
     * @inheritdoc
     */
    public function get($id, $startTime, $endTime)
    {
        $asArray = is_array($id);
        $ids = $asArray ? $id : [$id];

        $startTimestamp = self::timestamp($startTime);
        $endTimestamp = self::timestamp($endTime);

        $result = [];

        for ($timestamp = ($startTimestamp - $startTimestamp % (24 * 60 * 60)); $timestamp <= $endTimestamp; $timestamp += 24 * 60 * 60) {
            foreach ($ids as $id) {
                $tles = $this->getAllForDay($id, $timestamp);
                foreach ($tles as $tle) {
                    $epochTimestamp = self::getEpochTimestamp($tle);
                    if ($startTimestamp > $epochTimestamp || $endTimestamp < $epochTimestamp) {
                        continue;
                    }

                    if ($asArray) {
                        if (!isset($result[$id])) {
                            $result[$id] = [];
                        }
                        $result[$id][] = $tle;
                    }
                    else {
                        $result[] = $tle;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @inheritdoc
     */
    public function remove(&$tle)
    {
        $id = self::getNoradId($tle);
        $timestamp = self::getEpochTimestamp($tle);
        return $this->delete($this->getFilePath($id, $timestamp));
    }

    /**
     * Gets all TLEs within a single day.
     * 
     * @param int $id satellite's NORAD identifier.
     * @param int $timestamp Unix timestamp belonging to the day of interest.
     * @return array
     */
    protected function getAllForDay($id, $timestamp)
    {
        $tles = [];

        $directoryPath = $this->getDirectoryPath($id, $timestamp);
        $files = $this->getDirectoryFilesList($id, $timestamp);

        foreach ($files as $file) {
            $filePath = sprintf('%s%s%s', $directoryPath, DIRECTORY_SEPARATOR, $file);
            $tle = $this->read($filePath);
            if ($tle) {
                $tles[] = $tle;
            }
        }

        return $tles;
    }

    /**
     * Gets TLE from a file.
     * 
     * @param string $filePath path to TLE file.
     * @return array|bool
     */
    protected function read($filePath)
    {
        $fh = fopen($filePath, 'r');
        if ($fh === false) {
            return false;
        }

        if (!flock($fh, LOCK_SH)) {
            fclose($fh);
            return false;
        }

        $result = fgets($fh);

        flock($fh, LOCK_UN);
        fclose($fh);

        return $result !== false ? json_decode($result) : false;
    }

    /**
     * Puts TLE in a file.
     * 
     * @param string $filePath path to TLE file.
     * @param array $tle
     * @return bool
     */
    protected function write($filePath, &$tle)
    {
        $fh = fopen($filePath, 'w');
        if ($fh === false) {
            return false;
        }

        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            return false;
        }

        fwrite($fh, json_encode($tle));

        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);

        if (isset($this->fileMode)) {
            @chmod($filePath, $this->fileMode);
        }

        return true;
    }

    /**
     * Removes TLE file.
     * 
     * @param string $filePath path to TLE file.
     * @return bool
     */
    protected function delete($filePath)
    {
        $fh = fopen($filePath, 'r');
        if ($fh === false) {
            return false;
        }

        $result = false;
        $attempts = 0;
        while ($attempts < 10) {
            if (!flock($fh, LOCK_EX | LOCK_NB)) {
                sleep(0.1);
                $attempts += 1;
            }
            else {
                flock($fh, LOCK_UN);
                $result = unlink($filePath);
                break;
            }
        }

        fclose($fh);
        return $result;
    }

    /**
     * Gets path to satellite's root TLE directory. 
     * 
     * @param int $id satellite's NORAD identifier.
     * @return string
     */
    protected function getSatellitePath($id)
    {
        return sprintf('%s%s%05d', $this->storagePath, DIRECTORY_SEPARATOR, $id);
    }

    /**
     * Gets path to satellite's TLE directory for specific day. 
     * 
     * @param int $id satellite's NORAD identifier.
     * @param int $timestamp Unix timestamp belonging to the day of interest.
     * @return string
     */
    protected function getDirectoryPath($id, $timestamp)
    {
        return sprintf('%s%s%s%s%s%s%s', $this->getSatellitePath($id),
            DIRECTORY_SEPARATOR, gmdate('Y', $timestamp),
            DIRECTORY_SEPARATOR, gmdate('m', $timestamp),
            DIRECTORY_SEPARATOR, gmdate('d', $timestamp)
        );
    }

    /**
     * Gets list of TLE files in satellite's directory for specific day.
     * 
     * @param int $id satellite's NORAD identifier.
     * @param int $timestamp Unix timestamp belonging to the day of interest.
     * @return array
     */
    protected function getDirectoryFilesList($id, $timestamp)
    {
        $directoryPath = $this->getDirectoryPath($id, $timestamp);
        if (!is_dir($directoryPath)) {
            return [];
        }

        $files = [];
        foreach (scandir($directoryPath) as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $files[] = $file;
        };

        return $files;
    }

    /**
     * Gets path to TLE file. 
     * 
     * @param int $id satellite's NORAD identifier.
     * @param int $timestamp Unix timestamp of TLE.
     * @return string
     */
    protected function getFilePath($id, $timestamp)
    {
        return sprintf('%s%s%s-%s-%s.json', $this->getDirectoryPath($id, $timestamp),
            DIRECTORY_SEPARATOR, gmdate('H', $timestamp), gmdate('i', $timestamp), gmdate('s', $timestamp)
        );
    }

    /**
     * Parses satellite's NORAD identifier and time from file's path.
     * 
     * @param string $filePath path to TLE file.
     * @return array|bool
     */
    protected static function parseFilePath($filePath)
    {
        $parts = explode(DIRECTORY_SEPARATOR, $filePath);
        if (count($parts) < 5) {
            return false;
        }

        $timeMatch = [];
        if (!preg_match('/^(\d+)-(\d+)-(\d+)\.tle$/', $parts[count($parts) - 1], $timeMatch)) {
            return false;
        }

        $result = [
            'id' => intval($parts[count($parts) - 5]),
            'year' => intval($parts[count($parts) - 4]),
            'month' => intval($parts[count($parts) - 3]),
            'day' => intval($parts[count($parts) - 2]),
            'hour' => intval($timeMatch[1]),
            'minute' => intval($timeMatch[2]),
            'second' => intval($timeMatch[3])
        ];

        $result['timestamp'] = gmmktime(
            $result['hour'],
            $result['minute'],
            $result['second'],
            $result['month'],
            $result['day'],
            $result['year']
        );

        return $result;
    }
}