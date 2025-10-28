<?php declare(strict_types = 1);

/*
 * This file is part of the Valksor package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <packages@valksor.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ValksorDev\Build\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Valksor\Bundle\DependencyInjection\AbstractDependencyConfiguration;

use function is_array;
use function is_bool;
use function is_int;
use function is_string;

class DevConfiguration //extends AbstractDependencyConfiguration
{
    public function addSection(
        ArrayNodeDefinition $rootNode,
        callable $enableIfStandalone,
        string $component,
    ): void {
        $rootNode
            ->children()
                ->arrayNode($component)
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('watch_dirs')
                            ->info('Global watch directories for all services (can be overridden per-service)')
                            ->scalarPrototype()->end()
                            ->defaultValue(['/apps', '/shared', '/src'])
                            ->example(['%project_structure.apps_dir%', '%project_structure.shared_dir%', '/src'])
                        ->end()
                        ->arrayNode('binaries')
                            ->scalarPrototype()->end()
                            ->defaultValue(['tailwindcss', 'esbuild', 'daisyui', 'lucide'])
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
                                'tailwind' => ['enabled' => true, 'options' => ['minify' => false]],
                                'importmap' => ['enabled' => true, 'options' => ['minify' => false]],
                                'sse' => ['enabled' => true],
                            ])
                        ->end()
                        ->arrayNode('initialization')
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
                            ->end()
                        ->end()
                        ->arrayNode('project_structure')
                            ->info('Project directory structure configuration')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('apps_dir')
                                    ->info('Directory containing applications (relative to project root)')
                                    ->example('/apps')
                                    ->isRequired()
                                    ->example('/apps')
                                ->end()
                                ->scalarNode('shared_dir')
                                    ->info('Directory containing shared resources (relative to project root)')
                                    ->example('/shared')
                                    ->isRequired()
                                    ->example('/shared')
                                ->end()
                                ->scalarNode('templates_dir')
                                    ->info('Directory containing shared templates (relative to project root)')
                                    ->defaultValue('/templates')
                                    ->example('/templates')
                                ->end()
                                ->scalarNode('public_dir')
                                    ->info('Public web directory (relative to project root)')
                                    ->defaultValue('/public')
                                    ->example('/public')
                                ->end()
                                ->scalarNode('config_dir')
                                    ->info('Configuration directory (relative to project root)')
                                    ->defaultValue('/config')
                                    ->example('/config')
                                ->end()
                                ->scalarNode('shared_identifier')
                                    ->info('Identifier for shared resources (replaces hardcoded strings)')
                                    ->example('shared')
                                    ->isRequired()
                                ->end()
                                ->arrayNode('exclude_patterns')
                                    ->info('Directory patterns to exclude from file watching')
                                    ->scalarPrototype()->end()
                                    ->isRequired()
                                    ->example(['node_modules', 'vendor', 'build'])
                                ->end()
                                ->arrayNode('exclude_files')
                                    ->info('File patterns to exclude from file watching')
                                    ->scalarPrototype()->end()
                                    ->isRequired()
                                    ->example(['.gitignore', '.gitkeep'])
                                ->end()
                            ->end()
                        ->end()

                            ->arrayNode('asset_roots')
                            ->scalarPrototype()->end()
                            ->defaultValue(['/apps'])
                        ->end()
                        ->arrayNode('dev_command')
                            ->info('Configuration for valksor:dev command (lightweight development mode)')
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
                            ->end()
                        ->end()
                        ->arrayNode('sse')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('bind')
                                    ->info('Bind address for SSE server')
                                    ->defaultValue('0.0.0.0')
                                ->end()
                                ->integerNode('port')
                                    ->info('Port for SSE server')
                                    ->defaultValue(3000)
                                ->end()
                                ->scalarNode('path')
                                    ->info('Base path for SSE endpoint')
                                    ->defaultValue('/sse')
                                ->end()
                                ->scalarNode('domain')
                                    ->info('Domain for TLS certificate lookup')
                                    ->defaultValue('localhost')
                                ->end()
                                ->booleanNode('enable_file_watching')
                                    ->info('Enable file watching by default (can be overridden by command flags)')
                                    ->defaultValue(false)
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('hot_reload')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enable_file_watching')
                                    ->info('Enable file watching for hot reload')
                                    ->defaultValue(true)
                                ->end()
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
                                                ->example(['%project_structure.apps_dir%', '%project_structure.shared_dir%'])
                                            ->end()
                                        ->end()
                                    ->end()
                                    ->defaultValue([
                                        '*.tailwind.css' => [
                                            'output_pattern' => '{path}/{name}.css',
                                            'debounce_delay' => 0.5,
                                            'track_output' => true,
                                        ],
                                    ])
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
                                    ->defaultValue(['/apps', '/shared', '/src'])
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
                            ->end()
                        ->end()
                        ->arrayNode('prod_build')
                            ->info('Production build configuration')
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
                            ->end()
                        ->end()
                ->end()
            ->end();
    }
}
