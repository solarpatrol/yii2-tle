<?php

namespace solarpatrol\tle;

use solarpatrol\tle\models\Tle;
use yii\base\Component;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\helpers\Json;

abstract class Storage extends Component
{
    const TLE_LINE_LENGTH = 69;
    const SECONDS_PER_DAY = 86400;

    public $actualDaysCount = 5;

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
     * Checks whether TLE is in the storage.
     *
     * @param object|TLE $tle
     * @return bool
     */
    abstract function exists(&$tle);

    /**
     * Adds TLE to the storage.
     *
     * @param object|Tle $tle
     * @return bool
     */
    abstract function add(&$tle);

    /**
     * Finds all TLEs in the storage within specified time range.
     *
     * @param int|array $id satellite's NORAD identifier.
     * @param int $startTime start of time range.
     * @param int $endTime end of time range.
     * @return array
     */
    abstract function get($id, $startTime, $endTime);

    /**
     * Removes TLE from the storage.
     *
     * @param object|TLE
     * @return bool
     */
    abstract function remove(&$tle);

    /**
     * Gets NORAD identifiers of all satellites having TLEs in the storage.
     * 
     * @return array
     */
    abstract function getIds();

    /**
     * Makes API request.
     *
     * @param string $url request URL.
     * @return mixed
     * @throws Exception
     */
    public function requestApi($url)
    {
        $cookiePath = null;
        try {
            $result = $this->login();
            if ($result['response']['http_code'] !== 200) {
                throw new Exception(sprintf(Module::t('Unable to log in with HTTP error code #%d.'), $result['response']['http_code']));
            }

            $cookiePath = $result['cookiePath'];

            $result = $this->curl([
                CURLOPT_URL => $url,
                CURLOPT_HEADER => false,
                CURLOPT_COOKIEFILE => $cookiePath
            ]);

            if ($result['response']['http_code'] !== 200 ||
                $result['response']['content_type'] !== 'application/json'
            ) {
                $this->logout($cookiePath);
                throw new Exception(sprintf(Module::t('Request is ended with HTTP error code #%d.'), $result['response']['http_code']));
            }

            $this->logout($cookiePath);

            return $result['output'];
        }
        catch(Exception $e) {
            if ($cookiePath !== null) {
                $this->logout($cookiePath);
            }
            throw $e;
        }
    }

    /**
     * Downloads TLEs from Space Track for specified time range and saves them in the storage.
     *
     * @param array $ids NORAD identifiers of satellites.
     * @param int $startTime Unix timestamp of range's start.
     * @param int $endTime Unix timestamp of range's end.
     * @return array
     * @throws Exception
     */
    public function update(array $ids, $startTime, $endTime)
    {
        $tles = $this->download($ids, $startTime, $endTime);

        foreach ($tles as $tle) {
            if ($this->exists($tle)) {
                continue;
            }

            if (!$this->add($tle)) {
                throw new Exception(Module::t('Unable to add TLE to storage.'));
            }
        }

        return $tles;
    }

