<?php declare(strict_types = 1);

/*
 * This file is part of the Valksor package.
 *
 * (c) Davis Zalitis (k0d3r1s)
 * (c) SIA Valksor <packages@valksor.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ValksorDev\Build\Util;

use Symfony\Component\Process\Process;

use function is_string;

/**
 * Utility class for building console commands with standardized options.
 *
 * This class eliminates the repetition of 'php bin/console' string construction
 * across providers by providing a fluent interface for command building.
 */
final class ConsoleCommandBuilder
{
    public const CONSOLE_COMMAND = 'bin/console';
    public const PHP_CONSOLE = 'php';

    /**
     * Build a console command process with options or arguments.
     *
     * @param string $command The console command (e.g., 'valksor:tailwind' or 'assets:install')
     * @param array  $options Command options:
     *                        For valksor commands:
     *                        - 'app' (string): Application ID for multi-app setup
     *                        - 'minify' (bool): Whether to add minification flag
     *                        - 'watch' (bool): Whether to add watch flag
     *                        For generic commands:
     *                        - Array of command-line arguments (e.g., ['--relative', '--no-interaction'])
     *
     * @return Process The configured process ready to run
     */
    public function build(
        string $command,
        array $options = [],
    ): Process {
        $arguments = [self::PHP_CONSOLE, self::CONSOLE_COMMAND, $command];

        // Handle valksor-specific options
        if (isset($options['app'])) {
            $arguments[] = '--id=' . $options['app'];
        }

        if ($options['minify'] ?? false) {
            $arguments[] = '--minify';
        }

        if ($options['watch'] ?? false) {
            $arguments[] = '--watch';
        }

        // Handle generic command arguments (non-valksor commands)
        // Check if this is a valksor command or generic command by looking for known options
        if (!str_starts_with($command, 'valksor:') && !str_starts_with($command, 'valksor-prod:')) {
            // Add all options as command-line arguments for generic commands
            foreach ($options as $option) {
                if (is_string($option)) {
                    $arguments[] = $option;
                }
            }
        }

        return new Process($arguments);
    }

    /**
     * Build command arguments array for use with ProcessManager::executeProcess().
     *
     * @param string $command The console command (e.g., 'valksor:tailwind')
     * @param array  $options Command options (same as build())
     *
     * @return array<string> Command arguments excluding 'php bin/console'
     */
    public function buildArguments(
        string $command,
        array $options = [],
    ): array {
        $arguments = [$command];

        // Add app ID if specified
        if (isset($options['app'])) {
            $arguments[] = '--id=' . $options['app'];
        }

        // Add minification flag
        if ($options['minify'] ?? false) {
            $arguments[] = '--minify';
        }

        // Add watch flag
        if ($options['watch'] ?? false) {
            $arguments[] = '--watch';
        }

        return $arguments;
    }
}
