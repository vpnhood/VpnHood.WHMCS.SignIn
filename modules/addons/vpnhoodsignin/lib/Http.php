<?php

namespace WHMCS\Module\Addon\VpnHoodSignIn;

if (!defined('WHMCS') && !defined('VPNHOODSIGNIN_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Minimal cURL wrapper. The module makes exactly one outbound request —
 * fetching Google's published signing certificates — so this stays small on
 * purpose. Always sets a User-Agent (Google and any CDN in front of it can
 * reject UA-less requests) and enforces timeouts.
 */
class Http
{
    public const USER_AGENT = 'VpnHoodSignIn/1.0 (+WHMCS)';

    /**
     * @param array<string,string> $headers extra headers, name => value
     * @return array{status:int, body:string, json:?array}
     * @throws \RuntimeException on transport failure
     */
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeoutSeconds = 15
    ): array {
        $curlHeaders = ['User-Agent: ' . self::USER_AGENT];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = $name . ': ' . $value;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("HTTP request failed: $error ($method $url)");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $json = json_decode((string) $responseBody, true);
        return [
            'status' => $status,
            'body'   => (string) $responseBody,
            'json'   => is_array($json) ? $json : null,
        ];
    }
}
