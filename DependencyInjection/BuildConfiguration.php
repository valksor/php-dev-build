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
                    ->arrayNode('hot_reload')
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
                    ->end()
                ->end()
            ->end();
    }
}
