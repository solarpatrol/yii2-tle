<?php

namespace solarpatrol\tle;

use yii\base\Component;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\helpers\Json;

abstract class Storage extends Component
{
    const TLE_LINE_LENGTH = 69;
    const SECONDS_PER_DAY = 86400;

    public $spaceTrackUrl = 'https://www.space-track.org';
    public $spaceTrackLogin;
    public $spaceTrackPassword;
    public $connectionTimeout = 30;
    public $cookiePath = 'space-track-cookie';
    public $proxyHost;
    public $proxyPort;
    public $proxyAuthLogin;
    public $proxyAuthPassword = '';
    public $userAgent;
    public $enableCaching = true;
    public $cacheExpiration = 21600;

    /**
     * @var array keeps default CURL options
     */
    protected $curlOptions = [];

    /**
     * @inheritdoc
     * @throws Exception
     */
    public function init()
    {
        parent::init();

        if (!isset($this->spaceTrackLogin)) {
            throw new InvalidConfigException('Space Track login is not provided.');
        }

        if (!isset($this->spaceTrackPassword)) {
            throw new InvalidConfigException('Space Track password is not provided.');
        }

        $this->curlOptions = [
            CURLOPT_URL => $this->spaceTrackUrl,
            CURLOPT_HTTPHEADER => [
                'Accept: */*',
                'Connection: Keep-Alive'
            ],
            CURLOPT_HEADER => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_AUTOREFERER => true,
            CURLOPT_TIMEOUT => $this->connectionTimeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectionTimeout,
            CURLOPT_SSL_VERIFYHOST, true,
            CURLOPT_SSL_VERIFYPEER, true
        ];

        if (isset($this->proxyHost) && isset($this->proxyPort)) {
            $this->setProxy($this->proxyHost, $this->proxyPort, $this->proxyAuthLogin, $this->proxyAuthPassword);
        }

        if (isset($this->userAgent)) {
            $this->curlOptions[CURLOPT_USERAGENT] = $this->userAgent;
        }
    }

    /**
     * @param int $id
     * @param int $timestamp
     * @return bool
     */
    abstract function exists($id, $timestamp);

    /**
     * @param int $id
     * @param string $line1
     * @param string $line2
     * @return bool
     */
    abstract function add($id, $line1, $line2);

    /**
     * @param int $id
     * @param int $startTimestamp
     * @param int $endTimestamp
     * @return mixed
     */
    abstract function getRange($id, $startTimestamp, $endTimestamp);

    /**
     * @param int $id
     * @param int $timestamp
     * @return bool
     */
    abstract function remove($id, $timestamp);

    public function get($id, $timestamp, $actualDaysCount = 5)
    {
        $startTime = $timestamp - self::SECONDS_PER_DAY * $actualDaysCount;
        $endTime = $timestamp + self::SECONDS_PER_DAY * $actualDaysCount;

        $tles = $this->getRange($id, $startTime, $endTime);

        $closestTle = null;

        foreach ($tles as $tle) {
            if ($closestTle === null || abs($timestamp - $tle['timestamp']) < abs($timestamp - $closestTle['timestamp'])) {
                $closestTle = $tle;
                continue;
            }
            break;
        }

        return $closestTle;
    }

    public function update(array $ids, $timestamp, $actualDaysCount = 5)
    {
        $year = intval(gmdate('Y', $timestamp));
        $month = intval(gmdate('n', $timestamp));
        $day = intval(gmdate('j', $timestamp));
        $currentDayStartTimestamp = gmmktime(0, 0, 0, $month, $day, $year);

        $startTimestamp = $currentDayStartTimestamp - $actualDaysCount * Storage::SECONDS_PER_DAY;
        $endTimestamp = $currentDayStartTimestamp + ($actualDaysCount + 1) * Storage::SECONDS_PER_DAY;

        return $this->updateRange($ids, $startTimestamp, $endTimestamp);
    }

    public function updateRange(array $ids, $startTimestamp, $endTimestamp)
    {
        $data = $this->download($ids, $startTimestamp, $endTimestamp);
        if (!$data) {
            return false;
        }

        foreach ($data as $dataItem) {
            if ($this->exists($dataItem['id'], $dataItem['epochTimestamp'])) {
                continue;
            }

            if (!$this->add($dataItem['id'], $dataItem['line1'], $dataItem['line2'])) {
                return false;
            }
        }

        return true;
    }

