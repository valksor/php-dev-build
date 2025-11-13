# ValksorDev Build Tools

[![BSD-3-Clause](https://img.shields.io/badge/BSD--3--Clause-green?style=flat)](https://github.com/valksor/php-dev-build/blob/master/LICENSE)
[![Coverage Status](https://coveralls.io/repos/github/valksor/php-dev-build/badge.svg?branch=master)](https://coveralls.io/github/valksor/php-dev-build?branch=master)

A comprehensive development build tool suite for PHP applications that provides hot reloading, asset compilation, import map management, and development workflow automation. Designed to streamline modern PHP development with integrated tooling for Tailwind CSS, ESBuild, DaisyUI, and other frontend build tools.

## What It Does

The build system provides a modern development experience with:
- **Automatic Browser Reload**: Files change → browser refreshes automatically
- **Tailwind CSS Compilation**: Real-time CSS compilation with DaisyUI support
- **Import Map Management**: Modern JavaScript dependency handling
- **Binary Management**: Automatic download and management of build tools
- **Icon Generation**: SVG icon processing with Lucide integration

## Configuration

### Basic Setup

```yaml
# config/packages/valksor.yaml
valksor:
    build:
        enabled: true
        hot_reload:
            enabled: true
        tailwind:
            enabled: true
        importmap:
            enabled: true
```

### Complete Configuration Reference

```yaml
# config/packages/valksor.yaml
valksor:
    build:
        enabled: true

        # Hot Reload Configuration
        hot_reload:
            enabled: true
            debounce_delay: 0.3        # Seconds to wait before triggering reload
            watch_dirs:                # Directories to watch
                - 'templates/'
                - 'src/'
                - 'assets/'
            extended_extensions:        # File-specific debounce times
                php: 0.1              # PHP files: 100ms debounce
                js: 0.1               # JavaScript files: 100ms debounce
                css: 0.2              # CSS files: 200ms debounce
                twig: 0.3             # Twig files: 300ms debounce
            extended_suffixes:          # File suffix-specific timing
                '.tailwind.css': 0.5   # Compiled CSS: 500ms debounce
                '.min.css': 0.3        # Minified CSS: 300ms debounce

        # Tailwind CSS Configuration
        tailwind:
            enabled: true
            minify: false              # Set to true for production
            watch: true                # Watch for changes in development

        # Import Map Configuration
        importmap:
            enabled: true
            watch: true                # Auto-update import maps
            minify: false              # Set to true for production

        # Binary Management
        binaries:
            required:                  # Tools to download automatically
                - 'tailwindcss'
                - 'esbuild'
            cache_duration: 3600       # Cache binary downloads for 1 hour

            # Download strategy for binaries
            download_strategy: 'release'  # 'release' (default), 'tag', 'commit'
            commit_ref: 'main'           # Optional: branch/commit for 'commit' strategy
```

### Service Configuration (Advanced)

For fine-grained control over which services run:

```yaml
# config/packages/valksor.yaml
valksor:
    build:
        services:
            binaries:
                enabled: true
                flags: ['init', 'dev', 'prod']     # When to run this service
                options:
                    required: ['tailwindcss', 'esbuild']

            hot_reload:
                enabled: true
                flags: ['dev']                      # Run only in development
                options:
                    debounce_delay: 0.3
                    watch_dirs: ['/src', '/templates']

            tailwind:
                enabled: true
                flags: ['dev', 'prod']              # Run in dev and production
                options:
                    minify: false                   # Will be true in prod

            importmap:
                enabled: true
                flags: ['dev', 'prod']
                options:
                    watch: true
                    minify: false
```

**Service Flags Explained:**
- `init`: Runs during initialization (binary downloads, setup)
- `dev`: Runs during development (`valksor:dev`, `valksor:watch`)
- `prod`: Runs during production builds (`valksor-prod:build`)

### Binary Download Strategies

The build system supports multiple strategies for downloading binary tools:

#### Download Strategies

**release**: Downloads from GitHub releases with pre-compiled binaries (default)

**tag**: Downloads from Git tags when no GitHub releases exist

**commit**: Downloads from specific commits or branches

**Usage Examples:**
```yaml
# Download from releases (default)
binaries:
    download_strategy: 'release'

# Download from tags
binaries:
    download_strategy: 'tag'

# Download from specific branch
binaries:
    download_strategy: 'commit'
    commit_ref: 'develop'
```

**Strategy Selection:**
- Use **release** for tools with proper GitHub releases and compiled binaries
- Use **tag** for tools that only publish tags without releases
- Use **commit** for tools that need the latest code from a specific branch

## Development Commands

### Main Development Workflow

```bash
# Lightweight development (fast feedback)
php bin/console valksor:dev
# Runs: binaries + hot_reload

# Full development environment (all services)
php bin/console valksor:watch
# Runs: all services with 'dev' flag
```

### Individual Service Commands

```bash
# Build Tailwind CSS manually
php bin/console valksor:tailwind

# Update import maps manually
php bin/console valksor:importmap

# Hot reload only
php bin/console valksor:hot-reload

# Download/update build tools
php bin/console valksor:binary

# Generate icons
php bin/console valksor:icons

# Production build
php bin/console valksor-prod:build
```

### Command Options

```bash
# Multi-app projects: run on specific app
php bin/console valksor:watch --id=www
php bin/console valksor:watch --id=admin

# Non-interactive mode (for scripts/CI)
php bin/console valksor:watch --non-interactive

# Different environment
php bin/console valksor:dev --env=prod
```

## Project Structure Support

### Single-App Projects (Default)
Works out of the box with standard Symfony structure:
- `src/` - PHP files
- `templates/` - Twig templates
- `assets/` - CSS, JavaScript, images
- `public/` - Compiled assets

### Multi-App Projects
For projects with multiple applications:

```yaml
# config/packages/valksor.yaml
valksor:
    build:
        project:
            apps_dir: 'apps'              # Directory containing apps
            infrastructure_dir: 'src'     # Shared infrastructure code
```

This automatically discovers:
- Tailwind files in each app directory
- Import maps per application
- Icons for each app
- App-specific configuration

## File Watching Patterns

### Default Watched Paths
- `templates/` - Twig templates
- `src/` - PHP files
- `assets/` - CSS, JavaScript, images

### Custom Watch Paths
```yaml
valksor:
    build:
        hot_reload:
            watch_dirs:
                - 'config/'          # Watch configuration files
                - 'translations/'    # Watch translation files
                - 'public/assets/'   # Watch compiled assets
```

### File-Specific Debounce
Different file types can have different reload delays:

```yaml
valksor:
    build:
        hot_reload:
            extended_extensions:
                php: 0.1           # PHP files: quick reload
                twig: 0.5          # Templates: slower reload
                css: 0.3           # CSS: medium speed
            extended_suffixes:
                '.tailwind.css': 1.0   # Compiled CSS: slowest
```

## Tailwind CSS Integration

### Basic Tailwind Setup

1. Create your CSS file:
```css
/* assets/css/app.css */
@tailwind base;
@tailwind components;
@tailwind utilities;
```

2. Configure Valksor:
```yaml
valksor:
    build:
        tailwind:
            enabled: true
```

3. Start development:
```bash
php bin/console valksor:watch
```

### With DaisyUI

```yaml
valksor:
    build:
        tailwind:
            enabled: true
            # DaisyUI is automatically supported
```

### Tailwind Configuration

Create `tailwind.config.js` in your project root:

```javascript
module.exports = {
  content: [
    './templates/**/*.html.twig',
    './src/**/*.php',
    './assets/js/**/*.js',
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

## Import Map Management

### Basic Setup

```yaml
valksor:
    build:
        importmap:
            enabled: true
```

### Using Import Maps

```javascript
// assets/js/app.js
import { hotwire } from '@hotwired/turbo';
import { stimulus } from '@hotwired/stimulus';

// Import maps automatically resolve these imports
```

## Icon Generation

### Basic Icon Setup

```yaml
valksor:
    build:
        icons:
            enabled: true
```

### Generate Icons

```bash
php bin/console valksor:icons
```

Icons are automatically available as Twig functions:

```twig
{{ icon('lucide-user') }}     <!-- Lucide icon -->
{{ icon('custom-icon') }}     <!-- Custom SVG icon -->
```

## Troubleshooting

### Common Issues

**Binary Downloads Fail**
```bash
# Force re-download of build tools
php bin/console valksor:binary
```

**File Watching Not Working**
- Check file permissions on watched directories
- Verify inotify limits (Linux): `cat /proc/sys/fs/inotify/max_user_watches`
- For Docker, ensure volume mounts are correct

**Tailwind Compilation Issues**
- Check your `tailwind.config.js` exists
- Verify content paths in Tailwind config
- Ensure input CSS file exists with `@tailwind` directives

**Port Conflicts**
The build system uses default ports that may conflict:
- Hot reload: Port 8080 (configurable)

### Debug Mode

Enable debug logging:

```yaml
valksor:
    build:
        debug: true
        hot_reload:
            debug: true
```

## Requirements

- **PHP 8.4 or higher**
- **inotify extension** (for file watching - Linux only)
- **PCNTL extension** (for process management)
- **POSIX extension**
- **Symfony Framework** (7.2.0 or higher)
- **Valksor Bundle** (valksor/php-bundle)
- **Valksor SSE Component** (valksor/php-sse)

### Platform Requirements

⚠️ **Linux Only Required**

The ValkDev Build Tools require **Linux** due to their dependency on the **inotify** extension for efficient file system monitoring.

- **inotify** is a Linux kernel subsystem available only on Linux platforms
- File watching is essential for the hot reload, Tailwind compilation, and development workflow features
- These build tools are not compatible with Windows or macOS
- All core functionality (hot reload, asset compilation, import map management) depends on real-time file monitoring

## Installation

Install the package via Composer:

```bash
composer require valksor/php-dev-build
```

Note: This package is also included in the meta-package `valksor/php-dev`.