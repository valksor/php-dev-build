<?php declare(strict_types = 1);

/*
 * This file is part of the Valksor package.
 *
 * (c) Dāvis Zālītis (k0d3r1s)
 * (c) SIA Valksor <packages@valksor.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ValksorDev\Build\Service;

use function array_any;
use function array_map;
use function fnmatch;
use function in_array;
use function pathinfo;
use function strtolower;

use const FNM_NOESCAPE;
use const FNM_PATHNAME;
use const PATHINFO_BASENAME;
use const PATHINFO_EXTENSION;

/**
 * Provides ignore checks for directories and files based on patterns borrowed
 * from the legacy Bun implementation.
 */
final class PathFilter
{
    private array $ignoredDirectories;
    private array $ignoredExtensions;
    private array $ignoredFilenames;

    private function __construct(
        array $directories,
        private readonly array $ignoredGlobs,
        array $filenames,
        array $extensions,
    ) {
        $this->ignoredDirectories = array_map('strtolower', $directories);
        $this->ignoredFilenames = array_map('strtolower', $filenames);
        $this->ignoredExtensions = array_map('strtolower', $extensions);
    }

    public function shouldIgnoreDirectory(
        string $basename,
    ): bool {
        return in_array(strtolower($basename), $this->ignoredDirectories, true);
    }

    public function shouldIgnorePath(
        ?string $path,
    ): bool {
        if (null === $path || '' === $path) {
            return false;
        }

        $basename = strtolower(pathinfo($path, PATHINFO_BASENAME));

        if ('' !== $basename && in_array($basename, $this->ignoredFilenames, true)) {
            return true;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ('' !== $extension && in_array('.' . $extension, $this->ignoredExtensions, true)) {
            return true;
        }

        return array_any($this->ignoredGlobs, static fn ($glob) => fnmatch($glob, $path, FNM_PATHNAME | FNM_NOESCAPE));
    }

    public static function createDefault(): self
    {
        return new self(
            ['node_modules', 'vendor', 'public', 'var', '.git', '.idea', '.webpack-cache'],
            ['**/node_modules/**', '**/vendor/**', '**/public/**', '**/var/**', '**/.git/**', '**/.idea/**', '**/.webpack-cache/**', '**/*.md', '**/.gitignore', '**/.gitkeep'],
            ['.gitignore', '.gitkeep'],
            ['.md'],
        );
    }
}
