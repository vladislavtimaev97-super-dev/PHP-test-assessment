<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Integration tests talk to the running stack; unit tests do not care.
if (getenv('SKIP_HEALTHCHECK') !== '1') {
    try {
        \App\Support\ApiClient::api()->waitForHealth(60);
        \App\Support\ApiClient::supplier('A')->waitForHealth(60);
        \App\Support\ApiClient::supplier('B')->waitForHealth(60);
    } catch (\Throwable $e) {
        fwrite(STDERR, "warning: stack not reachable ({$e->getMessage()}); integration tests will fail\n");
    }
}
