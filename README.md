# ValksorDev Build Tools

[![BSD-3-Clause](https://img.shields.io/badge/BSD--3--Clause-green?style=flat)](https://github.com/valksor/php-dev-build/blob/master/LICENSE)
[![Coverage Status](https://coveralls.io/repos/github/valksor/php-dev-build/badge.svg?branch=master)](https://coveralls.io/github/valksor/php-dev-build?branch=master)

A comprehensive development build tool suite for PHP applications that provides hot reloading, asset compilation, import map management, and development workflow automation. Designed to streamline modern PHP development with integrated tooling for Tailwind CSS, ESBuild, DaisyUI, and other frontend build tools.

## Features

- **Service Registry Architecture**: Clean, DRY system with extensible flag-based service selection
- **Three-Command Development Workflow**:
  - `valksor:dev` - Lightweight development (SSE + hot reload)
  - `valksor:watch` - Full development environment (all services in parallel)
  - `valksor-prod:build` - Production asset building
- **Provider System**: Extensible architecture with service ordering and dependency resolution
- **Hot Reloading**: Automatic browser reload on file changes using inotify
- **Asset Management**: Integrated support for ESBuild, Tailwind CSS, and DaisyUI
- **Import Map Synchronization**: Automatic import map generation and synchronization
- **Binary Management**: Unified binary download and version management for build tools
- **Development Watchers**: Parallel process management for multiple build tools
- **File Watching**: Efficient inotify-based file system monitoring
- **Process Orchestration**: Coordinated execution of multiple development services
- **SSE Integration**: Seamless integration with Server-Sent Events for live updates

## Requirements

- **PHP 8.4 or higher**
- **inotify extension** (for file watching)
- **PCNTL extension** (for process management)
- **POSIX extension**
- **Symfony Framework** (7.2.0 or higher)
- **Valksor Bundle** (valksor/php-bundle)
- **Valksor SSE Component** (valksor/php-sse)

## Installation

Install the package via Composer:

```bash
composer require valksor/php-dev-build
```

Note: This package is also included in the meta-package `valksor/php-dev`.

## Usage

### Basic Setup

1. Register the bundle in your Symfony application:

```php
// config/bundles.php
return [
    // ...
    Valksor\Bundle\ValksorBundle::class => ['all' => true],
    // ...
];
```

1. Enable the build tools component:

```yaml
# config/packages/valksor.yaml
valksor:
    build:
        enabled: true
        hot_reload:
            enabled: true
            watch_paths:
                - 'templates/'
                - 'src/'
                - 'assets/'
        tailwind:
            enabled: true
            input: 'assets/css/app.css'
            output: 'public/build/app.css'
        importmap:
            enabled: true
            importmap_path: 'importmap.json'
        binaries:
            esbuild_version: 'latest'
            tailwind_version: 'latest'
            daisyui_version: 'latest'
```

### Development Commands

#### New Architecture Commands

The build system now uses a clean 3-command architecture with extensible flag-based service selection:

```bash
# Lightweight development (SSE + hot reload only)
php bin/console valksor:dev

# Full development environment (all services in parallel)
php bin/console valksor:watch

# Production asset building
php bin/console valksor-prod:build
```

#### Individual Services

```bash
# Hot reload only
php bin/console valksor:hot-reload

# Build Tailwind CSS
php bin/console valksor:tailwind

# Sync import maps
php bin/console valksor:importmap

# Generate icons
php bin/console valksor:icons

# Ensure all binaries are downloaded
php bin/console valksor:binary

# Install binaries
php bin/console valksor:binaries:install
```

### Service Registry Architecture

The build system uses a clean, DRY service registry architecture with extensible flag-based service selection:

#### Provider System

All build services are implemented as **providers** that can be enabled/disabled and configured via flags:

- **Service Registry**: Single source of truth for all available services
- **Flag-Based Selection**: Services run based on flags (`init`, `dev`, `prod`, `custom`)
- **Dependency Resolution**: Automatic service ordering and dependency handling
- **Extensible**: Easy to add new providers without modifying core code

#### Available Providers

| Provider | Description | Flags | Dependencies |
|----------|-------------|-------|--------------|
| `binaries` | Binary download and management | `init`, `dev`, `prod` | None |
| `hot_reload` | SSE server and browser reload | `dev` | `binaries` |
| `tailwind` | Tailwind CSS compilation | `dev`, `prod` | `binaries` |
| `importmap` | Import map synchronization | `dev`, `prod` | `binaries` |
| `assets` | Asset building and optimization | `prod` | `binaries`, `tailwind` |
| `icons` | Icon generation and optimization | `prod` | `binaries` |

#### Service Configuration

```yaml
# config/packages/valksor.yaml
valksor:
    build:
        services:
            binaries:
                enabled: true
                flags: [init, dev, prod]
                options:
                    download_dir: 'bin/build-tools/'
                    esbuild_version: 'latest'
                    tailwind_version: 'latest'

            hot_reload:
                enabled: true
                flags: [dev]
                options:
                    watch_paths: ['templates/', 'src/', 'assets/']

            tailwind:
                enabled: true
                flags: [dev, prod]
                options:
                    input: 'assets/css/app.css'
                    output: 'public/build/app.css'
                    minify: false  # Will be true in prod
```

#### Command-Provider Mapping

| Command | Flags Used | Services That Run |
|---------|------------|------------------|
| `valksor:dev` | `dev` | `binaries` + `hot_reload` |
| `valksor:watch` | `dev` | All services with `dev` flag |
| `valksor-prod:build` | `prod` | `binaries` + services with `prod` flag |

### Configuration

#### Complete Configuration Example

```yaml
# config/packages/valksor.yaml
valksor:
    build:
        enabled: true

        # Hot Reload Configuration
        hot_reload:
            enabled: true
            watch_paths:
                - 'templates/'
                - 'src/Controller/'
                - 'src/Service/'
                - 'assets/js/'
                - 'assets/css/'
            exclude_patterns:
                - 'vendor/'
                - 'var/'
                - 'node_modules/'
                - '.git/'
            debounce_ms: 100
            sse_port: 8080

        # Tailwind CSS Configuration
        tailwind:
            enabled: true
            input: 'assets/css/app.css'
            output: 'public/build/app.css'
            config: 'tailwind.config.js'
            minify: false
            watch: true

        # Import Map Configuration
        importmap:
            enabled: true
            importmap_path: 'importmap.json'
            vendor_dir: 'assets/vendor/'
            paths:
                - 'assets/js/'

        # Binary Management
        binaries:
            download_dir: 'bin/build-tools/'
            esbuild_version: 'latest'
            tailwind_version: 'latest'
            daisyui_version: 'latest'
            lucide_version: 'latest'

        # File Watching Configuration
        watching:
            max_file_handles: 1000
            event_buffer_size: 16384
            recursive: true
```

### Binary Management

The component automatically manages binary downloads and versions:

#### Supported Binaries

- **ESBuild**: JavaScript bundler and minifier
- **Tailwind CSS**: Utility-first CSS framework
- **DaisyUI**: Component library for Tailwind CSS
- **Lucide**: Icon library

#### Binary Configuration

```yaml
valksor:
    build:
        binaries:
            download_dir: 'bin/build-tools/'  # Where to store binaries
            esbuild_version: '0.19.0'         # Specific version
            tailwind_version: 'latest'          # Latest version
            daisyui_version: 'latest'
            lucide_version: 'latest'

            # Platform-specific binary paths
            platforms:
                linux:
                    esbuild: 'esbuild-linux-64'
                darwin:
                    esbuild: 'esbuild-darwin-arm64'
                win32:
                    esbuild: 'esbuild-windows-64.exe'
```

#### Manual Binary Management

```bash
# Download all required binaries
php bin/console valksor:binary-ensure

# Force re-download of binaries
php bin/console valksor:binary-ensure --force

# Check binary versions
php bin/console valksor:binary-ensure --check-versions
```

### File Watching

The inotify-based file watcher provides efficient real-time file monitoring:

#### Watch Path Configuration

```yaml
valksor:
    build:
        hot_reload:
            watch_paths:
                - 'templates/'           # Twig templates
                - 'src/Controller/'      # PHP controllers
                - 'src/Service/'         # PHP services
                - 'assets/js/'           # JavaScript files
                - 'assets/css/'          # CSS files
                - 'config/'              # Configuration files
            exclude_patterns:
                - 'vendor/'              # Dependencies
                - 'var/'                 # Symfony cache
                - 'node_modules/'        # Node dependencies
                - '.git/'                # Git files
                - '*.log'                # Log files
                - '*.tmp'                # Temporary files
```

#### Advanced Watching Options

```yaml
valksor:
    build:
        watching:
            debounce_ms: 100            # Debounce file change events
            max_file_handles: 1000      # Maximum file descriptors
            event_buffer_size: 16384    # Inotify buffer size
            recursive: true            # Watch directories recursively
            follow_symlinks: false     # Follow symbolic links
```

### Tailwind CSS Integration

#### Basic Tailwind Setup

```yaml
valksor:
    build:
        tailwind:
            enabled: true
            input: 'assets/css/app.css'         # Input file
            output: 'public/build/app.css'      # Output file
            config: 'tailwind.config.js'        # Config file
            minify: false                       # Minify in production
            watch: true                         # Watch for changes
            postcss: true                       # Use PostCSS
```

#### Advanced Tailwind Configuration

```javascript
// tailwind.config.js
module.exports = {
  content: [
    './templates/**/*.html.twig',
    './assets/js/**/*.js',
    './src/**/*.php',
  ],
  theme: {
    extend: {},
  },
  plugins: [
    require('daisyui'),
  ],
  daisyui: {
    themes: ['light', 'dark', 'cupcake'],
  },
}
```

#### DaisyUI Integration

```yaml
valksor:
    build:
        tailwind:
            daisyui:
                enabled: true
                themes:
                    - light
                    - dark
                    - cupcake
                    - bumblebee
                plugins: ['themes']
```

### Import Map Management

#### Import Map Configuration

```yaml
valksor:
    build:
        importmap:
            enabled: true
            importmap_path: 'importmap.json'
            vendor_dir: 'assets/vendor/'
            paths:
                - 'assets/js/'
                - 'assets/css/'
            exclude_patterns:
                - '*.test.js'
                - '*.spec.js'
```

#### Import Map Usage

```javascript
// assets/js/app.js
import { hotwire } from '@hotwired/turbo';
import { stimulus } from '@hotwired/stimulus';

// Import maps automatically resolve these imports
```

```json
{
  "imports": {
    "@hotwired/turbo": "/assets/vendor/turbo.min.js",
    "@hotwired/stimulus": "/assets/vendor/stimulus.min.js",
    "app": "/assets/js/app.js"
  }
}
```

### Hot Reload Integration

The hot reload system provides automatic browser refresh:

#### Frontend Integration

```twig
{# templates/base.html.twig #}
<!DOCTYPE html>
<html>
<head>
    <title>My App</title>
    {{ valksor_sse_importmap_definition() }}
</head>
<body>
    {% block body %}{% endblock %}
    {{ valksor_sse_importmap_scripts() }}
</body>
</html>
```

#### Custom Hot Reload Events

```php
<?php

namespace App\Service;

use ValksorDev\Build\Service\HotReloadService;

class CustomReloadService
{
    public function __construct(private HotReloadService $hotReloadService) {}

    public function triggerCustomReload(string $message): void
    {
        $this->hotReloadService->broadcast([
            'type' => 'custom-reload',
            'message' => $message,
            'timestamp' => time()
        ]);
    }
}
```

### Development Workflow

#### Typical Development Session

```bash
# 1. Choose your development mode:

# Lightweight development (SSE + hot reload only)
php bin/console valksor:dev

# OR Full development environment (all services)
php bin/console valksor:watch

# 2. Services that start automatically:
#    valksor:dev → binaries + hot_reload
#    valksor:watch → all services with 'dev' flag

# 3. Work on your files:
#    - Edit Twig templates → automatic browser reload
#    - Edit CSS → Tailwind recompiles → browser reload
#    - Edit JavaScript → import map updates → browser reload
#    - Edit PHP → browser reload

# 4. Stop the development server
#    Ctrl+C or kill the process
```

#### Production Build

```bash
# Build all production assets (runs all services with 'prod' flag)
php bin/console valksor-prod:build

# This will automatically run:
# 1. Initialization phase (binaries)
# 2. Production services (tailwind, importmap, assets, icons)
# 3. All services run with minification and optimization enabled
```

#### Service Selection Examples

```bash
# Quick frontend development (no heavy compilation)
php bin/console valksor:dev

# Full-stack development with all tooling
php bin/console valksor:watch

# Production deployment
php bin/console valksor-prod:build

# Individual service management
php bin/console valksor:tailwind
php bin/console valksor:importmap
php bin/console valksor:binary
```

## Advanced Usage

### Custom Binary Providers

Create custom binary providers:

```php
<?php

namespace App\Binary;

use ValksorDev\Build\Binary\BinaryInterface;

class CustomBinary implements BinaryInterface
{
    public function getName(): string
    {
        return 'custom-tool';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getBinaryPath(): string
    {
        return 'bin/custom-tool';
    }

    public function download(): void
    {
        // Download logic
    }

    public function isInstalled(): bool
    {
        return file_exists($this->getBinaryPath());
    }
}
```

### Custom Service Providers

```php
<?php

namespace App\Provider;

use ValksorDev\Build\Provider\ProviderInterface;

class CustomProvider implements ProviderInterface
{
    public function getName(): string
    {
        return 'custom-service';
    }

    public function start(): void
    {
        // Start custom service
    }

    public function stop(): void
    {
        // Stop custom service
    }

    public function isRunning(): bool
    {
        // Check if service is running
        return false;
    }
}
```

### Service Registration

```yaml
# config/services.yaml
services:
    App\Binary\CustomBinary:
        tags:
            - { name: 'valksor.binary_provider' }

    App\Provider\CustomProvider:
        tags:
            - { name: 'valksor.build_provider' }
```

## API Reference

### Commands

| Command                      | Description                                    |
|------------------------------|------------------------------------------------|
| `valksor:dev`                | Lightweight development (SSE + hot reload)     |
| `valksor:watch`              | Full development environment (all services)    |
| `valksor-prod:build`        | Production asset building                      |
| `valksor:hot-reload`         | Start hot reload service only                  |
| `valksor:tailwind`           | Build Tailwind CSS                             |
| `valksor:importmap`          | Mirror JavaScript assets for importmap usage   |
| `valksor:binary`             | Ensure tool binaries are downloaded            |
| `valksor:binaries:install`   | Install all required binaries                  |
| `valksor:icons`              | Generate Twig SVG icons                        |

### Command Behavior

| Command | Flags Used | Services Executed | Use Case |
|---------|------------|------------------|----------|
| `valksor:dev` | `dev` | `binaries`, `hot_reload` | Quick frontend development |
| `valksor:watch` | `dev` | All services with `dev` flag | Full development environment |
| `valksor-prod:build` | `prod` | `binaries` + all `prod` services | Production deployment |

### Configuration Options

| Section      | Option             | Type    | Default              | Description         |
|--------------|--------------------|---------|----------------------|---------------------|
| `hot_reload` | `enabled`          | boolean | true                 | Enable hot reload   |
|              | `watch_paths`      | array   | []                   | Paths to watch      |
|              | `exclude_patterns` | array   | []                   | Patterns to exclude |
|              | `debounce_ms`      | int     | 100                  | Debounce time       |
| `tailwind`   | `enabled`          | boolean | true                 | Enable Tailwind     |
|              | `input`            | string  | assets/css/app.css   | Input CSS file      |
|              | `output`           | string  | public/build/app.css | Output CSS file     |
|              | `minify`           | boolean | false                | Minify CSS          |
| `importmap`  | `enabled`          | boolean | true                 | Enable import maps  |
|              | `importmap_path`   | string  | importmap.json       | Import map file     |
| `binaries`   | `download_dir`     | string  | bin/build-tools/     | Binary directory    |
|              | `esbuild_version`  | string  | latest               | ESBuild version     |

## Troubleshooting

### Common Issues

1. **Binaries Not Downloaded**
   ```bash
   # Force re-download
   php bin/console valksor:binary --force
   ```

2. **File Watching Not Working**
   ```bash
   # Check inotify limits
   sudo sysctl fs.inotify.max_user_watches

   # Increase limit if needed
   echo 'fs.inotify.max_user_watches=524288' | sudo tee -a /etc/sysctl.conf
   sudo sysctl -p
   ```

3. **Port Already in Use**
   ```bash
   # Check if port 8080 is in use
   lsof -i :8080

   # Kill existing processes manually (pkill or kill)
   pkill -f valksor
   ```

4. **Permissions Issues**
   ```bash
   # Fix binary directory permissions
   chmod +x bin/build-tools/*
   ```

### Debug Mode

Enable debug logging:

```yaml
# config/packages/valksor.yaml
valksor:
    build:
        debug: true
        hot_reload:
            debug: true
        watching:
            debug: true
```


## Contributing

- Code style requirements (PSR-12)
- Testing requirements for PRs
- One feature per pull request
- Development setup instructions

To contribute to the build tools:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/new-build-tool`)
3. Implement your build tool following existing patterns
4. Add comprehensive tests
5. Ensure all tests pass and code style is correct
6. Submit a pull request

### Adding New Binary Providers

When adding support for new build tools:

1. Create a new binary class implementing `BinaryInterface`
2. Add tests in `tests/Binary/`
3. Register the binary in `BinaryAssetManager`
4. Update documentation with examples

## Security

If you discover any security-related issues, please email us at security@valksor.dev instead of using the issue tracker.

## Support

- **Documentation**: [Full documentation](https://github.com/valksor/valksor-dev)
- **Issues**: [GitHub Issues](https://github.com/valksor/valksor-dev/issues) for bug reports and feature requests
- **Discussions**: [GitHub Discussions](https://github.com/valksor/valksor-dev/discussions) for questions and community support
- **Stack Overflow**: Use tag `valksor-php-dev`
- **Build Tool Documentation**: Links to external tool documentation

## Credits

- **[Original Author](https://github.com/valksor)** - Creator and maintainer
- **[All Contributors](https://github.com/valksor/valksor-dev/graphs/contributors)** - Thank you to all who contributed
- **[Build Tool Authors](https://github.com/evanw/esbuild)** - ESBuild team and contributors
- **[Tailwind CSS Team](https://github.com/tailwindlabs/tailwindcss)** - Tailwind CSS framework
- **[DaisyUI Team](https://github.com/saadeghi/daisyui)** - Component library for Tailwind
- **[Valksor Project](https://github.com/valksor)** - Part of the larger Valksor PHP ecosystem

## Performance Considerations

- **File Handles**: Monitor inotify file handle limits
- **Memory Usage**: Binary tools may use significant memory
- **Network**: Binary downloads require internet connectivity
- **Disk Space**: Build tools require storage space

## License

This package is licensed under the [BSD-3-Clause License](LICENSE).

## About Valksor

This package is part of the [valksor/php-dev](https://github.com/valksor/valksor-dev) project - a comprehensive PHP library and Symfony bundle that provides a collection of utilities, components, and integrations for Symfony applications.

The main project includes:
- Various utility functions and components
- Doctrine ORM tools and extensions
- Symfony bundle for easy configuration
- And much more

If you find these Build tools useful, you might want to check out the full ValksorDev project for additional tools and utilities that can enhance your Symfony application development.

To install the complete package:

```bash
composer require valksor/php-dev
```
