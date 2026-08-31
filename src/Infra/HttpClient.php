<?php

declare(strict_types=1);

namespace App\Infra;

final class HttpClient
{
    public function __construct(
        private readonly float $timeoutSeconds = 2.0,
        private readonly float $connectTimeoutSeconds = 0.5,
    ) {
    }

    /** @param array<string,mixed> $body */
    public function postJson(string $url, array $body): HttpResult
    {
        return $this->send('POST', $url, $body);
    }

    public function getJson(string $url): HttpResult
    {
        return $this->send('GET', $url, null);
    }

    /** @param array<string,mixed>|null $body */
    private function send(string $method, string $url, ?array $body): HttpResult
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS     => (int) round($this->timeoutSeconds * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round($this->connectTimeoutSeconds * 1000),
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Trace-Id: ' . Log::traceId(),
            ],
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $opts);

        $started = microtime(true);
        $raw     = curl_exec($ch);
        $ms      = (int) round((microtime(true) - $started) * 1000);
        $errno   = curl_errno($ch);
        $status  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $connect = (float) curl_getinfo($ch, CURLINFO_CONNECT_TIME);
        $err     = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            // Never connected => the supplier never saw the request => safe to fail over.
            $neverConnected = in_array($errno, [
                CURLE_COULDNT_CONNECT,
                CURLE_COULDNT_RESOLVE_HOST,
                CURLE_COULDNT_RESOLVE_PROXY,
            ], true) || ($errno === CURLE_OPERATION_TIMEDOUT && $connect <= 0.0);

            if ($neverConnected) {
                return HttpResult::unavailable($ms, sprintf('curl(%d): %s', $errno, $err));
            }

            // Connected, then no answer in time => UNKNOWN, not a failure.
            return HttpResult::timeout($ms, sprintf('curl(%d): %s', $errno, $err));
        }

        $json = null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $json = $decoded;
            }
        }

        return HttpResult::response($status, $json, is_string($raw) ? $raw : null, $ms);
    }
}
