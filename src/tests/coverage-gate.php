<?php

declare(strict_types=1);

/**
 * Fails when measured line coverage drops below the agreed minimum.
 *
 * Usage: php tests/coverage-gate.php coverage/clover.xml [minimum-percent]
 * Run through `composer test:coverage:check`, which produces the Clover report first.
 */

$file = $argv[1] ?? 'coverage/clover.xml';
$minimum = (float) ($argv[2] ?? 90);
if (!is_file($file)) {
    fwrite(STDERR, "Coverage report not found: {$file}\n");
    exit(2);
}
$report = simplexml_load_file($file);
if ($report === false) {
    fwrite(STDERR, "Coverage report is not readable XML: {$file}\n");
    exit(2);
}

$statements = 0;
$covered = 0;
foreach ($report->xpath('//file/metrics') ?: [] as $metrics) {
    $statements += (int) $metrics['statements'];
    $covered += (int) $metrics['coveredstatements'];
}
if ($statements === 0) {
    fwrite(STDERR, "The coverage report contains no measured statements.\n");
    exit(2);
}

$percent = 100 * $covered / $statements;
printf("Line coverage: %.2f%% (%d/%d); minimum %.2f%%\n", $percent, $covered, $statements, $minimum);
exit($percent + 1e-9 >= $minimum ? 0 : 1);