    public function download(array $ids, $startTimestamp, $endTimestamp)
    {
        $epochRange = gmdate('Y-m-d', $startTimestamp) . '--' . gmdate('Y-m-d', $endTimestamp);

        $url = sprintf('%s/basicspacedata/query/class/tle/format/json/EPOCH/%s/NORAD_CAT_ID/%s/orderby/EPOCH%%20desc',
            $this->spaceTrackUrl, $epochRange, implode(',', $ids)
        );

        $cacheKey = 'tle-download:' . sha1(serialize([
            'epochStartTime' => $startTimestamp,
            'epochEndTime' => $endTimestamp,
            'ids' => $ids
        ]));

        if ($this->enableCaching) {
            $data = \Yii::$app->cache->get($cacheKey);
            if ($data !== false) {
                return $data;
            }
        }

        $cookiePath = null;
        try {
            $loginResult = $this->login();
            if ($loginResult['response']['http_code'] !== 200) {
                return false;
            }

            $cookiePath = $loginResult['cookiePath'];

            $fetchResult = $this->curl([
                CURLOPT_URL => $url,
                CURLOPT_HEADER => false,
                CURLOPT_COOKIEFILE => $cookiePath
            ]);
            if ($fetchResult['response']['http_code'] !== 200 ||
                $fetchResult['response']['content_type'] !== 'application/json'
            ) {
                $this->logout($cookiePath);
                return false;
            }

            $this->logout($cookiePath);

            $data = self::parseTleData($fetchResult['output']);
            if ($this->enableCaching) {
                \Yii::$app->cache->set($cacheKey, $data, $this->cacheExpiration);
            }

            return $data;
        }
        catch (Exception $e) {
            if ($cookiePath !== null) {
                $this->logout($cookiePath);
            }
            throw $e;
        }
    }

    protected static function parseTleData(&$output)
    {
        $outputData = Json::decode($output);

        $data = [];
        foreach ($outputData as $outputDataItem) {
            $id = intval($outputDataItem['NORAD_CAT_ID']);
            $line1 = substr($outputDataItem['TLE_LINE1'], 0, self::TLE_LINE_LENGTH);
            $line2 = substr($outputDataItem['TLE_LINE2'], 0, self::TLE_LINE_LENGTH);
            $epoch = $outputDataItem['EPOCH'];
            $epochParsed = date_parse_from_format('Y.m.d H:i:s', $outputDataItem['EPOCH']);

            $data[] = [
                'id' => $id,
                'line1' => $line1,
                'line2' => $line2,
                'epochTime' => $epoch,
                'epochTimestamp' => self::getEpochTimestamp($line1),
                'epochTimestampParsed' => gmmktime(
                    $epochParsed['hour'],
                    $epochParsed['minute'],
                    $epochParsed['second'],
                    $epochParsed['month'],
                    $epochParsed['day'],
                    $epochParsed['year']
                )
            ];
        }

        return $data;
    }

    protected static function getEpochTimestamp($line1)
    {
        $year = intval(substr($line1, 18, 2));
        $year = ($year >= 57 ? 1900 : 2000) + $year;
        $seconds = intval((floatval(substr($line1, 20, 12)) - 1) * 86400);
        return gmmktime(0, 0, 0, 1, 1, $year) + $seconds;
    }

    protected function curl($curlOptions = array(), $options = array())
    {
        foreach ($this->curlOptions as $key => $value) {
            if (!isset($curlOptions[$key])) {
                $curlOptions[$key] = $value;
            }
        }

        $curlHandler = isset($options['curlHandler']) ? $options['curlHandler'] : curl_init();
        foreach ($curlOptions as $curlOptionName => $curlOptionValue) {
            curl_setopt($curlHandler, $curlOptionName, $curlOptionValue);
        }

        $output = curl_exec($curlHandler);
        $response = curl_getinfo($curlHandler);

        $result = array(
            'response' => $response,
            'output' => $output
        );

        if ($output === false) {
            $result['errorCode'] = curl_errno($curlHandler);
            $result['errorMessage'] = curl_error($curlHandler);
        }

        if (isset($options['closeCurlHandler']) && $options['closeCurlHandler'] === false) {
            $result['curlHandler'] = $curlHandler;
        }
        else {
            curl_close($curlHandler);
        }
        return $result;
    }

    protected function login()
    {
        $cookiePath = tempnam(sys_get_temp_dir(), $this->cookiePath);
        $result = $this->curl([
            CURLOPT_URL => $this->spaceTrackUrl . '/ajaxauth/login',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'identity' => $this->spaceTrackLogin,
                'password' => $this->spaceTrackPassword
            ],
            CURLOPT_COOKIEJAR => $cookiePath
        ]);

        $result['cookiePath'] = $cookiePath;
        return $result;
    }

    protected function logout($cookiePath)
    {
        return $this->curl([
            CURLOPT_URL => $this->spaceTrackUrl . '/ajaxauth/logout',
            CURLOPT_POST => false,
            CURLOPT_COOKIEFILE => $cookiePath
        ]);
    }

    public function setProxy($host, $port = 0, $authLogin = null, $authPassword = '')
    {
        if (!$host) {
            $this->curlOptions[CURLOPT_PROXY] = null;
            return;
        }

        $proxy = $host;
        if ($port) {
            $proxy .= ':' . $port;
        }
        $this->curlOptions[CURLOPT_PROXY] = $proxy;

        if ($authLogin) {
            $this->curlOptions[CURLOPT_PROXYUSERPWD] = $authLogin . ':' . $authPassword;
        }
    }
}