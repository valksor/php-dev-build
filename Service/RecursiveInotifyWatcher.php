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

use RuntimeException;

use function array_key_exists;
use function array_keys;
use function basename;
use function closedir;
use function count;
use function function_exists;
use function inotify_add_watch;
use function inotify_init;
use function inotify_read;
use function inotify_rm_watch;
use function is_dir;
use function is_resource;
use function opendir;
use function readdir;
use function realpath;
use function rtrim;
use function stream_set_blocking;

use const DIRECTORY_SEPARATOR;
use const IN_ATTRIB;
use const IN_CLOSE_WRITE;
use const IN_CREATE;
use const IN_DELETE;
use const IN_DELETE_SELF;
use const IN_IGNORED;
use const IN_ISDIR;
use const IN_MOVE_SELF;
use const IN_MOVED_FROM;
use const IN_MOVED_TO;

/**
 * Recursive inotify-based file system watcher for hot reload functionality.
 *
 * This class provides efficient file system monitoring using Linux's inotify API.
 * It handles:
 * - Recursive directory watching with automatic new directory detection
 * - Efficient event-based file change notifications
 * - Automatic cleanup when directories are deleted
 * - Path filtering to ignore unwanted files and directories
 * - Retry mechanism for failed watch registrations
 * - Bidirectional mapping between paths and watch descriptors for efficient lookups
 *
 * The watcher is optimized for development scenarios where files change frequently
 * and performance is critical for responsive hot reload.
 */
final class RecursiveInotifyWatcher
{
    /**
     * Maximum number of watch descriptors to prevent OS limit exhaustion.
     * Most Linux systems have a default limit of 8192-65536 per user.
     * We use a conservative limit to ensure system stability.
     */
    private const int MAX_WATCH_DESCRIPTORS = 4096;

    /**
     * Inotify watch mask defining which file system events to monitor.
     *
     * Events watched:
     * - IN_ATTRIB: File metadata changes (permissions, ownership, etc.)
     * - IN_CLOSE_WRITE: File closed after being written to
     * - IN_CREATE: File/directory created in watched directory
     * - IN_DELETE: File/directory deleted from watched directory
     * - IN_DELETE_SELF: Watched file/directory was deleted
     * - IN_MOVE_SELF: Watched file/directory was moved
     * - IN_MOVED_FROM: File moved out of watched directory
     * - IN_MOVED_TO: File moved into watched directory
     *
     * Note: We don't watch IN_MODIFY to avoid getting events during file writes,
     * only when files are closed after writing (IN_CLOSE_WRITE).
     */
    private const int WATCH_MASK =
        IN_ATTRIB          // File attribute changes
        | IN_CLOSE_WRITE   // File written and closed
        | IN_CREATE        // File/directory created
        | IN_DELETE        // File/directory deleted
        | IN_DELETE_SELF   // Watched item deleted
        | IN_MOVE          // Watched item moved
        | IN_MOVE_SELF     // Watched item moved
        | IN_MOVED_FROM    // File moved from watched directory
        | IN_MOVED_TO;     // File moved to watched directory

    /**
     * Callback function invoked when a file change is detected.
     *
     * @var callable(string):void
     */
    private $callback;

    /**
     * Inotify instance resource for kernel communication.
     *
     * @var resource
     */
    private $inotify;

    /**
     * Mapping from file paths to inotify watch descriptors.
     * Enables quick lookup of existing watches for path checking.
     *
     * @var array<string,int>
     */
    private array $pathToWatchDescriptor = [];

    /**
     * Pending directory registrations that failed initially.
     * These are retried periodically to handle timing issues.
     *
     * @var array<string,bool>
     */
    private array $pendingRegistrations = [];

    /**
     * Set of registered root directories to prevent duplicate registrations.
     *
     * @var array<string,bool>
     */
    private array $registeredRoots = [];

    /**
     * Reverse mapping from inotify watch descriptors to file paths.
     * Used for efficient event processing when events reference descriptors.
     *
     * @var array<int,string>
     */
    private array $watchDescriptorToPath = [];

