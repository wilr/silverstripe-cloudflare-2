<?php

namespace SteadLane\Cloudflare;

use Exception;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SteadLane\Cloudflare\Messages\Notifications;

/**
 * Cloudflare API integration for SilverStripe
 * Handles authentication, zone management, and cache purging
 */
class CloudFlare
{
    use Configurable;
    use Injectable;
    use Extensible;

    /**
     * Cache key for storing the Cloudflare Zone ID
     */
    const CF_ZONE_ID_CACHE_KEY = 'CFZoneID';

    /**
     * Whether a Zone ID has been detected, enabling admin panel functionality
     *
     * @var bool
     */
    protected static $ready = false;

    /**
     * Check if Cloudflare API credentials are configured
     *
     * @return bool
     */
    public function hasCFCredentials()
    {
        return !empty(Environment::getEnv('CLOUDFLARE_AUTH_KEY'));
    }

    /**
     * Get the current server name for Cloudflare zone identification
     *
     * @return string
     */
    public function getServerName()
    {
        $serverName = Environment::getEnv('CLOUDFLARE_SERVER_NAME') ?? '';
        if ($serverName) {
            return $serverName;
        }

        if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
            $serverName = Convert::raw2xml($_SERVER['HTTP_HOST']);
        } elseif (!empty($_SERVER['SERVER_NAME'])) {
            $serverName = Convert::raw2xml($_SERVER['SERVER_NAME']);
        } else {
            $serverName = '';
        }

        // Remove protocols
        $replaceWith = [
            'http://' => '',
            'https://' => ''
        ];
        if (!isset($_SERVER['HTTP_HOST'])) {
            $replaceWith['www.'] = '';
        }
        $serverName = str_replace(array_keys($replaceWith), array_values($replaceWith), $serverName);

        // Allow extensions to modify or replace the server name if required
        $this->extend('updateCloudFlareServerName', $serverName);

