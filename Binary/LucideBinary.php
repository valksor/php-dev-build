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

/**
 * Provider for Lucide icons.
 */
final class LucideBinary implements BinaryInterface
{
    public function createManager(
        string $varDir,
        ?string $requestedName = null,
    ): BinaryAssetManager {
        return self::createForLucide($varDir . '/lucide');
    }

    public function getName(): string
    {
        return 'lucide';
    }

    public static function createForLucide(
        string $targetDir,
    ): BinaryAssetManager {
        return new BinaryAssetManager([
            'name' => 'Lucide Icons',
            'source' => 'github-zip',
            'repo' => 'lucide-icons/lucide',
            'assets' => [
                [
                    'pattern' => 'lucide-icons-%s.zip',
                    'target' => 'lucide-icons.zip',
                    'executable' => false,
                ],
            ],
            'target_dir' => $targetDir,
            'version_in_path' => true,
        ]);
    }
}