    /**
     * Initialize the inotify watcher with path filtering and change callback.
     *
     * @param PathFilter                  $filter   Filter for ignoring unwanted paths and directories
     * @param callable(string $path):void $onChange Callback invoked when file changes are detected
     *
     * @throws RuntimeException If inotify extension is not available or initialization fails
     */
    public function __construct(
        private readonly PathFilter $filter,
        callable $onChange,
    ) {
        // Verify inotify extension is available
        if (!function_exists('inotify_init')) {
            throw new RuntimeException('inotify extension is required but not available.');
        }

        // Initialize inotify instance for kernel communication
        $this->inotify = inotify_init();

        if (!is_resource($this->inotify)) {
            throw new RuntimeException('Failed to initialise inotify.');
        }

        // Set non-blocking mode to prevent the watcher from blocking the main process
        // This allows integration with event loops and stream_select()
        stream_set_blocking($this->inotify, false);

        $this->callback = $onChange;
    }

    /**
     * Destructor to ensure proper cleanup of inotify resources.
     *
     * This prevents resource leaks when the watcher is destroyed,
     * which is critical for long-running processes.
     */
    public function __destruct()
    {
        if (is_resource($this->inotify)) {
            // Clean up all watches before closing the inotify instance
            foreach ($this->watchDescriptorToPath as $descriptor => $path) {
                @inotify_rm_watch($this->inotify, $descriptor);
            }

            // Close the inotify instance to free file descriptors
            @fclose($this->inotify);
        }
    }

    public function addRoot(
        string $path,
    ): void {
        $real = $this->normalisePath($path);

        if (null === $real || isset($this->registeredRoots[$real])) {
            return;
        }

        $this->registeredRoots[$real] = true;
        $this->registerDirectoryRecursively($real);
    }

    public function getStream()
    {
        return $this->inotify;
    }

    public function poll(): void
    {
        // Read available inotify events from the kernel
        // Returns array of events or false if no events are available
        $events = inotify_read($this->inotify);

        if (false === $events || [] === $events) {
            // No events available - use this opportunity to retry failed registrations
            $this->retryPendingRegistrations();

            return;
        }

        // Process each inotify event
        foreach ($events as $event) {
            $this->handleEvent($event);
        }

        // Retry any pending directory registrations after processing events
        // New directories might have been created during event processing
        $this->retryPendingRegistrations();
    }

    private function addWatch(
        string $path,
    ): void {
        // Check if we've reached the maximum number of watch descriptors
        if (count($this->watchDescriptorToPath) >= self::MAX_WATCH_DESCRIPTORS) {
            // Note: Since this is a low-level service without direct IO access, we use
            // a simple approach to prevent OS limit exhaustion without logging
            return;
        }

        $descriptor = inotify_add_watch($this->inotify, $path, self::WATCH_MASK);

        if (false === $descriptor) {
            $this->pendingRegistrations[$path] = true;

            return;
        }

        $this->watchDescriptorToPath[$descriptor] = $path;
        $this->pathToWatchDescriptor[$path] = $descriptor;
    }

