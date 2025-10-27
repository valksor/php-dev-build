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

use RuntimeException;

use function array_key_exists;
use function array_keys;
use function basename;
use function closedir;
use function function_exists;
use function inotify_add_watch;
use function inotify_init;
use function inotify_read;
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
 * Recursively registers directories with inotify and emits callbacks for file
 * changes. Automatically tracks new directories and cleans up when watched
 * paths are removed.
 */
final class RecursiveInotifyWatcher
{
    private const int WATCH_MASK =
        IN_ATTRIB
        | IN_CLOSE_WRITE
        | IN_CREATE
        | IN_DELETE
        | IN_DELETE_SELF
        | IN_MOVE_SELF
        | IN_MOVED_FROM
        | IN_MOVED_TO;

    /** @var callable */
    private $callback;

    /** @var resource */
    private $inotify;

    /** @var array<string,int> */
    private array $pathToWatchDescriptor = [];

    /** @var array<string,bool> */
    private array $pendingRegistrations = [];

    /** @var array<string,bool> */
    private array $registeredRoots = [];

    /** @var array<int,string> */
    private array $watchDescriptorToPath = [];

    /**
     * @param callable(string $path):void $onChange
     */
    public function __construct(
        private readonly PathFilter $filter,
        callable $onChange,
    ) {
        if (!function_exists('inotify_init')) {
            throw new RuntimeException('inotify extension is required but not available.');
        }

        $this->inotify = inotify_init();

        if (!is_resource($this->inotify)) {
            throw new RuntimeException('Failed to initialise inotify.');
        }

        stream_set_blocking($this->inotify, false);

        $this->callback = $onChange;
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
        $events = inotify_read($this->inotify);

        if (false === $events || [] === $events) {
            $this->retryPendingRegistrations();

            return;
        }

        foreach ($events as $event) {
            $this->handleEvent($event);
        }

        $this->retryPendingRegistrations();
    }

    private function addWatch(
        string $path,
    ): void {
        $descriptor = inotify_add_watch($this->inotify, $path, self::WATCH_MASK);

        if (false === $descriptor) {
            $this->pendingRegistrations[$path] = true;

            return;
        }

        $this->watchDescriptorToPath[$descriptor] = $path;
        $this->pathToWatchDescriptor[$path] = $descriptor;
    }

    /**
     * @param array{wd:int,mask:int,name?:string} $event
     */
    private function handleEvent(
        array $event,
    ): void {
        $watchDescriptor = $event['wd'] ?? null;

        if (!array_key_exists($watchDescriptor, $this->watchDescriptorToPath)) {
            return;
        }

        $basePath = $this->watchDescriptorToPath[$watchDescriptor];
        $name = $event['name'] ?? '';
        $fullPath = '' !== $name ? $basePath . DIRECTORY_SEPARATOR . $name : $basePath;

        if (($event['mask'] & IN_IGNORED) === IN_IGNORED) {
            $this->removeWatch($watchDescriptor, $basePath);

            return;
        }

        if (($event['mask'] & IN_ISDIR) === IN_ISDIR) {
            if (($event['mask'] & (IN_CREATE | IN_MOVED_TO)) !== 0) {
                $this->registerDirectoryRecursively($fullPath);
            }
        }

        if (($event['mask'] & (IN_DELETE_SELF | IN_MOVE_SELF)) !== 0) {
            $this->removeWatch($watchDescriptor, $basePath);
        }

        if ($this->filter->shouldIgnorePath($fullPath)) {
            return;
        }

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

        if ('' !== $basename && $this->filter->shouldIgnoreDirectory($basename)) {
            return;
        }

        $this->addWatch($path);
        $this->scanChildren($path);
    }

    private function removeWatch(
        int $descriptor,
        string $path,
    ): void {
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

                if ('' !== $basename && $this->filter->shouldIgnoreDirectory($basename)) {
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
