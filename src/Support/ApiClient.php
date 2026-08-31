<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Thin JSON client used by the scenario scripts and the integration tests.
 */
final class ApiClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly float $timeout = 30.0,
    ) {
    }

    public static function api(): self
    {
        return new self((string) Env::get('API_URL', 'http://api:8080'));
    }

    public static function supplier(string $name): self
    {
        $key = 'SUPPLIER_' . strtoupper($name) . '_URL';

        return new self((string) Env::get($key, 'http://supplier-' . strtolower($name) . ':8080'));
    }

    /**
     * @param  array<string,mixed>|null $body
     * @param  array<string,string>     $headers
     * @return array{status:int,json:array<string,mixed>,raw:string}
     */
    public function request(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $ch = curl_init($this->baseUrl . $path);

        $hdr = ['Content-Type: application/json', 'Accept: application/json'];
        foreach ($headers as $k => $v) {
            $hdr[] = $k . ': ' . $v;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => (int) ceil($this->timeout),
            CURLOPT_HTTPHEADER     => $hdr,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException("{$method} {$path} failed: {$err}");
        }

        $json = json_decode((string) $raw, true);

        return ['status' => $status, 'json' => is_array($json) ? $json : [], 'raw' => (string) $raw];
    }

    /**
     * @param  array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    public function post(string $path, ?array $body = null, array $headers = []): array
    {
        return $this->request('POST', $path, $body, $headers)['json'];
    }

    /** @return array<string,mixed> */
    public function get(string $path): array
    {
        return $this->request('GET', $path)['json'];
    }

    public function waitForHealth(int $seconds = 60): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            try {
                if ($this->request('GET', '/health')['status'] === 200) {
                    return;
                }
            } catch (\Throwable) {
                // not up yet
            }
            usleep(300_000);
        }

        throw new \RuntimeException("Service {$this->baseUrl} did not become healthy");
    }
}
