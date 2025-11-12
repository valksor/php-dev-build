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

namespace ValksorDev\Build\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ValksorDev\Build\Service\PathFilter;

final class PathFilterTest extends TestCase
{
    public function testDirectoryFiltering(): void
    {
        $filter = PathFilter::createDefault('/test/project');

        self::assertTrue($filter->shouldIgnoreDirectory('node_modules'));
        self::assertTrue($filter->shouldIgnoreDirectory('NODE_MODULES'));
        self::assertFalse($filter->shouldIgnoreDirectory('src'));
    }

    public function testPathFilteringRules(): void
    {
        $filter = PathFilter::createDefault('/test/project');

        self::assertFalse($filter->shouldIgnorePath(null));

        $ref = new ReflectionClass(PathFilter::class);
        $ignoredFilenames = $ref->getProperty('ignoredFilenames')->getValue($filter);
        self::assertContains('.gitignore', $ignoredFilenames);

        $ignoredExtensions = $ref->getProperty('ignoredExtensions')->getValue($filter);
        self::assertContains('.md', $ignoredExtensions);

        self::assertStringContainsString(
            'src/ValksorDev/Build/Service/PathFilter.php',
            $ref->getFileName(),
        );

        $basename = strtolower(pathinfo('app/.gitignore', PATHINFO_BASENAME));
        self::assertSame('.gitignore', $basename);
        self::assertContains($basename, $ignoredFilenames);
        self::assertContains($basename, $ignoredFilenames);

        $result = $filter->shouldIgnorePath('app/.gitignore');
        self::assertTrue($result, 'shouldIgnorePath returned false for .gitignore');
        self::assertTrue($filter->shouldIgnorePath('README.md'));
        self::assertTrue($filter->shouldIgnorePath('src/node_modules/package/index.js')); // Should be ignored by **/node_modules/** pattern

        self::assertFalse($filter->shouldIgnorePath('src/Controller/HomeController.php'));
        self::assertFalse($filter->shouldIgnorePath('resources/styles/app.css'));
        self::assertFalse($filter->shouldIgnorePath('docs/guide.txt'));
    }
}