    /**
     * Handle a single inotify event, processing file system changes.
     *
     * This method implements the core event processing logic including:
     * - Event validation and path reconstruction
     * - Automatic directory registration for new subdirectories
     * - Watch cleanup when directories are deleted
     * - Path filtering and callback invocation
     *
     * @param array{wd:int,mask:int,name?:string} $event Inotify event data structure
     */
    private function handleEvent(
        array $event,
    ): void {
        $watchDescriptor = $event['wd'] ?? null;

        // Validate that we know about this watch descriptor
        // This can happen if the watch was removed but events are still pending
        if (!array_key_exists($watchDescriptor, $this->watchDescriptorToPath)) {
            return;
        }

        // Reconstruct the full path from watch descriptor and event data
        $basePath = $this->watchDescriptorToPath[$watchDescriptor];
        $name = $event['name'] ?? '';
        $fullPath = '' !== $name ? $basePath . DIRECTORY_SEPARATOR . $name : $basePath;

        // Handle IN_IGNORED events (watch was automatically removed by kernel)
        // This happens when the watched directory is deleted or the filesystem is unmounted
        if (($event['mask'] & IN_IGNORED) === IN_IGNORED) {
            $this->removeWatch($watchDescriptor, $basePath);

            return; // Don't notify for ignored events
        }

        // Handle directory creation events - automatically watch new subdirectories
        // This enables true recursive watching without manual rescanning
        if (($event['mask'] & IN_ISDIR) === IN_ISDIR) {
            if (($event['mask'] & (IN_CREATE | IN_MOVED_TO)) !== 0) {
                $this->registerDirectoryRecursively($fullPath);
            }
        }

        // Handle watched directory deletion or movement events
        // Clean up our internal tracking when the watched item disappears
        if (($event['mask'] & (IN_DELETE_SELF | IN_MOVE_SELF)) !== 0) {
            $this->removeWatch($watchDescriptor, $basePath);

            return; // Don't notify for self-deletion events
        }

        // Apply path filtering to ignore unwanted files and directories
        if ($this->filter->shouldIgnorePath($fullPath)) {
            return;
        }

        // Invoke the user callback with the full path to the changed file/directory
        ($this->callback)($fullPath);
    }

    private function normalisePath(
        string $path,
    ): ?string {
        $trimmed = rtrim($path, DIRECTORY_SEPARATOR);

        if ('' === $trimmed) {
            return null;
        }

        if (!is_dir($trimmed)) {
            return null;
        }

        $real = realpath($trimmed);

        return false === $real ? null : $real;
    }

    private function registerDirectoryRecursively(
        string $path,
    ): void {
        $path = $this->normalisePath($path);

        if (null === $path) {
            return;
        }

        if (isset($this->pathToWatchDescriptor[$path])) {
            return;
        }

        $basename = basename($path);

        // Check both directory name and full path exclusion
        if ('' !== $basename && $this->filter->shouldIgnoreDirectory($basename)) {
            return;
        }

        if ($this->filter->shouldIgnorePath($path)) {
            return;
        }

        $this->addWatch($path);
        $this->scanChildren($path);
    }

    private function removeWatch(
        int $descriptor,
        string $path,
    ): void {
        // Clean up kernel resources by removing the inotify watch
        // This prevents file descriptor leaks that cause restart loops
        @inotify_rm_watch($this->inotify, $descriptor);

        // Clean up internal mappings
        unset($this->watchDescriptorToPath[$descriptor], $this->pathToWatchDescriptor[$path]);
    }

    private function retryPendingRegistrations(): void
    {
        if ([] === $this->pendingRegistrations) {
            return;
        }

        foreach (array_keys($this->pendingRegistrations) as $path) {
            unset($this->pendingRegistrations[$path]);
            $this->registerDirectoryRecursively($path);
        }
    }

    private function scanChildren(
        string $directory,
    ): void {
        $handle = opendir($directory);

        if (false === $handle) {
            return;
        }

        try {
            while (($entry = readdir($handle)) !== false) {
                if ('.' === $entry || '..' === $entry) {
                    continue;
                }

                $child = $directory . DIRECTORY_SEPARATOR . $entry;
                $realChild = $this->normalisePath($child);

                if (null === $realChild || !is_dir($realChild)) {
                    continue;
                }

                $basename = basename($realChild);

                // Check both directory name and full path exclusion
                if ('' !== $basename && $this->filter->shouldIgnoreDirectory($basename)) {
                    continue;
                }

                if ($this->filter->shouldIgnorePath($realChild)) {
                    continue;
                }

                if (!isset($this->pathToWatchDescriptor[$realChild])) {
                    $this->addWatch($realChild);
                    $this->scanChildren($realChild);
                }
            }
        } finally {
            closedir($handle);
        }
    }
}
