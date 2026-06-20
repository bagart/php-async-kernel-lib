<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel;

final class CliActions
{
    /**
     * Validate parsed CLI options – reject unknown --options.
     *
     * @param  array<string, string|bool>  $options  result of getopt('', $definedOptions)
     * @param  string[]  $definedOptions  getopt definitions, e.g. ['token::', 'echo', 'help']
     * @return array<string, string|bool>  validated options
     */
    public static function parseOptions(array $options, array $definedOptions): array
    {
        $knownNames = array_map(
            fn (string $opt) => preg_replace('/[:]+$/', '', $opt),
            $definedOptions,
        );

        foreach ($_SERVER['argv'] as $arg) {
            if (!str_starts_with($arg, '--')) {
                continue;
            }

            $name = explode('=', substr($arg, 2), 2)[0];
            $nameWithoutValue = rtrim($name, ':');

            if (!in_array($nameWithoutValue, $knownNames, true)) {
                echo "Error: Unknown option --{$nameWithoutValue}\n";

                exit(1);
            }
        }

        return $options;
    }

    /**
     * Initialize PHP runtime for CLI commands: memory_limit, output flushing.
     *
     * @param  array<string, string|bool>  $options  parsed options from parseOptions()
     */
    public static function initRuntime(array $options): void
    {
        ini_set('memory_limit', (string)($options['memory-limit'] ?? '512M'));

        ob_implicit_flush(true);
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $signals = [];
        foreach (['SIGINT', 'SIGTERM'] as $name) {
            if (defined($name)) {
                $signals[] = constant($name);
            }
        }
        if ($signals !== []) {
            SignalTriggers::register(
                signals: $signals,
                onGraceful: static function (): void {
                },
                onForce: static function (): void {
                },
            );
        }
    }
}
