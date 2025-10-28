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
use ValksorDev\Build\Provider\HotReloadProvider;
use ValksorDev\Build\Provider\ProviderRegistry;

use function is_array;
use function is_bool;
use function is_int;
use function is_string;
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
                        ->scalarNode('env')
                            ->info('Build env')
                            ->defaultValue('dev')
                        ->end()
                        ->arrayNode('performance')
                            ->info('Performance optimization settings for development workflows')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('skip_binaries')
                                    ->info('Skip binary checks/downloads for faster startup')
                                    ->defaultTrue()
                                ->end()
                                ->booleanNode('skip_initialization')
                                    ->info('Skip initialization tasks like icon generation')
                                    ->defaultTrue()
                                ->end()
                                ->booleanNode('skip_asset_cleanup')
                                    ->info('Skip public asset cleanup')
                                    ->defaultTrue()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('services')
                            ->info('Unified services configuration for dev_command, dev (watch), and prod modes')
                            ->example([
                                'tailwind' => [
                                    'enabled' => true,
                                    'dev_command' => true,
                                    'dev' => true,
                                    'prod' => true,
                                    'options' => ['minify' => false],
                                ],
                                'hot-reload' => [
                                    'enabled' => true,
                                    'dev_command' => false,
                                    'dev' => true,
                                    'prod' => false,
                                ],
                                'binaries' => [
                                    'enabled' => true,
                                    'dev_command' => false,
                                    'dev' => false,
                                    'prod' => true,
                                ],
                            ])
                            ->beforeNormalization()
                                ->always(function ($v) {
                                    // Normalize to associative array format
                                    if (!is_array($v)) {
                                        return [];
                                    }

                                    $normalized = [];

                                    foreach ($v as $key => $value) {
                                        if (is_int($key) && is_string($value)) {
                                            // Simple format: ['tailwind', 'importmap']
                                            $normalized[$value] = [
                                                'enabled' => true,
                                                ProviderRegistry::DEV_COMMAND => true,
                                                ProviderRegistry::DEV => true,
                                                ProviderRegistry::PROD => true,
                                                ProviderRegistry::INIT => true,
                                            ];
                                        } elseif (is_string($key) && is_array($value)) {
                                            // Detailed format: ['tailwind' => ['enabled' => true, 'dev' => true, ...]]
                                            $normalized[$key] = $value + [
                                                ProviderRegistry::DEV_COMMAND => true,
                                                ProviderRegistry::DEV => true,
                                                ProviderRegistry::PROD => true,
                                                ProviderRegistry::INIT => true,
                                            ];
                                        } elseif (is_string($key) && is_bool($value)) {
                                            // Boolean format: ['tailwind' => true]
                                            $normalized[$key] = [
                                                'enabled' => $value,
                                                ProviderRegistry::DEV_COMMAND => true,
                                                ProviderRegistry::DEV => true,
                                                ProviderRegistry::PROD => true,
                                                ProviderRegistry::INIT => true,
                                            ];
                                        } else {
                                            // Keep as-is for validation errors
                                            $normalized[$key] = $value;
                                        }
                                    }

                                    return $normalized;
                                })
                            ->end()
                            ->useAttributeAsKey('name')
                            ->arrayPrototype()
                                ->children()
                                    ->booleanNode('enabled')
                                        ->defaultTrue()
                                        ->info('Whether this service is available')
                                    ->end()
                                    ->booleanNode(ProviderRegistry::DEV_COMMAND)
                                        ->defaultTrue()
                                        ->info('Whether this service runs in dev_command mode')
                                    ->end()
                                    ->booleanNode(ProviderRegistry::DEV)
                                        ->defaultTrue()
                                        ->info('Whether this service runs in dev (watch) mode')
                                    ->end()
                                    ->booleanNode(ProviderRegistry::PROD)
                                        ->defaultTrue()
                                        ->info('Whether this service runs in prod build mode')
                                    ->end()
                                    ->booleanNode(ProviderRegistry::INIT)
                                        ->defaultFalse()
                                        ->info('Whether this service runs in prod build mode')
                                    ->end()
                                ->end()
                            ->end()
                            ->defaultValue([
                                HotReloadProvider::class => [
                                    'enabled' => true,
                                    'dev_command' => true,
                                    'dev' => true,
                                    'prod' => false,
                                ],
                                //                                'tailwind' => [
                                //                                    'enabled' => true,
                                //                                    'dev_command' => true,
                                //                                    'dev' => true,
                                //                                    'prod' => true,
                                //                                    'options' => ['minify' => false],
                                //                                ],
                                //                                'importmap' => [
                                //                                    'enabled' => true,
                                //                                    'dev_command' => true,
                                //                                    'dev' => true,
                                //                                    'prod' => true,
                                //                                ],
                                //                                'sse' => [
                                //                                    'enabled' => true,
                                //                                    'dev_command' => true,
                                //                                    'dev' => true,
                                //                                    'prod' => false,
                                //                                ],
                                //                                'hot-reload' => [
                                //                                    'enabled' => true,
                                //                                    'dev_command' => false,
                                //                                    'dev' => true,
                                //                                    'prod' => false,
                                //                                ],
                                //                                'binaries' => [
                                //                                    'enabled' => true,
                                //                                    'dev_command' => false,
                                //                                    'dev' => false,
                                //                                    'prod' => true,
                                //                                    'options' => ['tools' => ['tailwindcss', 'esbuild', 'daisyui']],
                                //                                ],
                                //                                'icons' => [
                                //                                    'enabled' => true,
                                //                                    'init' => true,
                                //                                    'dev' => false,
                                //                                    'prod' => false,
                                //                                ],
                            ])
                        ->end()
                        ->append($this->addHotReloadConfiguration())
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Configure hot reload section with file watching and transformation rules.
     */
    private function addHotReloadConfiguration(): ArrayNodeDefinition
    {
        return new ArrayNodeDefinition('hot_reload')
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('file_transformations')
                    ->info('File transformation rules for build tools and processors')
                    ->normalizeKeys(false)
                    ->useAttributeAsKey('pattern')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('output_pattern')
                                ->info('Output file pattern (supports {path} and {name} variables)')
                                ->isRequired()
                                ->example('{path}/{name}.css')
                            ->end()
                            ->floatNode('debounce_delay')
                                ->info('Custom debounce delay for this transformation')
                                ->defaultValue(0.5)
                                ->example('0.8')
                            ->end()
                            ->booleanNode('track_output')
                                ->info('Whether to track output file changes separately')
                                ->defaultValue(true)
                            ->end()
                            ->arrayNode('watch_dirs')
                                ->info('Specific directories to watch for this transformation')
                                ->scalarPrototype()->end()
                                ->defaultValue([])
                            ->end()
                        ->end()
                    ->end()
                    ->defaultValue([])
                    ->example([
                        '*.tailwind.css' => [
                            'output_pattern' => '{path}/{name}.css',
                            'debounce_delay' => 0.5,
                        ],
                        '*.scss' => [
                            'output_pattern' => '{path}/{name}.css',
                            'debounce_delay' => 0.3,
                        ],
                        'src/**/*.ts' => [
                            'output_pattern' => 'dist/{path}/{name}.js',
                            'debounce_delay' => 1.0,
                        ],
                    ])
                ->end()
                ->arrayNode('watch_dirs')
                    ->scalarPrototype()->end()
                    ->defaultValue(['/src'])
                    ->example(['%project_structure.apps_dir%', '%project_structure.shared_dir%', '/src'])
                ->end()
                ->floatNode('debounce_delay')
                    ->info('Debounce delay in seconds before triggering reload')
                    ->defaultValue(0.3)
                ->end()
                ->arrayNode('extended_extensions')
                    ->info('File extensions that require longer reload delays')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('extended_suffixes')
                    ->info('File suffixes that require longer reload delays')
                    ->scalarPrototype()->end()
                    ->defaultValue(['.tailwind.css' => 0.5])
                ->end()
                ->arrayNode('exclude_patterns')
                    ->info('Directory patterns to exclude from file watching')
                    ->scalarPrototype()->end()
                    ->defaultValue(['node_modules', 'vendor', 'build', 'dist'])
                    ->example(['node_modules', 'vendor', 'build'])
                ->end()
                ->arrayNode('exclude_files')
                    ->info('File patterns to exclude from file watching')
                    ->scalarPrototype()->end()
                    ->defaultValue(['.gitignore', '.gitkeep'])
                    ->example(['.gitignore', '.gitkeep'])
                ->end()
            ->end();
    }
}
