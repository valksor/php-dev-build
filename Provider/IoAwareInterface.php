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

namespace ValksorDev\Build\Provider;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Interface for providers that need IO access for user feedback.
 */
interface IoAwareInterface
{
    /**
     * Set the IO style for user feedback.
     */
    public function setIo(
        SymfonyStyle $io,
    ): void;
}
