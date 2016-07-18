<?php

namespace solarpatrol\tle;

use yii\base\Exception;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;

class FileStorage extends Storage
{
    public $storagePath = '@runtime/tle';
    public $dirMode = 0775;
    public $fileMode;

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

    public function exists($id, $timestamp)
    {
        return is_file($this->getFilePath($id, $timestamp));
    }

    public function add($id, $line1, $line2)
    {
        $timestamp = $this->getEpochTimestamp($line1);
        $directoryPath = $this->getDirectoryPath($id, $timestamp);
        if (!is_dir($directoryPath)) {
            if (!@FileHelper::createDirectory($directoryPath, $this->dirMode)) {
                throw new Exception('Unable to create directory "' . $directoryPath . "'");
            }
        }

        return $this->write($this->getFilePath($id, $timestamp), $line1, $line2);
    }

    public function getRange($id, $startTimestamp, $endTimestamp)
    {
        $result = [];

        for ($time = ($startTimestamp - $startTimestamp % (24 * 60 * 60)); $time <= $endTimestamp; $time += 24 * 60 * 60) {
            $tles = $this->getAllForDay($id, $time);
            foreach ($tles as $tle) {
                if ($startTimestamp > $tle['timestamp'] || $endTimestamp < $tle['timestamp']) {
                    continue;
                }
                $result[] = [
                    'id' => $tle['id'],
                    'timestamp' => $tle['timestamp'],
                    'line1' => $tle['line1'],
                    'line2' => $tle['line2']
                ];
            }
        }

        return $result;
    }

    public function remove($id, $timestamp)
    {
        return $this->delete($this->getFilePath($id, $timestamp));
    }

    protected function getAllForDay($id, $time)
    {
        $result = [];

        $directoryPath = $this->getDirectoryPath($id, $time);
        $files = $this->getDirectoryFilesList($id, $time);

        foreach ($files as $file) {
            $filePath = sprintf('%s%s%s', $directoryPath, DIRECTORY_SEPARATOR, $file);
            $tle = $this->read($filePath);
            if ($tle) {
                $result[] = $tle;
            }
        }

        return $result;
    }

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

        $result = [
            'line1' => fgets($fh),
            'line2' => fgets($fh)
        ];
        flock($fh, LOCK_UN);
        fclose($fh);

        if ($result['line1'] === false || $result['line2'] === false) {
            return false;
        }

        $result['line1'] = substr($result['line1'], 0, self::TLE_LINE_LENGTH);
        $result['line2'] = substr($result['line2'], 0, self::TLE_LINE_LENGTH);

        $info = self::parseFilePath($filePath);
        return ArrayHelper::merge($info ? $info : [], $result);
    }

    protected function write($filePath, $line1, $line2)
    {
        $fh = fopen($filePath, 'w');
        if ($fh === false) {
            return false;
        }

        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            return false;
        }

        fwrite($fh, substr($line1, 0, self::TLE_LINE_LENGTH) . "\n");
        fwrite($fh, substr($line2, 0, self::TLE_LINE_LENGTH));

        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);

        if (isset($this->fileMode)) {
            @chmod($filePath, $this->fileMode);
        }

        return true;
    }

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

    protected function getSatellitePath($id)
    {
        return sprintf('%s%s%05d', $this->storagePath, DIRECTORY_SEPARATOR, $id);
    }

    protected function getDirectoryPath($id, $time)
    {
        return sprintf('%s%s%s%s%s%s%s', $this->getSatellitePath($id),
            DIRECTORY_SEPARATOR, gmdate('Y', $time),
            DIRECTORY_SEPARATOR, gmdate('m', $time),
            DIRECTORY_SEPARATOR, gmdate('d', $time)
        );
    }

    protected function getDirectoryFilesList($id, $time)
    {
        $directoryPath = $this->getDirectoryPath($id, $time);
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

    protected function getFilePath($id, $time)
    {
        return sprintf('%s%s%s-%s-%s.tle', $this->getDirectoryPath($id, $time),
            DIRECTORY_SEPARATOR, gmdate('H', $time), gmdate('i', $time), gmdate('s', $time)
        );
    }

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