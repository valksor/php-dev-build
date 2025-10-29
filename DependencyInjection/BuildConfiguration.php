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

namespace ValksorDev\Build\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Valksor\Bundle\DependencyInjection\AbstractDependencyConfiguration;
use Valksor\Bundle\ValksorBundle;

use function sprintf;

class BuildConfiguration extends AbstractDependencyConfiguration
{
    public function addSection(
        ArrayNodeDefinition $rootNode,
        callable $enableIfStandalone,
        string $component,
    ): void {
        $rootNode
            ->children()
                ->arrayNode($component)
                    ->{$enableIfStandalone(sprintf('%s/%s', ValksorBundle::VALKSOR, $component), self::class)}()
                    ->children()
                        // Service registry with extensible flag system
                        ->arrayNode('services')
                            ->info('Service registry with extensible flag system')
                            ->useAttributeAsKey('name')
                            ->arrayPrototype()
                                ->children()
                                    ->booleanNode('enabled')
                                        ->info('Is service enabled?')
                                        ->defaultTrue()
                                    ->end()
                                    ->scalarNode('provider')
                                        ->info('Provider class name (required for extensibility)')
                                        ->isRequired()
                                    ->end()
                                    // Prototype pattern - users can add any boolean flags
                                    ->variableNode('flags')
                                        ->info('Service execution flags (init, dev, prod, custom)')
                                        ->defaultValue([])
                                    ->end()
                                    ->variableNode('options')
                                        ->info('Service-specific options')
                                        ->defaultValue([])
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }
}
