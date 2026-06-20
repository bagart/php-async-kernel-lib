<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\ASK;
use BAGArt\AsyncKernel\AsyncKernel;
use BAGArt\AsyncKernel\CliActions;
use BAGArt\AsyncKernel\Daemons\ASKFnDaemon;
use BAGArt\AsyncKernel\Daemons\ASKFnDaemonContext;
use BAGArt\AsyncKernel\Exceptions\ASKInterruptException;
use BAGArt\AsyncKernel\Promise\ASKPromiseResolver;
use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;

require_once __DIR__.'/../../../../vendor/autoload.php';

function abs_cmp(string $a, string $b): int
{
    $a = ltrim($a, '0') ?: '0';
    $b = ltrim($b, '0') ?: '0';
    $la = strlen($a);
    $lb = strlen($b);
    if ($la !== $lb) {
        return $la <=> $lb;
    }
    return $a <=> $b;
}

function big_add(string $a, string $b): string
{
    $negA = $a[0] === '-';
    $negB = $b[0] === '-';
    $absA = $negA ? substr($a, 1) : $a;
    $absB = $negB ? substr($b, 1) : $b;

    if ($negA === $negB) {
        $i = strlen($absA) - 1;
        $j = strlen($absB) - 1;
        $carry = 0;
        $r = '';
        while ($i >= 0 || $j >= 0 || $carry) {
            $s = $carry + ($i >= 0 ? (int)$absA[$i--] : 0) + ($j >= 0 ? (int)$absB[$j--] : 0);
            $carry = intdiv($s, 10);
            $r .= $s % 10;
        }
        return ($negA ? '-' : '') . strrev($r);
    }

    if (abs_cmp($absA, $absB) >= 0) {
        return ($negA ? '-' : '') . raw_sub($absA, $absB);
    }
    return ($negB ? '-' : '') . raw_sub($absB, $absA);
}

function big_sub(string $a, string $b): string
{
    if ($b[0] === '-') {
        return big_add($a, substr($b, 1));
    }
    return big_add($a, '-' . $b);
}

function big_mul(string $a, string $b): string
{
    if ($a === '0' || $b === '0') {
        return '0';
    }
    $negA = $a[0] === '-';
    $negB = $b[0] === '-';
    $absA = $negA ? substr($a, 1) : $a;
    $absB = $negB ? substr($b, 1) : $b;

    $la = strlen($absA);
    $lb = strlen($absB);
    $r = array_fill(0, $la + $lb, 0);
    for ($i = $la - 1; $i >= 0; $i--) {
        $da = (int)$absA[$i];
        for ($j = $lb - 1; $j >= 0; $j--) {
            $p = $da * (int)$absB[$j] + $r[$i + $j + 1];
            $r[$i + $j + 1] = $p % 10;
            $r[$i + $j] += intdiv($p, 10);
        }
    }
    $absResult = ltrim(implode('', $r), '0') ?: '0';
    return ($negA xor $negB) ? '-' . $absResult : $absResult;
}

function big_mul_int(string $a, int $b): string
{
    if ($b === 0 || $a === '0') {
        return '0';
    }
    if ($b === 1) {
        return $a;
    }
    if ($b === -1) {
        return $a[0] === '-' ? substr($a, 1) : '-' . $a;
    }

    $negA = $a[0] === '-';
    $absA = $negA ? substr($a, 1) : $a;
    $absB = abs($b);

    $i = strlen($absA) - 1;
    $carry = 0;
    $r = '';
    while ($i >= 0 || $carry) {
        $p = ($i >= 0 ? (int)$absA[$i--] : 0) * $absB + $carry;
        $r .= $p % 10;
        $carry = intdiv($p, 10);
    }
    return ($negA xor ($b < 0)) ? '-' . strrev($r) : strrev($r);
}

function big_lt(string $a, string $b): bool
{
    if ($a[0] === '-' && $b[0] !== '-') {
        return true;
    }
    if ($a[0] !== '-' && $b[0] === '-') {
        return false;
    }
    if ($a[0] === '-') {
        return abs_cmp(substr($a, 1), substr($b, 1)) > 0;
    }
    return abs_cmp($a, $b) < 0;
}

function raw_sub(string $a, string $b): string
{
    $i = strlen($a) - 1;
    $j = strlen($b) - 1;
    $borrow = 0;
    $r = '';
    while ($i >= 0) {
        $d = (int)$a[$i--] - $borrow - ($j >= 0 ? (int)$b[$j--] : 0);
        if ($d < 0) {
            $d += 10;
            $borrow = 1;
        } else {
            $borrow = 0;
        }
        $r .= $d;
    }
    $r = ltrim(strrev($r), '0');
    return $r === '' ? '0' : $r;
}

