<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Sends payment webhooks. All requests of one batch are started with
 * curl_multi, so they arrive together instead of one after another — that is
 * what makes the race reproducible rather than theoretical.
 */
final class WebhookSender
{
    public function __construct(private readonly string $url)
    {
    }

    public static function default(): self
    {
        return new self((string) Env::get('API_URL', 'http://api:8080') . '/webhooks/payment');
    }

    /**
     * @param  string $mode 'same' = one event_id repeated (redelivery),
     *                      'distinct' = N different events for one order
     * @return list<array<string,mixed>>
     */
    public static function payloads(
        string $orderId,
        float $amount,
        int $count,
        string $mode = 'same',
        string $status = 'paid',
        ?string $eventId = null,
        ?string $createdAt = null,
        string $currency = 'RUB',
    ): array {
        $base = $eventId ?? ('evt_' . bin2hex(random_bytes(5)));

        $out = [];
        for ($i = 0; $i < max(1, $count); $i++) {
            $out[] = [
                'event_id'   => $mode === 'distinct' ? sprintf('%s_%02d', $base, $i) : $base,
                'order_id'   => $orderId,
                'status'     => $status,
                'amount'     => $amount == (int) $amount ? (int) $amount : $amount,
                'currency'   => $currency,
                'created_at' => $createdAt ?? gmdate('Y-m-d\TH:i:s\Z'),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>> $payloads
     * @return array{http:array<int,int>,results:array<string,int>,errors:int,elapsed_ms:int}
     */
    public function sendConcurrently(array $payloads): array
    {
        $multi   = curl_multi_init();
        $handles = [];

        foreach ($payloads as $payload) {
            $ch = curl_init($this->url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 30,
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[] = $ch;
        }

        $started = microtime(true);
        do {
            $status = curl_multi_exec($multi, $active);
            if ($active) {
                curl_multi_select($multi, 1.0);
            }
        } while ($active && $status === CURLM_OK);

        $http    = [];
        $results = [];
        $errors  = 0;

        foreach ($handles as $ch) {
            $raw  = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err  = curl_error($ch);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);

            if ($err !== '' || $raw === null) {
                $errors++;
                continue;
            }

            $http[$code] = ($http[$code] ?? 0) + 1;
            $json        = json_decode((string) $raw, true);
            $result      = is_array($json) ? (string) ($json['result'] ?? 'n/a') : 'n/a';
            $results[$result] = ($results[$result] ?? 0) + 1;
        }
        curl_multi_close($multi);

        return [
            'http'       => $http,
            'results'    => $results,
            'errors'     => $errors,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }
}
