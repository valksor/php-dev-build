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

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Interface for binary/asset providers.
 *
 * Each provider represents a single tool (tailwindcss, esbuild, etc.)
 * and knows how to create a configured BinaryAssetManager for that tool.
 */
#[AutoconfigureTag('valksor.binary_provider')]
interface BinaryInterface
{
    /**
     * Create a configured BinaryAssetManager for this tool.
     *
     * @param string $varDir        The base var directory (e.g., /path/to/project/var)
     * @param string $requestedName The actual binary name requested (e.g., '@valksor/valksor@next')
     *
     * @return BinaryAssetManager Configured manager ready to download/ensure the binary
     */
    public function createManager(
        string $varDir,
        ?string $requestedName = null,
    ): BinaryAssetManager;

    /**
     * Get the unique name/identifier for this binary tool.
     *
     * @return string The tool name (e.g., 'tailwindcss', 'esbuild', 'daisyui')
     */
    public function getName(): string;
}