function big_div(string $a, string $b): string
{
    $negA = $a[0] === '-';
    $negB = $b[0] === '-';
    $absA = $negA ? substr($a, 1) : $a;
    $absB = $negB ? substr($b, 1) : $b;

    if (abs_cmp($absA, $absB) < 0) {
        return '0';
    }

    $result = '';
    $remainder = '';
    $len = strlen($absA);

    for ($i = 0; $i < $len; $i++) {
        $remainder .= $absA[$i];
        $remainder = ltrim($remainder, '0') ?: '0';

        $digit = 0;
        while (abs_cmp($remainder, $absB) >= 0) {
            $remainder = raw_sub($remainder, $absB);
            $digit++;
        }
        $result .= (string)$digit;
    }

    $result = ltrim($result, '0') ?: '0';
    return ($negA xor $negB) ? '-' . $result : $result;
}

$definedOptions = [
    'interval::',
    'memory-limit::',
    'log-level::',
    'help',
];

$options = CliActions::parseOptions(
    getopt('', $definedOptions),
    $definedOptions
);

CliActions::initRuntime($options);

if (isset($options['help'])) {
    echo "Usage:
php commands/example-daemon.php                       # Default: process every 1s
php commands/example-daemon.php --interval=5          # Process every 5s

Options:
  --interval=N                            Seconds between processing cycles (default: 1)
  --memory-limit=512M                     PHP memory limit (default: 512M)
  --log-level=debug|info|warning|error    minimum log level (default: info)
  --help
";

    exit(0);
}

$interval = (int)($options['interval'] ?? 1);
$logLevel = (string)($options['log-level'] ?? null) ?: ASKLogWrapper::LEVEL_DEFAULT;

$kernelLogger = new ASKLogWrapper(minLevel: $logLevel);

$kernel = new AsyncKernel($kernelLogger);
$promiseResolver = new ASKPromiseResolver();
$kernel->addTickable($promiseResolver);

$fnError = static function (\Throwable $e, ASKFnDaemonContext $context): void {
    $context->logger->error("[{$context->daemonName}] error: {$e->getMessage()}");
};

$piState = new stdClass();
$piState->q = '1';
$piState->r = '0';
$piState->t = '1';
$piState->k = '1';
$piState->n = '3';
$piState->l = '3';
$piState->digits = '';
$piState->computed = 0;

$kernel
    ->addDaemon(
        daemon: new ASKFnDaemon(
            daemonContext: new ASKFnDaemonContext(
                daemonName: 'pi-worker',
                logger: $kernelLogger,
            ),
            fnProduce: static function (ASKFnDaemonContext $context) use ($piState): void {
                if ($piState->computed > 100) {
                    echo "\n";
                    throw new ASKInterruptException(
                        source: 'pi completed',
                    );
                }
                $q = $piState->q;
                $r = $piState->r;
                $t = $piState->t;
                $k = $piState->k;
                $n = $piState->n;
                $l = $piState->l;

                while (true) {
                    $test = big_sub(big_add(big_mul_int($q, 4), $r), $t);

                    if (big_lt($test, big_mul($n, $t))) {
                        //$piState->digits .= $n;
                        if ($piState->computed === 1) {
                            //ASK::sleep(100)->await();
                            echo '.';
                        }
                        $piState->computed++;
                        echo $n;

                        $nr = big_mul_int(big_sub($r, big_mul($n, $t)), 10);
                        $n = big_sub(
                            big_div(big_mul_int(big_add(big_mul_int($q, 3), $r), 10), $t),
                            big_mul_int($n, 10),
                        );
                        $q = big_mul_int($q, 10);
                        $r = $nr;

                        $piState->q = $q;
                        $piState->r = $r;
                        $piState->t = $t;
                        $piState->k = $k;
                        $piState->n = $n;
                        $piState->l = $l;

                        return;
                    }

                    $nr = big_mul(big_add(big_mul_int($q, 2), $r), $l);
                    $nn = big_div(
                        big_add(
                            big_mul($q, big_add(big_mul_int($k, 7), '2')),
                            big_mul($r, $l),
                        ),
                        big_mul($t, $l),
                    );

                    $q = big_mul($q, $k);
                    $t = big_mul($t, $l);
                    $l = big_add($l, '2');
                    $k = big_add($k, '1');
                    $n = $nn;
                    $r = $nr;
                }
            },
            fnError: $fnError,
        ),
        producerInterval: $interval,
    )
    ->run();