        return $serverName;
    }

    /**
     * Check if caching is enabled for the Cloudflare module
     *
     * @return bool
     */
    public function getCacheEnabled()
    {
        return (bool)self::config()->get('cache_enabled');
    }

    /**
     * Get the Cloudflare Zone ID for the current domain
     *
     * @return string|bool|null Zone ID on success, false on error, null if no credentials
     */
    public function fetchZoneID()
    {
        if ($zoneID = Environment::getEnv('CLOUDFLARE_ZONE_ID')) {
            $this->isReady(true);
            return $zoneID;
        }
        if (!$this->hasCFCredentials()) {
            return null;
        }

        if ($this->getCacheEnabled()) {
            $factory = Injector::inst()->get(CacheInterface::class . '.CloudflareCache');
            if ($factory && ($cache = $factory->get(self::CF_ZONE_ID_CACHE_KEY))) {
                $this->isReady(true);
                return $cache;
            }
        }

        $serverName = $this->getServerName();

        if ($serverName === 'localhost') {
            Notifications::handleMessage(
                _t(
                    "CloudFlare.NoLocalhost",
                    "This module does not operate under <strong>localhost</strong>." .
                        "Please ensure your website has a resolvable DNS and access the website via the domain."
                ),
                ["type" => "error"]
            );

            return false;
        }

        $url = "https://api.cloudflare.com/client/v4/zones" .
            "?name={$serverName}" .
            "&status=active&page=1&per_page=20" .
            "&order=status&direction=desc&match=all";

        $result = $this->curlRequest($url, null, 'GET');

        $array = json_decode($result, true);

        if (!is_array($array) || !array_key_exists("result", $array) || empty($array['result'])) {
            $this->isReady(false);
            Notifications::handleMessage(
                _t(
                    "CloudFlare.ZoneIdNotFound",
                    "Unable to detect a Zone ID for <strong>{server_name}</strong> under the defined Cloudflare" .
                        " user.<br/><br/>Please create a new zone under this account to use this module on this domain.",
                    "",
                    [
                        "server_name" => $serverName
                    ]
                ),
                ["type" => "error"]
            );

            return false;
        }

        $zoneID = $array['result'][0]['id'];

        if ($this->getCacheEnabled() && isset($factory)) {
            $factory->set(self::CF_ZONE_ID_CACHE_KEY, $zoneID);
        }

        $this->isReady(true);

        return $zoneID;
    }

    /**
     * Set or get the ready state
     *
     * @param bool|null $state
     * @return bool|null
     */
    public function isReady($state = null)
    {
        if ($state !== null) {
            self::$ready = (bool)$state;
            return $state;
        }

        $this->fetchZoneID();
        return self::$ready;
    }

    /**
     * Get session data for Cloudflare notifications
     *
     * @return array<string, mixed>
     */
    public function getSessionJar()
    {
        $controller = Controller::curr();
        if (!$controller || !$controller->getRequest()) {
            return [];
        }

        $session = $controller->getRequest()->getSession()->get('slCloudFlare');
        if (!$session) {
            $session = [];
        }
        return $session;
    }

    /**
     * Set session data for Cloudflare notifications
     *
     * @param array<string, mixed> $data
     * @return $this
     */
    public function setSessionJar($data)
    {
        $controller = Controller::curr();
        if (!$controller || !$controller->getRequest()) {
            return $this;
        }

        $controller->getRequest()->getSession()->set('slCloudFlare', $data);
        return $this;
    }

    /**
     * Returns the cURL execution timeout limit (seconds)
     *
     * @return int
     */
    public function getCurlTimeout()
    {
        return (int)self::config()->get('curl_timeout');
    }

    /**
     * Execute a cURL request to Cloudflare API with authentication
     *
     * @param string $url The API endpoint URL
     * @param string|array|null $data Optional data to send with the request
     * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.)
     * @return string JSON response
     */
    public function curlRequest($url, $data = null, $method = 'DELETE'): string
    {
        $curlTimeout = $this->getCurlTimeout();

        $curl = curl_init();

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $curlTimeout);
        curl_setopt($curl, CURLOPT_TIMEOUT, $curlTimeout);

        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);


        curl_setopt($curl, CURLOPT_HTTPHEADER, $this->getAuthHeaders());
        // This is intended, and was/is required by Cloudflare at one point
        curl_setopt($curl, CURLOPT_USERAGENT, $this->getUserAgent());

        if (!is_null($data)) {
            if (is_array($data)) {
                $data = json_encode($data);
            }
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        }

        try {
            $result = curl_exec($curl);
            $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curlError = curl_error($curl);

            // Handle any errors
            if (false === $result || $curlError) {
                throw new Exception("Error connecting to Cloudflare: (code={$responseCode}) {$curlError}");
            }
            
            $decoded = json_decode($result);
            if ($decoded && isset($decoded->errors) && !empty($decoded->errors)) {
                $errorMsg = $decoded->errors[0]->message ?? 'Unknown error';
                $errorCode = $decoded->errors[0]->code ?? 'N/A';
                throw new Exception($errorMsg . ": (code={$errorCode})");
            }
        } catch (Exception $e) {
            //log the exception but don't prevent the page from publishing
            Injector::inst()->get(LoggerInterface::class)->warning($e->getMessage());
            $result = json_encode(['success' => false, 'errors' => [['message' => $e->getMessage()]]]);
        }

        curl_close($curl);

        return $result;
    }

    /**
     * Get the user agent string for Cloudflare API requests
     *
     * @return string
     */
    public function getUserAgent()
    {
        return "Mozilla/5.0 " .
            "(Macintosh; Intel Mac OS X 10_11_6) " .
            "AppleWebKit/537.36 (KHTML, like Gecko) " .
            "Chrome/53.0.2785.143 Safari/537.36";
    }

    /**
     * Get authentication headers for Cloudflare API requests
     *
     * @return array<string> Array of HTTP headers
     */
    public function getAuthHeaders()
    {
        if (!$this->hasCFCredentials()) {
            self::debug("Cloudflare API credentials have not been provided.");
            Injector::inst()->get(LoggerInterface::class)->error(
                "Cloudflare API credentials have not been provided."
            );
            return [];
        }

        $headers = [
            "Authorization: Bearer " . Environment::getEnv('CLOUDFLARE_AUTH_KEY'),
            "Content-Type: application/json"
        ];

        $this->extend("updateCloudFlareAuthHeaders", $headers);

        return $headers;
    }

    /**
     * Prepend server name to URL(s)
     *
     * @param array<string>|string $input URL or array of URLs
     * @return array<string>|string Absolute URL(s) with server name
     */
    public function prependServerName($input)
    {
        $serverName = CloudFlare::singleton()->getServerName();

        if (is_array($input)) {
            $stack = [];
            foreach ($input as $string) {
                $stack[] = $this->prependServerName($string);
            }
            return $stack;
        }

        if (strstr($input, "http://") || strstr($input, "https://")) {
            $input = str_replace(["http://", "https://"], "", trim($input));
        }

        if (strstr($input, $serverName)) {
            return "http://" . $input;
        }

        return "http://" . str_replace("//", "/", "{$serverName}/{$input}");
    }

    /**
     * Get mock API response for testing
     *
     * @param string $type Response type (e.g., 'Purge')
     * @param bool $isSuccessful Whether to return a success or failure response
     * @return array<string, mixed> Mock API response
     */
    public static function getMockResponse($type, $isSuccessful)
    {
        $mockDir = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . Director::baseURL() . "cloudflare/tests/Mock/";

        if (!is_dir($mockDir)) {
            Injector::inst()->get(LoggerInterface::class)->error(
                "Mock directory does not exist: {$mockDir}"
            );
            return ['success' => false, 'errors' => [['message' => 'Mock directory not found']]];
        }

        $filename = ucfirst($type) . (($isSuccessful) ? "Success" : "Failure") . ".json";
        $path = $mockDir . $filename;

        if (!file_exists($path)) {
            Injector::inst()->get(LoggerInterface::class)->error(
                "Mock file does not exist: {$path}"
            );
            return ['success' => false, 'errors' => [['message' => 'Mock file not found']]];
        }

        $result = json_decode(file_get_contents($path), true);

        if (!$result || !is_array($result)) {
            Injector::inst()->get(LoggerInterface::class)->error(
                "Invalid JSON in mock file: {$filename}"
            );
            return ['success' => false, 'errors' => [['message' => 'Invalid mock JSON']]];
        }

        return $result;
    }

    /**
     * Debug logging helper
     *
     * @param string $msg Debug message
     * @param mixed $obj Optional object/array to dump
     * @return void
     */
    public static function debug($msg, $obj = null)
    {
        if (CloudFlare::config()->debug && Injector::inst()->get(LoggerInterface::class)) {
            $error = $msg;
            if ($obj) {
                $error .= ' ' . print_r($obj, true);
            }
            Injector::inst()->get(LoggerInterface::class)->debug($error);
        }
    }
}
