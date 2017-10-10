<?php

declare(strict_types=1);

namespace SeatGeek\Sixpack\Session;

use SeatGeek\Sixpack\Response;
use SeatGeek\Sixpack\Response\Conversion;
use SeatGeek\Sixpack\Response\Participation;
use SeatGeek\Sixpack\Session\Exception\InvalidExperimentNameException;
use SeatGeek\Sixpack\Session\Exception\InvalidForcedAlternativeException;
use InvalidArgumentException;

class Base
{
    /**
     * @var string
     */
    protected $baseUrl = 'http://localhost:5000';

    /**
     * @var string
     */
    protected $cookiePrefix = 'sixpack';

    /**
     * @var int
     */
    protected $timeout = 500;

    /**
     * @var string
     */
    protected $clientId = null;

    public function __construct(array $options = [])
    {
        if (isset($options['baseUrl'])) {
            $this->baseUrl = $options['baseUrl'];
        }
        if (isset($options['cookiePrefix'])) {
            $this->cookiePrefix = $options['cookiePrefix'];
        }
        if (isset($options['timeout'])) {
            $this->timeout = $options['timeout'];
        }
        $this->setClientId(isset($options['clientId']) ? $options['clientId'] : null);
    }

    protected function setClientId(string $clientId = null)
    {
        $this->clientId = $clientId
            ?? $this->retrieveClientId()
            ?? $this->generateClientId();

        $this->storeClientId($clientId);
    }

    public function getClientid(): ?string
    {
        return $this->clientId;
    }

    protected function retrieveClientId(): ?string
    {
        $cookieName = $this->cookiePrefix . '_client_id';

        if (isset($_COOKIE[$cookieName])) {
            return $_COOKIE[$cookieName];
        }

        return null;
    }

    protected function storeClientId(?string $clientId): void
    {
        $cookieName = $this->cookiePrefix . '_client_id';

        setcookie($cookieName, $clientId, time() + (60 * 60 * 24 * 30 * 100), "/");
    }

    protected function generateClientId(): string
    {
        // This is just a first pass for testing. not actually unique.
        // TODO, NOT THIS
        $md5 = strtoupper(md5(uniqid(rand(), true)));
        $clientId = substr($md5, 0, 8) . '-' . substr($md5, 8, 4) . '-' . substr($md5, 12, 4) . '-' . substr($md5, 16, 4) . '-' . substr($md5, 20);
        return $clientId;
    }

    public function setTimeout(int $milliseconds): void
    {
        $this->timeout = $milliseconds;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function isForced(string $experiment): bool
    {
        $forceKey = sprintf('sixpack-force-%s', $experiment);

        return in_array($forceKey, array_keys($_GET), true);
    }

    /**
     * Force the alternative
     *
     * @throws \SeatGeek\Sixpack\Session\Exception\InvalidForcedAlternativeException
     *   if an alternative is requested that doesn't exist
     */
    protected function forceAlternative(string $experiment, array $alternatives): array
    {
        $forceKey = sprintf('sixpack-force-%s', $experiment);

        $forcedAlt = isset($_GET[$forceKey]) ? $_GET[$forceKey] : null;

        if (!in_array($forcedAlt, $alternatives)) {
            throw new InvalidForcedAlternativeException([$forcedAlt, $alternatives]);
        }

        $mockJson = json_encode([
          'status' => 'ok',
          'alternative' => ['name' => $forcedAlt],
          'experiment' => ['version' => 0, 'name' => $experiment],
          'client_id' => null,
        ]);

        $mockMeta = [
            'http_code' => 200,
            'called_url' => '',
        ];

        return [$mockJson, $mockMeta];
    }

    public function status()
    {
        return $this->sendRequest('/_status');
    }

    /**
     * Convert an experiment
     *
     * @param mixed $kpi
     *
     * @throws \SeatGeek\Sixpack\Session\Exception\InvalidExperimentNameException
     *   if the experiment name is invalid
     */
    public function convert(string $experiment, $kpi = null): Conversion
    {
        list($rawResp, $meta) = $this->sendRequest('convert', [
            'experiment' => $experiment,
            'kpi' => $kpi,
        ]);

        return new Conversion($rawResp, $meta);
    }

    /**
     * Participate in an experiment
     *
     * @throws \SeatGeek\Sixpack\Session\Exception\InvalidExperimentNameException
     *   if the experiment name is invalid
     * @throws \InvalidArgumentException if less than two alternatives are specified
     * @throws \InvalidArgumentException if an alternative has an invalid name
     * @throws \InvalidArgumentException if the traffic fraction is less than 0 or greater
     *   than 1
     * @throws \SeatGeek\Sixpack\Session\Exception\InvalidForcedAlternativeException
     *   if an alternative is requested that doesn't exist
     */
    public function participate(string $experiment, array $alternatives, $trafficFraction = 1): Participation
    {
        if (count($alternatives) < 2) {
            throw new InvalidArgumentException("At least two alternatives are required");
        }

        foreach ($alternatives as $alt) {
            if (!preg_match('#^[a-z0-9][a-z0-9\-_ ]*$#i', $alt)) {
                throw new InvalidArgumentException("Invalid Alternative Name: {$alt}");
            }
        }

        if (floatval($trafficFraction) < 0 || floatval($trafficFraction) > 1) {
            throw new InvalidArgumentException("Invalid Traffic Fraction");
        }

        if ($this->isForced($experiment)) {
            list($rawResp, $meta) = $this->forceAlternative($experiment, $alternatives);
        } else {
            list($rawResp, $meta) = $this->sendRequest('participate', [
                'experiment' => $experiment,
                'alternatives' => $alternatives,
                'traffic_fraction' => $trafficFraction
            ]);
        }

        return new Response\Participation($rawResp, $meta, $alternatives[0]);
    }

    protected function getUserAgent(): ?string
    {
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            return $_SERVER['HTTP_USER_AGENT'];
        }

        return null;
    }

    protected function getIpAddress(): ?string
    {
        $ordered_choices = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];

        $invalid_ips = ['127.0.0.1', '::1'];

        // check each server var in order
        // accepted ip must be non null and not in the invalid_ips list
        foreach ($ordered_choices as $var) {
            if (isset($_SERVER[$var])) {
                $ip = $_SERVER[$var];
                if ($ip && !in_array($ip, $invalid_ips)) {
                    $ips = explode(',', $ip);
                    return reset($ips);
                }
            }
        }

        return null;
    }

    /**
     * Send the request to sixpack
     *
     * @throws \SeatGeek\Sixpack\Session\Exception\InvalidExperimentNameException
     *   if the experiment name is invalid
     */
    protected function sendRequest(string $endpoint, array $params = []): array
    {
        if (isset($params["experiment"]) && !preg_match('#^[a-z0-9][a-z0-9\-_ ]*$#i', $params["experiment"])) {
            throw new InvalidExperimentNameException($params["experiment"]);
        }

        $params = array_merge([
            'client_id' => $this->clientId,
            'ip_address' => $this->getIpAddress(),
            'user_agent' => $this->getUserAgent()
        ], $params);

        $url = $this->baseUrl . '/' . $endpoint;

        $params = preg_replace('/%5B(?:[0-9]+)%5D=/', '=', http_build_query($params));
        $url .= '?' . $params;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $this->timeout);

        // Make sub 1 sec timeouts work, according to: http://ravidhavlesha.wordpress.com/2012/01/08/curl-timeout-problem-and-solution/
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);

        $return = curl_exec($ch);
        $meta = curl_getinfo($ch);

        // handle failures in call dispatcher
        return [$return, $meta];
    }
}
