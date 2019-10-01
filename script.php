#!/usr/bin/env php72
<?php
use Exception;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ClientException as GuzzleException;
use Cloudflare\API\Auth\APIKey as CloudflareKey;
use Cloudflare\API\Adapter\Guzzle as CloudflareGuzzleAdapter;
use Cloudflare\API\Endpoints\User as CloudflareUserApi;
use Cloudflare\API\Endpoints\DNS as CloudflareDnsApi;
use Cloudflare\API\Endpoints\Zones as CloudflareZoneApi;

define('LOG_FILE', '/var/log/cloudflareddns.log');

function logger(string $msg, array $params = []) {
    static $logger;
    
    if ($logger === null) {
        $logger = fopen(LOG_FILE, 'a+');
    }

    if (is_array($params) && !empty($params)) {
        $msg .= ' - '.json_encode($params);
    }
    
    $msg = '['.date('Y-m-d H:i:s').'] '.$msg;
    
    fwrite($logger, $msg."\n");
}

function env(string $key, $default = null): ?string {
    if (array_key_exists($key, $_ENV)) {
        return $_ENV[$key];
    }

    return $default;
}

/**
 * Composer package autoloader
 */
require_once('vendor/autoload.php');

/**
 * Load environment config.
 */
$dotenv = Dotenv\Dotenv::create(__DIR__);
$dotenv->load();

/**
 * API client instance
 */
$guzzle = new Guzzle();

$domainName = env('DOMAIN_NAME');
$recordTtl = env('RECORD_TTL', 1);
$recordProxy = env('RECORD_PROXY', true);

// Must be plugin invoke
if ($argc === 5) {
    $authEmail  = (string) $argv[1];
    $authKey    = (string) $argv[2];
    $recordName = (string) $argv[3];
    $ipAddress  = (string) $argv[4];
}
// Fallback for cron
else {
    /**
     * Service configuration
     */
    $authEmail  = env('AUTH_EMAIL');
    $authKey    = env('AUTH_KEY');
    $recordName = env('RECORD_NAME');

    // Get external/remote facing public IP address
    logger('Detecting public IP address...');
    $response = $guzzle->get('https://api.ipify.org');
    $ipAddress = (string) $response->getBody();
    logger('Detected public IP Address from remote service: '.$ipAddress);
}

// Validate the provided record hostname
if (strpos($recordName, '.') === false) {
    logger('ERROR: Record name is invalid!');
    echo 'notfqdn';
    exit(1);
}

// Validate the IP address is IPv4 format
if (!filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    logger('ERROR: IP address detected is invalid!');
    echo 'badparam';
    exit(1);
}

/**
 * API client instances
 */
$key     = new CloudflareKey($authEmail, $authKey);
$adapter = new CloudflareGuzzleAdapter($key);
$user    = new CloudflareUserApi($adapter);
$dns     = new CloudflareDnsApi($adapter);
$zones   = new CloudflareZoneApi($adapter);

try {
    $userEmail = $user->getUserEmail();
    logger('Authenticated into Cloudflare account: '.$userEmail);

    // Get the DNS zone status
    // $zone = $zones->getZoneById($zoneId)->result;

    // Get the DNS zone ID for the domain name
    logger('Looking up zone ID for domain name...');
    $zoneId = $zones->getZoneID($domainName);
    logger('Found DNS zone ID: '.$zoneId);

    // Get the DNS record ID for the record name
    logger('Looking up DNS record ID for the record name...');
    $recordId = $dns->getRecordID($zoneId, 'A', $recordName);
    logger('Found DNS record ID: '.$recordId);

    // Get the current public IP address for the DNS record
    logger('Found DNS record IP address: '.$recordId);
    $record = $dns->getRecordDetails($zoneId, $recordId);
    $currentIpAddress = $record->content;
    logger('Current public IP Address from DNS zone resolver: '.$currentIpAddress);

    if ($currentIpAddress !== $ipAddress) {
        $recordData = [
            'type'    => 'A',
            'name'    => $recordName,
            'content' => $ipAddress,
            'ttl'     => $recordTtl,
            'proxied' => $recordProxy,
        ];
        logger('Updating public IP Address in DNS zone...');
        $result = $dns->updateRecordDetails($zoneId, $recordId, $recordData);
        logger($currentIpAddress.' => '.$ipAddress);
        logger('SUCCESS: The DNS record was updated.');
        echo 'good';
    }
    else {
        logger('NOOP: No IP address change detected, skipping update.');
        echo 'nochg';
    }
}
catch (GuzzleException $e) {
    $response = $e->getResponse();
    $statusCode = $response->getStatusCode();
    $body = (string) $response->getBody();
    $content = json_decode($body, true);
    $error = $content['errors'][0];
    $errorCode = $error['code'];
    $errorMsg = $error['message'];
    logger('ERROR: Status is '.$statusCode.' - '.$errorMsg.' ('.$errorCode.')');

    if ($statusCode == 401) {
        echo 'badauth';
    }
    else if ($statusCode == 408) {
        echo 'badconn';
    }
    else if ($statusCode == 500) {
        echo '911';
    }

    echo 'badagent';
    exit(1);
}
catch (Exception $e) {
    $msg = $e->getMessage();
    $code = $e->getCode();
    logger('ERROR: '.$msg.' ('.$code.')');
    echo 'badagent';
    exit(1);
}

fclose($logger);
exit(0);
