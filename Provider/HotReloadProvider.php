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

final class HotReloadProvider extends AbstractProvider
{
    public function dev(): array
    {
        $process = $this->createConsoleProcess(['valksor:hot']);

        return [
            'hot' => [
                'process' => $process,
            ],
        ];
    }

    public function devCommand(): array
    {
        return $this->dev();
    }
}
