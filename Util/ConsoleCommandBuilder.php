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

/**
 * Utility class for building console commands with standardized options.
 *
 * This class eliminates the repetition of 'php bin/console' string construction
 * across providers by providing a fluent interface for command building.
 */
final class ConsoleCommandBuilder
{
    /**
     * Build a console command process with standardized options.
     *
     * @param string $command   The console command (e.g., 'valksor:tailwind')
     * @param array  $options   Command options:
     *                          - 'app' (string): Application ID for multi-app setup
     *                          - 'minify' (bool): Whether to add minification flag
     *                          - 'watch' (bool): Whether to add watch flag
     *
     * @return Process The configured process ready to run
     */
    public function build(
        string $command,
        array $options = [],
    ): Process {
        $arguments = ['php', 'bin/console', $command];

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