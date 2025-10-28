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
 * Provider for esbuild binary.
 */
final class EsbuildBinary implements BinaryInterface
{
    public function createManager(
        string $varDir,
    ): BinaryAssetManager {
        return self::createForEsbuild($varDir . '/esbuild');
    }

    public function getName(): string
    {
        return 'esbuild';
    }

    public static function createForEsbuild(
        string $targetDir,
    ): BinaryAssetManager {
        return new BinaryAssetManager([
            'name' => 'esbuild',
            'source' => 'npm',
            'npm_package' => 'esbuild',
            'assets' => [
                [
                    'pattern' => 'esbuild',
                    'target' => 'esbuild',
                    'executable' => true,
                    'extract_path' => 'package/bin/esbuild',
                ],
            ],
            'target_dir' => $targetDir,
        ]);
    }
}
