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
 * Path filtering utility for file system monitoring and build processes.
 *
 * This class provides intelligent file and directory filtering to optimize
 * file watching performance and prevent unnecessary processing of irrelevant
 * files. It's used extensively by the RecursiveInotifyWatcher and build services
 * to focus on source files while ignoring noise.
 *
 * Filtering Strategy:
 * - Directory-based filtering for large dependency folders (node_modules, vendor)
 * - File extension filtering for non-source files (.md, .log, etc.)
 * - Filename filtering for specific configuration files (.gitignore, .gitkeep)
 * - Glob pattern matching for complex path exclusions
 *
 * Performance Benefits:
 * - Reduces inotify watch descriptors by excluding irrelevant directories
 * - Minimizes file system events from build artifacts and dependencies
 * - Improves hot reload responsiveness by focusing on source files only
 * - Prevents infinite loops from watching build output directories
 *
 * Default Ignore Patterns:
 * - Dependencies: node_modules, vendor
 * - Build artifacts: public, var
 * - Development tools: .git, .idea, .webpack-cache
 * - Documentation: *.md files
 * - Git files: .gitignore, .gitkeep
 */
final class PathFilter
{
    /**
     * List of directory names to ignore during file system traversal.
     * These directories typically contain dependencies, build artifacts, or
     * - development tools that shouldn't trigger rebuilds when modified.
     *
     * @var array<string>
     */
    private array $ignoredDirectories;

    /**
     * List of file extensions to ignore (with dot prefix).
     * These extensions typically represent non-source files like documentation,
     * logs, or temporary files that don't affect the build process.
     *
     * @var array<string>
     */
    private array $ignoredExtensions;

    /**
     * List of specific filenames to ignore.
     * These are typically configuration files or meta-files that don't contain
     * source code but might be frequently updated by development tools.
     *
     * @var array<string>
     */
    private array $ignoredFilenames;

    /**
     * List of glob patterns for advanced path filtering.
     * These patterns support wildcards and recursive matching for complex
     * exclusion scenarios like "ignore all files in any node_modules directory".
     *
     * @var array<string>
     */
    private readonly array $ignoredGlobs;

    /**
     * Initialize the path filter with ignore patterns.
     *
     * The constructor receives four categories of ignore patterns and normalizes
     * them to lowercase for case-insensitive matching. This ensures consistent
     * behavior across different operating systems and file systems.
     *
     * @param array $directories List of directory names to ignore
     * @param array $globs       List of glob patterns for complex path matching
     * @param array $filenames   List of specific filenames to ignore
     * @param array $extensions  List of file extensions to ignore
     */
    private function __construct(
        array $directories,
        array $globs,
        array $filenames,
        array $extensions,
    ) {
        $this->ignoredDirectories = array_map('strtolower', $directories);
        $this->ignoredGlobs = $globs;
        $this->ignoredFilenames = array_map('strtolower', $filenames);
        $this->ignoredExtensions = array_map('strtolower', $extensions);
    }

    /**
     * Check if a directory should be ignored during file system traversal.
     *
     * This method is used by file watchers and directory scanners to determine
     * whether to descend into a directory. Ignoring large dependency directories
     * (node_modules, vendor) significantly improves performance and reduces
     * inotify watch descriptor usage.
     *
     * Common ignored directories:
     * - node_modules: JavaScript dependencies (thousands of files)
     * - vendor: PHP/Composer dependencies
     * - public: Build output and static assets
     * - var: Symfony cache and log files
     * - .git: Git repository metadata
     * - .idea: IDE configuration files
     *
     * @param string $basename Directory basename (without path)
     *
     * @return bool True if directory should be ignored, false if it should be watched
     */
    public function shouldIgnoreDirectory(
        string $basename,
    ): bool {
        return in_array(strtolower($basename), $this->ignoredDirectories, true);
    }

    /**
     * Check if a file path should be ignored during file system monitoring.
     *
     * This method implements comprehensive path filtering using multiple strategies
     * to determine if a file should trigger build processes. It combines filename
     * matching, extension filtering, and glob pattern matching for maximum flexibility.
     *
     * Filtering Strategy (in order of evaluation):
     * 1. Basic validation for null/empty paths
     * 2. Filename matching for specific files (.gitignore, .gitkeep)
     * 3. Extension filtering for file types (.md, .log, etc.)
     * 4. Glob pattern matching for complex path scenarios
     *
     *
     * Performance Considerations:
     * - Simple checks (filename, extension) are performed first
     * - Expensive glob matching is performed last
     * - Case-insensitive matching for cross-platform compatibility
     *
     * @param string|null $path Full file path to check
     *
     * @return bool True if file should be ignored, false if it should trigger rebuilds
     */
    public function shouldIgnorePath(
        ?string $path,
    ): bool {
        // Basic validation - handle null or empty paths gracefully
        if (null === $path || '' === $path) {
            return false;
        }

        // Check filename against ignored filenames list
        // This catches specific files like .gitignore, .gitkeep that shouldn't trigger builds
        $basename = strtolower(pathinfo($path, PATHINFO_BASENAME));

        if ('' !== $basename && in_array($basename, $this->ignoredFilenames, true)) {
            return true;
        }

        // Check file extension against ignored extensions list
        // This efficiently filters out entire file categories like documentation
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ('' !== $extension && in_array('.' . $extension, $this->ignoredExtensions, true)) {
            return true;
        }

        // Check against glob patterns for complex path matching
        // This handles advanced scenarios like "any file in any node_modules directory"
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