    /**
     * Downloads TLEs from Space Track for specified time range.
     *
     * @param array $ids NORAD identifiers of satellites.
     * @param int $startTime Start of time range.
     * @param int $endTime End of time range.
     * @return array
     * @throws Exception
     */
    public function download(array $ids, $startTime = null, $endTime = null)
    {
        if ($ids[0] === '*') {
            $satellites = $this->getSatcat();
            $ids = sprintf('%d--%d', $satellites[count($satellites) - 1]['id'], $satellites[0]['id']);
        }
        else {
            sort($ids);
        }

        $startTimestamp = self::timestamp($startTime);
        $endTimestamp = self::timestamp($endTime);
        if ($startTime === null || $startTimestamp >= $endTimestamp) {
            $startTimestamp = $endTimestamp - $this->actualDaysCount * Storage::SECONDS_PER_DAY;
        }

        $startTimestamp -= $startTimestamp % self::SECONDS_PER_DAY;
        $endTimestamp -= $endTimestamp % self::SECONDS_PER_DAY - self::SECONDS_PER_DAY;

        $epochRange = gmdate('Y-m-d', $startTimestamp) . '--' . gmdate('Y-m-d', $endTimestamp);
        $predicates = ['TLE_LINE0', 'TLE_LINE1', 'TLE_LINE2'];

        $url = sprintf('%s/basicspacedata/query/class/tle/format/json/predicates/%s/EPOCH/%s/NORAD_CAT_ID/%s/orderby/EPOCH%%20desc',
            $this->spaceTrackUrl, implode(',', $predicates), $epochRange, is_array($ids) ? implode(',', $ids) : $ids
        );

        // Checking whether TLEs are present in local cache
        $cacheKey = 'tle:' . sha1(serialize([
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

        $response = $this->requestApi($url);
        $data = self::parseTleData($response);
        if ($this->enableCaching) {
            \Yii::$app->cache->set($cacheKey, $data, $this->cacheExpiration);
        }
        return $data;
    }

    public function getSatcat(array $options = [])
    {
        $limit = isset($options['limit']) ? $options['limit'] : 25000;
        $offset = isset($options['offset']) ? $options['offset'] : 0;
        $internal = isset($options['internal']) ? $options['internal'] : false;

        $cacheKey = 'tle:satcat';
        if (!$internal && $this->enableCaching) {
            $satcat = \Yii::$app->cache->get($cacheKey);
            if ($satcat !== false) {
                return $satcat;
            }
        }

        $satcat = [];

        $url = sprintf('%s/basicspacedata/query/class/satcat/format/json/predicates/NORAD_CAT_ID,SATNAME/limit/%d,%d/orderby/NORAD_CAT_ID%%20desc',
            $this->spaceTrackUrl, $limit, $offset
        );

        $response = Json::decode($this->requestApi($url));
        foreach($response as $item) {
            $satcat[] = [
                'id' => intval($item['NORAD_CAT_ID']),
                'name' => $item['SATNAME']
            ];
        }

        if (count($response) === $limit) {
            $satcat = array_merge($satcat, $this->getSatcat([
                'limit' => $limit,
                'offset' => $offset + $limit,
                'internal' => true
            ]));
        }

        if (!$internal && $this->enableCaching) {
            \Yii::$app->cache->set($cacheKey, $satcat, 86400 * 7);
        }

        return $satcat;
    }

    /**
     * Finds closest TLE in a set of TLEs.
     *
     * @param array $tles set of TLEs.
     * @param int $time time of interest.
     * @return array|null
     */
    public static function getClosest(&$tles, $time)
    {
        $time = self::timestamp($time);

        $closestTle = null;
        $closestEpochTimestamp = null;

        foreach ($tles as $tle) {
            $epochTimestamp = self::getEpochTimestamp($tle);
            if ($closestTle === null || abs($time - $epochTimestamp) < abs($time - $closestEpochTimestamp)) {
                $closestTle = $tle;
                $closestEpochTimestamp = $epochTimestamp;
                continue;
            }
            break;
        }

        return $closestTle;
    }

    /**
     * Gets unix timestamp
     *
     * @param string|int|null $time Time presentation.
     * @return false|int
     */
    public static function timestamp($time = null)
    {
        if (is_string($time)) {
            return strtotime($time);
        }

        if (is_int($time)) {
            return $time;
        }

        return time();
    }

    /**
     * Gets satellite's NORAD identifier from TLE.
     *
     * @param array|TLE $tle
     * @return int
     */
    public static function getNoradId(&$tle)
    {
        if ($tle instanceof Tle) {
            return $tle->norad_id;
        }
        return intval(substr($tle[1], 2, 5));
    }

    /**
     * Gets satellite's name from TLE.
     * @param array|TLE $tle
     * @return string
     */
    public static function getName(&$tle)
    {
        if ($tle instanceof Tle) {
            return $tle->name;
        }
        return substr($tle[0], 2);
    }

    /**
     * Gets epoch Unix timestamp from TLE.
     *
     * @param array|TLE $tle.
     * @return int
     */
    public static function getEpochTimestamp(&$tle)
    {
        if ($tle instanceof Tle) {
            return strtotime($tle->epoch_time);
        }

        $year = intval(substr($tle[1], 18, 2));
        $year = ($year >= 57 ? 1900 : 2000) + $year;
        $seconds = intval((floatval(substr($tle[1], 20, 12)) - 1) * 86400);
        return gmmktime(0, 0, 0, 1, 1, $year) + $seconds;
    }

    /***
     * Parses Space Track API's response.
     * 
     * @param string $response Response JSON string.
     * @return array TLE items.
     */
    protected static function parseTleData(&$response)
    {
        $response = Json::decode($response);

        $items = [];
        foreach ($response as $item) {
            $items[] = array_values($item);
        }

        return $items;
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
