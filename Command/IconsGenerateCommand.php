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

namespace ValksorDev\Build\Command;

use DOMDocument;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use ValksorDev\Build\Binary\LucideBinary;

use function array_diff;
use function array_intersect;
use function array_key_exists;
use function array_map;
use function array_values;
use function closedir;
use function count;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function json_decode;
use function ltrim;
use function opendir;
use function readdir;
use function rtrim;
use function sprintf;
use function str_ends_with;

use const DIRECTORY_SEPARATOR;
use const GLOB_NOSORT;
use const JSON_THROW_ON_ERROR;

#[AsCommand(name: 'valksor:icons', description: 'Generate Twig SVG icons using Lucide and local overrides.')]
final class IconsGenerateCommand extends AbstractCommand
{
    private string $cacheRoot;
    private SymfonyStyle $io;
    private string $projectRoot;

    public function __construct(
        ParameterBagInterface $bag,
        \ValksorDev\Build\Provider\ProviderRegistry $providerRegistry,
    ) {
        parent::__construct($bag, $providerRegistry);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('target', InputArgument::OPTIONAL, 'Generate icons for a specific app (or "shared"). Default: all', null)
            ->addOption('lucide-version', null, InputOption::VALUE_REQUIRED, 'Explicit Lucide version (e.g. 0.544.0). Defaults to latest release.');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $this->io = $this->createSymfonyStyle($input, $output);
        $this->projectRoot = $this->resolveProjectRoot();
        $this->cacheRoot = $this->projectRoot . '/var/lucide';
        $this->ensureDirectory($this->cacheRoot);

        $target = $input->getArgument('target');
        $requestedVersion = $input->getOption('lucide-version');

        $lucideDir = $this->ensureLucideIcons($requestedVersion);

        if (null === $lucideDir) {
            $this->io->warning('No Lucide icon source could be located. Only local and shared overrides will be used.');
        }

        $sharedIcons = $this->readJsonList($this->getSharedDir() . '/assets/icons.json');
        $appIcons = $this->collectAppIcons($sharedIcons);

        $targets = $this->determineTargets($target, $sharedIcons, $appIcons);

        if ([] === $targets) {
            $this->io->warning('No icon targets found.');

            return $this->handleCommandSuccess();
        }

        $localIconsDir = $this->projectRoot . '/project/js/icons';
        $sharedIconsDir = $this->getSharedDir() . '/assets/icons';

        $generated = 0;

        foreach ($targets as $targetId => $iconNames) {
            $generated += $this->generateForTarget(
                $targetId,
                $iconNames,
                $sharedIcons,
                $localIconsDir,
                $sharedIconsDir,
                $lucideDir,
            );
        }

        if (0 === $generated) {
            $this->io->warning('No icons generated.');

            return $this->handleCommandSuccess();
        }

        return $this->handleCommandSuccess(sprintf('Generated %d icon file%s.', $generated, 1 === $generated ? '' : 's'), $this->io);
    }

