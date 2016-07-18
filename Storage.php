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

    /**
     * @var string URL of Space Track service.
     */
    public $spaceTrackUrl = 'https://www.space-track.org';

    /**
     * @var string Space Track API account's login.
     */
    public $spaceTrackLogin;

    /**
     * @var string Space Track API account's password.
     */
    public $spaceTrackPassword;

    /**
     * @var int timeout in seconds to send an API request to Space Track.
     */
    public $connectionTimeout = 30;

    /**
     * @var bool whether data caching is enabled.
     */
    public $enableCaching = true;

    /**
     * @var int cache expiration in seconds.
     */
    public $cacheExpiration = 21600;

    /**
     * @var string user agent to send in headers of Space Track API requests.
     */
    public $userAgent;

    /**
     * @var string host of proxy server (if used).
     */
    public $proxyHost;

    /**
     * @var string port of proxy server (if used).
     */
    public $proxyPort;

    /**
     * @var string proxy server's authentication login (if proxy server requires authentication).
     */
    public $proxyAuthLogin;

    /**
     * @var string proxy server's authentication password (if proxy server requires authentication).
     */
    public $proxyAuthPassword = '';

    /**
     * @var string path to store cookies.
     */
    public $cookiePath = 'space-track-cookie';

    /**
     * @var array keeps CURL options
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
     * Checks whether TLE for specified time exists in the storage.
     *
     * @param int $id satellite's NORAD identifier.
     * @param int $timestamp Unix timestamp.
     * @return bool
     */
    abstract function exists($id, $timestamp);

    /**
     * Adds TLE to the storage.
     *
     * @param int $id satellite's NORAD identifier.
     * @param string $line1 first line of TLE.
     * @param string $line2 second line of TLE.
     * @return bool
     */
    abstract function add($id, $line1, $line2);

    /**
     * Finds all TLEs in the storage within specified time range.
     *
     * @param int $id satellite's NORAD identifier.
     * @param int $startTimestamp Unix timestamp of range's start.
     * @param int $endTimestamp Unix timestamp of range's end.
     * @return array
     */
    abstract function getRange($id, $startTimestamp, $endTimestamp);

    /**
     * Removes TLE from the storage.
     *
     * @param int $id satellite's NORAD identifier.
     * @param int $timestamp Unix timestamp.
     * @return bool
     */
    abstract function remove($id, $timestamp);

    /**
     * Finds closest TLE in the storage within specified actual days in the past and in the future.
     *
     * @param int $id satellite's NORAD identifier.
     * @param int $timestamp Unix timestamp.
     * @param int $actualDaysCount days count to the past and to the future from specified time to search in.
     * @return array|null
     */
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

    /**
     * Downloads TLEs from Space Track for specified time and saves them in the storage.
     *
     * @param array $ids NORAD identifiers of satellites.
     * @param int $timestamp Unix timestamp to download TLEs for.
     * @param int $actualDaysCount days count to the past and to the future from specified time to download for.
     * @return bool
     */
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

    /**
     * Downloads TLEs from Space Track for specified time range and saves them in the storage.
     * 
     * @param array $ids NORAD identifiers of satellites.
     * @param int $startTimestamp Unix timestamp of range's start.
     * @param int $endTimestamp Unix timestamp of range's end.
     * @return bool
     * @throws Exception
     */
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

    /**
     * Downloads TLEs from Space Track for specified time range.
     * 
     * @param array $ids NORAD identifiers of satellites.
     * @param int $startTimestamp Unix timestamp of range's start.
     * @param int $endTimestamp Unix timestamp of range's end.
     * @return array|bool
     * @throws Exception
     */
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

    /***
     * Parses Space Track API's response.
     * 
     * @param string $output response JSON string.
     * @return array TLE items.
     */
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

    /**
     * Gets epoch Unix timestamp from first TLE line.
     * 
     * @param $line1 first TLE line.
     * @return int
     */
    protected static function getEpochTimestamp($line1)
    {
        $year = intval(substr($line1, 18, 2));
        $year = ($year >= 57 ? 1900 : 2000) + $year;
        $seconds = intval((floatval(substr($line1, 20, 12)) - 1) * 86400);
        return gmmktime(0, 0, 0, 1, 1, $year) + $seconds;
    }

    /**
     * Executes CURL request.
     * 
     * @param array $curlOptions CURL options.
     * @param array $options Options to pass existing CURL handler and leave CURL handler non-closed on complete.
     * @return array
     */
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

    /**
     * Logs in on Space Track.
     * 
     * @return array 
     */
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

    /**
     * Logs out from Space Track.
     * 
     * @param string $cookiePath path to cookies.
     * @return array
     */
    protected function logout($cookiePath)
    {
        return $this->curl([
            CURLOPT_URL => $this->spaceTrackUrl . '/ajaxauth/logout',
            CURLOPT_POST => false,
            CURLOPT_COOKIEFILE => $cookiePath
        ]);
    }

    /**
     * Sets proxy settins. 
     * 
     * @param string $host
     * @param int $port
     * @param string $authLogin
     * @param string $authPassword
     */
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