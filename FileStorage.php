<?php

namespace solarpatrol\tle;

use yii\base\Exception;
use yii\helpers\ArrayHelper;
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
    public function exists($id, $timestamp)
    {
        return is_file($this->getFilePath($id, $timestamp));
    }

    /**
     * @inheritdoc
     */
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

    /**
     * @inheritdoc
     */
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

    /**
     * @inheritdoc
     */
    public function remove($id, $timestamp)
    {
        return $this->delete($this->getFilePath($id, $timestamp));
    }

    /**
     * Gets all TLEs within a single day.
     * 
     * @param int $id satellite's NORAD identifier.
     * @param int $time Unix timestamp belonging to the day of interest.
     * @return array
     */
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

    /**
     * Puts TLE in a file.
     * 
     * @param string $filePath path to TLE file.
     * @param string $line1 first line of TLE.
     * @param string $line2 second line of TLE.
     * @return bool
     */
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
     * @param int $time Unix timestamp belonging to the day of interest.
     * @return string
     */
    protected function getDirectoryPath($id, $time)
    {
        return sprintf('%s%s%s%s%s%s%s', $this->getSatellitePath($id),
            DIRECTORY_SEPARATOR, gmdate('Y', $time),
            DIRECTORY_SEPARATOR, gmdate('m', $time),
            DIRECTORY_SEPARATOR, gmdate('d', $time)
        );
    }

    /**
     * Gets list of TLE files in satellite's directory for specific day.
     * 
     * @param int $id satellite's NORAD identifier.
     * @param int $time Unix timestamp belonging to the day of interest.
     * @return array
     */
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

    /**
     * Gets path to TLE file. 
     * 
     * @param int $id satellite's NORAD identifier.
     * @param int $time Unix timestamp of TLE.
     * @return string
     */
    protected function getFilePath($id, $time)
    {
        return sprintf('%s%s%s-%s-%s.tle', $this->getDirectoryPath($id, $time),
            DIRECTORY_SEPARATOR, gmdate('H', $time), gmdate('i', $time), gmdate('s', $time)
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