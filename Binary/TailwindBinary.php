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

namespace ValksorDev\Build\Binary;

use function sprintf;

/**
 * Provider for Tailwind CSS binary.
 */
final class TailwindBinary implements BinaryInterface
{
    public function createManager(
        string $varDir,
        ?string $requestedName = null,
    ): BinaryAssetManager {
        return self::createForTailwindCss($varDir . '/tailwindcss');
    }

    public function getName(): string
    {
        return 'tailwindcss';
    }

    private static function createForTailwindCss(
        string $targetDir,
    ): BinaryAssetManager {
        $platform ??= BinaryAssetManager::detectPlatform();

        return new BinaryAssetManager([
            'name' => 'Tailwind CSS',
            'source' => 'github',
            'repo' => 'tailwindlabs/tailwindcss',
            'assets' => [
                [
                    'pattern' => sprintf('tailwindcss-%s', $platform),
                    'target' => 'tailwindcss',
                    'executable' => true,
                ],
            ],
            'target_dir' => $targetDir,
        ]);
    }
}