    private function cleanExistingTwigIcons(
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

                if (!str_ends_with($entry, '.svg.twig')) {
                    continue;
                }

                @unlink($directory . DIRECTORY_SEPARATOR . $entry);
            }
        } finally {
            closedir($handle);
        }
    }

    /**
     * @return array<string,array<int,string>>
     */
    private function collectAppIcons(
        array $sharedIcons,
    ): array {
        $appsDir = $this->getAppsDir();
        $result = [];

        if (!is_dir($appsDir)) {
            return $result;
        }

        $handle = opendir($appsDir);

        if (false === $handle) {
            return $result;
        }

        try {
            while (($entry = readdir($handle)) !== false) {
                if ('.' === $entry || '..' === $entry) {
                    continue;
                }

                $iconsPath = $appsDir . '/' . $entry . '/assets/icons.json';

                if (!is_file($iconsPath)) {
                    continue;
                }

                $icons = $this->readJsonList($iconsPath);

                if ([] === $icons) {
                    continue;
                }

                $duplicates = array_values(array_intersect($icons, $sharedIcons));

                if ([] !== $duplicates) {
                    $this->io->note(sprintf(
                        'App "%s" defines icons already provided by shared: %s. Shared icons will be used.',
                        $entry,
                        implode(', ', $duplicates),
                    ));
                }

                $unique = array_values(array_diff($icons, $sharedIcons));

                if ([] === $unique) {
                    continue;
                }

                $result[$entry] = $unique;
            }
        } finally {
            closedir($handle);
        }

        return $result;
    }

    /**
     * @param array<string,array<int,string>> $appIcons
     *
     * @return array<string,array<int,string>>
     */
    private function determineTargets(
        $targetArgument,
        array $sharedIcons,
        array $appIcons,
    ): array {
        $targets = [];

        if (null === $targetArgument) {
            $targets['shared'] = $sharedIcons;

            foreach ($appIcons as $app => $icons) {
                $targets[$app] = $icons;
            }

            return $targets;
        }

        $target = (string) $targetArgument;

        $sharedIdentifier = $this->projectStructure->sharedIdentifier;

        if ($target === $sharedIdentifier) {
            $targets['shared'] = $sharedIcons;

            return $targets;
        }

        if (is_array($appIcons) ? array_key_exists($target, $appIcons) : isset($appIcons[$target]) || [] === $appIcons[$target]) {
            $this->io->warning(sprintf('No icons.json found for app "%s" or no icons defined.', $target));

            return [];
        }

        $targets['shared'] = $sharedIcons;
        $targets[$target] = $appIcons[$target];

        return $targets;
    }

    private function ensureLucideIcons(
        mixed $requestedVersion,
    ): ?string {
        // First check if Lucide icons already exist locally
        $existingIconsDir = $this->findExistingLucideIcons();

        if (null !== $existingIconsDir) {
            $this->io->text(sprintf('Using existing Lucide icons from: %s', $existingIconsDir));

            return $existingIconsDir;
        }

        // If no existing icons found, download them using BinaryAssetManager
        try {
            $manager = LucideBinary::createForLucide($this->cacheRoot);
            $tag = $manager->ensureLatest([$this->io, 'text']);
            $version = ltrim($tag, 'v');

            // Look for the icons directory
            $iconsDir = $this->locateIconsDirectory($this->cacheRoot, $version);

            if (null === $iconsDir) {
                throw new RuntimeException('Lucide icons directory could not be located after download.');
            }

            return $iconsDir;
        } catch (RuntimeException $exception) {
            $this->io->error(sprintf('Failed to ensure Lucide icons: %s', $exception->getMessage()));

            return null;
        }
    }

    private function findExistingLucideIcons(): ?string
    {
        // Check if Lucide icons already exist in the standard cache directory
        if (!is_dir($this->cacheRoot)) {
            return null;
        }

        // Look for icons directory in the standard location where BinaryAssetManager downloads
        $iconsDir = $this->cacheRoot . '/icons';

        if ($this->iconDirectoryLooksValid($iconsDir)) {
            return $iconsDir;
        }

        return null;
    }

    /**
     * @param array<int,string> $icons
     */
    private function generateForTarget(
        string $target,
        array $icons,
        array $sharedIcons,
        string $localIconsDir,
        string $sharedIconsDir,
        ?string $lucideIconDir,
    ): int {
        $icons = array_map(static fn ($icon) => (string) $icon, $icons);

        $sharedIdentifier = $this->projectStructure->sharedIdentifier;

        if ($target === $sharedIdentifier) {
            $icons = array_map(static fn ($icon) => (string) $icon, array_diff($icons, $sharedIcons));
        }

        $icons = array_values($icons);
        $count = count($icons);

        if (0 === $count) {
            $this->io->text(sprintf('[%s] No icons to generate.', $target));

            return 0;
        }

        $sharedIdentifier = $this->projectStructure->sharedIdentifier;
        $destination = ($target === $sharedIdentifier)
            ? $this->getSharedDir() . '/templates/icons'
            : $this->getAppsDir() . '/' . $target . '/templates/icons';

        $this->ensureDirectory($destination);
        $this->cleanExistingTwigIcons($destination);

        $generated = 0;

        foreach ($icons as $icon) {
            $source = $this->locateIconSource($icon, $localIconsDir, $sharedIconsDir, $lucideIconDir);

            if (null === $source) {
                $this->io->warning(sprintf('[%s] Icon "%s" not found in local, shared, or lucide sources.', $target, $icon));

                continue;
            }

            if (true === $this->writeTwigIcon($icon, $source, $destination)) {
                $generated++;
            }
        }

        $this->io->success(sprintf('[%s] Generated %d icon%s.', $target, $generated, 1 === $generated ? '' : 's'));

        return $generated;
    }

    private function iconDirectoryLooksValid(
        string $path,
    ): bool {
        if (!is_dir($path)) {
            return false;
        }

        $files = glob($path . '/*.svg', GLOB_NOSORT);

        return false !== $files && [] !== $files;
    }

    private function locateIconSource(
        string $icon,
        string $localDir,
        string $sharedDir,
        ?string $lucideDir,
    ): ?string {
        $candidates = [
            $localDir . '/' . $icon . '.svg',
            $sharedDir . '/' . $icon . '.svg',
        ];

        if (null !== $lucideDir && is_dir($lucideDir)) {
            $candidates[] = rtrim($lucideDir, '/') . '/' . $icon . '.svg';
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function locateIconsDirectory(
        string $baseDir,
        string $version,
    ): ?string {
        // Since BinaryAssetManager downloads to var/lucide, just look for icons subdirectory
        $iconsDir = $baseDir . '/icons';

        if ($this->iconDirectoryLooksValid($iconsDir)) {
            return $iconsDir;
        }

        return null;
    }

    private function readJsonList(
        string $path,
    ): array {
        if (!is_file($path)) {
            $this->io->warning(sprintf('Icons manifest missing at %s', $path));

            return [];
        }

        $raw = file_get_contents($path);

        if (false === $raw || '' === $raw) {
            return [];
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->io->warning(sprintf('Invalid JSON in %s: %s', $path, $exception->getMessage()));

            return [];
        }

        return array_map(static fn ($item) => (string) $item, $data);
    }

    private function writeTwigIcon(
        string $icon,
        string $sourcePath,
        string $destinationDir,
    ): bool {
        $svg = file_get_contents($sourcePath);

        if (false === $svg) {
            $this->io->warning('Unable to read icon source ' . $sourcePath);

            return false;
        }

        $document = new DOMDocument();
        $document->preserveWhiteSpace = false;
        $document->formatOutput = false;

        if (!@$document->loadXML($svg)) {
            $this->io->warning(sprintf('Invalid SVG for icon %s (%s)', $icon, $sourcePath));

            return false;
        }

        $svgElement = $document->getElementsByTagName('svg')->item(0);

        if (null === $svgElement) {
            $this->io->warning(sprintf('SVG element missing for icon %s (%s)', $icon, $sourcePath));

            return false;
        }

        $viewBox = $svgElement->getAttribute('viewBox') ?: '0 0 24 24';

        $inner = '';

        foreach ($svgElement->childNodes as $child) {
            $inner .= $document->saveXML($child);
        }

        if ('logo' === $icon) {
            $wrapped = sprintf(
                '{# twig-cs-fixer-disable #}<svg xmlns="http://www.w3.org/2000/svg" viewBox="%s" fill="currentColor">%s</svg>',
                $viewBox,
                $inner,
            );
        } else {
            $wrapped = sprintf(
                '{# twig-cs-fixer-disable #}<svg xmlns="http://www.w3.org/2000/svg" viewBox="%s" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">%s</svg>',
                $viewBox,
                $inner,
            );
        }

        $outputPath = $destinationDir . '/' . $icon . '.svg.twig';
        file_put_contents($outputPath, $wrapped);

        return true;
    }
}
