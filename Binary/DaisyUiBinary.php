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

namespace ValksorDev\Build\Binary;

/**
 * Provider for DaisyUI assets.
 */
final class DaisyUiBinary implements BinaryInterface
{
    public function createManager(
        string $varDir,
    ): BinaryAssetManager {
        return self::createForDaisyUi($varDir . '/daisyui');
    }

    public function getName(): string
    {
        return 'daisyui';
    }

    public static function createForDaisyUi(
        string $targetDir,
    ): BinaryAssetManager {
        return new BinaryAssetManager([
            'name' => 'DaisyUI',
            'source' => 'github',
            'repo' => 'saadeghi/daisyui',
            'assets' => [
                [
                    'pattern' => 'daisyui.js',
                    'target' => 'daisyui.js',
                    'executable' => false,
                ],
                [
                    'pattern' => 'daisyui-theme.js',
                    'target' => 'daisyui-theme.js',
                    'executable' => false,
                ],
            ],
            'target_dir' => $targetDir,
        ]);
    }
}
