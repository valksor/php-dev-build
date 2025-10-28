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

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

use function array_key_exists;
use function sprintf;

class ProviderRegistry
{
    public const string DEV = 'dev';
    public const string DEV_COMMAND = 'dev_command';
    public const string INIT = 'init';
    public const string PROD = 'prod';

    private const array TYPES = [
        self::DEV,
        self::DEV_COMMAND,
        self::PROD,
        self::INIT,
    ];
    private array $providers = [];

    public function __construct(
        #[AutowireIterator(
            'valksor.build.provider',
        )]
        iterable $providers,
        #[Autowire(
            param: 'valksor.build.services',
        )]
        array $services,
    ) {
        foreach ($providers as $provider) {
            if (!array_key_exists($provider::class, $services) || false === ($services[$provider::class]['enabled'] ?? false)) {
                continue;
            }

            foreach (self::TYPES as $type) {
                if (true === ($services[$provider::class][$type] ?? false)) {
                    $this->register($provider, $type);
                }
            }
        }
    }

    public function get(
        string $name,
        string $type,
    ): ProviderInterface {
        if (!$this->has($name, $type)) {
            throw new RuntimeException(sprintf('No service provider registered for: %s', $name));
        }

        return $this->providers[$name];
    }

    public function has(
        string $name,
        string $type,
    ): bool {
        return isset($this->providers[$type][$name]);
    }

    public function register(
        ProviderInterface $provider,
        string $type,
    ): void {
        $this->providers[$type][$provider::class] = $provider;
    }
}
