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

namespace ValksorDev\Build\Service\Tailwind;

use ValksorDev\Build\Config\ProjectStructureConfig;
use ValksorDev\Build\Watcher\PathFilter;

use function array_unique;
use function array_values;
use function closedir;
use function dirname;
use function is_dir;
use function opendir;
use function preg_match;
use function preg_replace;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

use const DIRECTORY_SEPARATOR;

/**
 * Service for discovering Tailwind CSS source files.
 */
final class TailwindSourceDiscovery
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly ProjectStructureConfig $projectStructure,
        private readonly PathFilter $filter,
    ) {
    }

    /**
     * @return array<int,array{input:string,output:string,relative_input:string,relative_output:string,label:string,watchRoots:array<int,string>}>
     */
    public function collectTailwindSources(
        ?string $activeAppId = null,
        bool $includeAllApps = false,
    ): array {
        $sources = [];

        // Multi-app project structure
        if ($includeAllApps) {
            $appsDir = $this->projectStructure->getAppsPath($this->projectRoot);

            if (is_dir($appsDir)) {
                $handle = opendir($appsDir);

                if (false !== $handle) {
                    try {
                        while (($entry = readdir($handle)) !== false) {
                            if ('.' === $entry || '..' === $entry) {
                                continue;
                            }

                            if ($this->filter->shouldIgnoreDirectory($entry)) {
                                continue;
                            }

                            $appRoot = $appsDir . DIRECTORY_SEPARATOR . $entry;

                            if (!is_dir($appRoot)) {
                                continue;
                            }

                            $this->discoverSources($appRoot, $sources);
                        }
                    } finally {
                        closedir($handle);
                    }
                }
            }
        } elseif (null !== $activeAppId) {
            $appRoot = $this->projectStructure->getAppsPath($this->projectRoot) . '/' . $activeAppId;

            if (is_dir($appRoot)) {
                $this->discoverSources($appRoot, $sources);
            }
        }

        return $sources;
    }

    /**
     * @return array{input:string,output:string,relative_input:string,relative_output:string,label:string,watchRoots:array<int,string>}
     */
    private function createSourceDefinition(
        string $inputPath,
    ): array {
        $relativeInput = trim(str_replace('\\', '/', substr($inputPath, strlen($this->projectRoot))), '/');
        $outputPath = preg_replace('/\.tailwind\.css$/', '.css', $inputPath);
        $relativeOutput = trim(str_replace('\\', '/', substr($outputPath, strlen($this->projectRoot))), '/');

        $label = $relativeInput;
        $watchRoots = [];

        // Multi-app project structure
        if (1 === preg_match('#^apps/([^/]+)/#', $relativeInput, $matches)) {
            $appName = $matches[1];
            $label = $appName;
            $watchRoots[] = $this->projectRoot . '/apps/' . $appName;

            // Include shared directory if it exists
            if (is_dir($this->projectRoot . '/shared')) {
                $watchRoots[] = $this->projectRoot . '/shared';
            }
        } elseif (str_starts_with($relativeInput, 'shared/')) {
            $label = 'shared';
            $watchRoots[] = $this->projectRoot . '/shared';
        } else {
            $watchRoots[] = dirname($inputPath);
        }

        return [
            'input' => $inputPath,
            'output' => $outputPath,
            'relative_input' => $relativeInput,
            'relative_output' => $relativeOutput,
            'label' => $label,
            'watchRoots' => array_values(array_unique($watchRoots)),
        ];
    }

    /**
     * @param array<int,array{input:string,output:string,relative_input:string,relative_output:string,label:string,watchRoots:array<int,string>}> $sources
     */
    private function discoverSources(
        string $directory,
        array &$sources,
    ): void {
        if (!is_dir($directory)) {
            return;
        }

        $handle = opendir($directory);

        if (false === $handle) {
            return;
        }

        try {
            while (($entry = readdir($handle)) !== false) {
                if ('.' === $entry || '..' === $entry) {
                    continue;
                }

                $full = $directory . DIRECTORY_SEPARATOR . $entry;

                if (is_dir($full)) {
                    if ($this->filter->shouldIgnoreDirectory($entry)) {
                        continue;
                    }

                    $this->discoverSources($full, $sources);

                    continue;
                }

                if (!str_ends_with($entry, '.tailwind.css')) {
                    continue;
                }

                $sources[] = $this->createSourceDefinition($full);
            }
        } finally {
            closedir($handle);
        }
    }
}
