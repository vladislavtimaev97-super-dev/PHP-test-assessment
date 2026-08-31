<?php

declare(strict_types=1);

/**
 * Reconciliation report as a CLI (the same data as GET /admin/reconciliation).
 *
 *   php bin/reconcile.php [--grace-seconds=20] [--json] [--verbose]
 *
 * Exit code 1 when the system is not consistent, so it can be a cron/CI check.
 */

use App\Service\ReconciliationService;
use App\Support\Cli;

require __DIR__ . '/../vendor/autoload.php';

$args   = Cli::args($argv);
$report = (new ReconciliationService())->report(
    isset($args['grace-seconds']) ? (int) $args['grace-seconds'] : null
);

if (isset($args['json'])) {
    Cli::line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    exit($report['healthy'] ? 0 : 1);
}

Cli::head('reconciliation @ ' . $report['generated_at']);

Cli::line('  totals');
foreach ($report['totals'] as $key => $value) {
    Cli::line(sprintf('    %-26s %s', $key, $value));
}

Cli::line();
Cli::line('  money journal (must sum to 0)');
foreach ($report['ledger_balance'] as $row) {
    Cli::line(sprintf('    %-26s %12s  (%s entries)', $row['account'], $row['balance_minor'], $row['entries']));
}

Cli::line();
Cli::line('  findings (all should be 0)');
foreach ($report['counts'] as $section => $count) {
    $line = sprintf('    %-26s %s', $section, $count);
    if ((int) $count > 0) {
        Cli::line("\033[33m{$line}\033[0m");
        if (isset($args['verbose'])) {
            foreach (array_slice($report['sections'][$section], 0, 10) as $row) {
                Cli::line('        ' . json_encode($row, JSON_UNESCAPED_UNICODE));
            }
        }
    } else {
        Cli::line($line);
    }
}

Cli::line();
Cli::line($report['healthy']
    ? "\033[32m  consistent\033[0m"
    : "\033[31m  INCONSISTENT — see the sections above\033[0m");
Cli::line();

exit($report['healthy'] ? 0 : 1);
