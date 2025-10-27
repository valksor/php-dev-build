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
                        ->booleanNode('minify')
                            ->info('Should minify files')
                            ->defaultFalse()
                        ->end()
                        ->scalarNode('env')
                            ->info('Build env')
                            ->defaultValue('dev')
                        ->end()
                        ->arrayNode('binaries')
                            ->scalarPrototype()->end()
                            ->defaultValue(['tailwindcss', 'esbuild', 'daisyui'])
                        ->end()
                        ->arrayNode('services')
                            ->info('Services to run in valksor:watch. Supports simple list or detailed configuration.')
                            ->example(['tailwind', 'importmap', ['hot-reload' => ['enabled' => true, 'watch_dirs' => ['/apps']]]])
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
                                            $normalized[$value] = ['enabled' => true];
                                        } elseif (is_string($key) && is_array($value)) {
                                            // Detailed format: ['tailwind' => ['enabled' => true, 'watch_dirs' => [...]]]
                                            $normalized[$key] = $value + ['enabled' => true];
                                        } elseif (is_string($key) && is_bool($value)) {
                                            // Boolean format: ['tailwind' => true]
                                            $normalized[$key] = ['enabled' => $value];
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
                                    ->end()
                                    ->arrayNode('watch_dirs')
                                        ->info('Override global watch_dirs for this specific service')
                                        ->scalarPrototype()->end()
                                    ->end()
                                    ->variableNode('options')
                                        ->info('Service-specific options')
                                        ->defaultValue([])
                                    ->end()
                                ->end()
                            ->end()
                            ->defaultValue([
                                'tailwind' => ['enabled' => true],
                                'importmap' => ['enabled' => true],
                                'sse' => ['enabled' => true],
                                'hot-reload' => ['enabled' => true],
                            ])
                        ->end()
                        ->append($this->addHotReloadConfiguration())
                        ->append($this->addInitilaizationConfiguration())
                        ->append($this->addProdBuildConfiguration())
                        ->append($this->addDevCommandConfiguration())
                    ->end()
                ->end()
            ->end();
    }

    private function addDevCommandConfiguration(): ArrayNodeDefinition
    {
        return new ArrayNodeDefinition('dev_command')
            ->info('One-time initialization tasks that run once at startup')
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('services')
                    ->info('Services to run in dev command')
                    ->example(['sse' => ['enabled' => true], 'hot-reload' => ['enabled' => true]])
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->booleanNode('enabled')
                                ->defaultTrue()
                            ->end()
                        ->end()
                    ->end()
                    ->defaultValue([
                        'sse' => ['enabled' => true],
                        'hot-reload' => ['enabled' => true],
                    ])
                ->end()
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

    private function addInitilaizationConfiguration(): ArrayNodeDefinition
    {
        return new ArrayNodeDefinition('initialization')
            ->info('One-time initialization tasks that run once at startup')
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('icons')
                    ->info('Icon template generation')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Whether to generate icon templates during watch startup')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('target')
                            ->info('Specific icon target to generate (null = all targets)')
                            ->defaultNull()
                            ->example('shared')
                        ->end()
                        ->booleanNode('blocking')
                            ->info('Whether icon generation blocks watch startup')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    private function addProdBuildConfiguration(): ArrayNodeDefinition
    {
        return new ArrayNodeDefinition('prod')
            ->info('One-time initialization tasks that run once at startup')
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('steps')
                    ->info('Build steps configuration with order and options')
                    ->example([
                        'binaries' => ['enabled' => true, 'options' => []],
                        'tailwind' => ['enabled' => true, 'options' => ['minify' => true]],
                        'importmap' => ['enabled' => true, 'options' => ['minify' => true]],
                        'icons' => ['enabled' => true, 'options' => []],
                        'symfony_assets' => ['enabled' => true, 'options' => []],
                    ])
                    ->beforeNormalization()
                        ->always(function ($v) {
                            if (!is_array($v)) {
                                return [];
                            }

                            $normalized = [];

                            foreach ($v as $key => $value) {
                                if (is_int($key) && is_string($value)) {
                                    // Simple format: ['tailwind', 'importmap']
                                    $normalized[$value] = ['enabled' => true];
                                } elseif (is_string($key) && is_array($value)) {
                                    // Detailed format: ['tailwind' => ['enabled' => true, 'options' => [...]]]
                                    $normalized[$key] = $value + ['enabled' => true, 'options' => []];
                                } elseif (is_string($key) && is_bool($value)) {
                                    // Boolean format: ['tailwind' => true]
                                    $normalized[$key] = ['enabled' => $value, 'options' => []];
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
                                ->info('Whether this build step is enabled')
                            ->end()
                            ->arrayNode('options')
                                ->info('Step-specific options')
                                ->defaultValue([])
                                ->normalizeKeys(false)
                                ->useAttributeAsKey('name')
                                ->variablePrototype()->end()
                            ->end()
                        ->end()
                    ->end()
                    ->defaultValue([
                        'binaries' => ['enabled' => true, 'options' => []],
                        'tailwind' => ['enabled' => true, 'options' => ['minify' => true]],
                        'importmap' => ['enabled' => true, 'options' => ['minify' => true]],
                        'icons' => ['enabled' => true, 'options' => []],
                        'symfony_assets' => ['enabled' => true, 'options' => []],
                    ])
                ->end()
            ->end();
    }
}
