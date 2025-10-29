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
                            ->info('Valksor Build System Services Configuration')
                            ->example([
                                'binaries' => [
                                    'enabled' => true,
                                    'flags' => ['init' => true, 'dev' => true, 'prod' => true],
                                    'provider' => 'binaries',
                                ],
                                'tailwind' => [
                                    'enabled' => true,
                                    'flags' => ['dev' => true, 'prod' => true],
                                    'provider' => 'tailwind',
                                    'options' => [
                                        'minify' => true,
                                        'watch' => false,
                                    ],
                                ],
                                'importmap' => [
                                    'enabled' => true,
                                    'flags' => ['dev' => true, 'prod' => true],
                                    'provider' => 'importmap',
                                    'options' => [
                                        'minify' => false,
                                        'esbuild' => true,
                                    ],
                                ],
                                'hot_reload' => [
                                    'enabled' => true,
                                    'flags' => ['dev' => true],
                                    'provider' => 'hot_reload',
                                    'options' => [
                                        'watch_dirs' => ['src/apps', 'infrastructure'],
                                        'debounce_delay' => 0.3,
                                        'extended_extensions' => [
                                            'php' => 0.1,
                                            'js' => 0.1,
                                            'css' => 0.2,
                                        ],
                                        'extended_suffixes' => [
                                            '.tailwind.css' => 0.5,
                                        ],
                                        'file_transformations' => [
                                            '*.tailwind.css' => [
                                                'output_pattern' => '{path}/{name}.css',
                                                'debounce_delay' => 0.5,
                                                'track_output' => true,
                                            ],
                                        ],
                                    ],
                                ],
                            ])
                            ->useAttributeAsKey('name')
                            ->arrayPrototype()
                                ->children()
                                    ->booleanNode('enabled')
                                        ->info('Enable or disable this service globally')
                                        ->example('true')
                                        ->defaultTrue()
                                    ->end()
                                    ->scalarNode('provider')
                                        ->info('Service provider class name (maps to implementation)')
                                        ->example('tailwind')
                                        ->isRequired()
                                    ->end()
                                    ->variableNode('flags')
                                        ->info('Service execution flags that determine when services run')
                                        ->example([
                                            'init' => true,
                                            'dev' => true,
                                            'prod' => true,
                                            'watch' => false,
                                            'build' => false,
                                        ])
                                        ->defaultValue([])
                                    ->end()
                                    ->variableNode('options')
                                        ->info('Service-specific configuration options')
                                        ->example([
                                            // Tailwind CSS service options
                                            'minify' => true,
                                            'watch' => false,

                                            // Importmap service options
                                            'esbuild' => true,
                                            'minify' => false,

                                            // Hot reload service options
                                            'watch_dirs' => ['src/apps', 'infrastructure'],
                                            'debounce_delay' => 0.3,
                                            'extended_extensions' => [
                                                'php' => 0.1,    // PHP files get 100ms debounce
                                                'js' => 0.1,     // JS files get 100ms debounce
                                                'css' => 0.2,     // CSS files get 200ms debounce
                                                'twig' => 0.3,   // Twig files get 300ms debounce
                                            ],
                                            'extended_suffixes' => [
                                                '.tailwind.css' => 0.5,  // Compiled CSS gets longer debounce
                                                '.min.css' => 0.3,
                                            ],
                                            'file_transformations' => [
                                                '*.tailwind.css' => [
                                                    'output_pattern' => '{path}/{name}.css',
                                                    'debounce_delay' => 0.5,
                                                    'track_output' => true,
                                                ],
                                            ],
                                        ])
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
