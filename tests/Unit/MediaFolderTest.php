<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Talea\Core\MediaFolder;
use Talea\Core\MediaHygiene;
use Talea\Core\MediaStaging;

/** Uploads of a further owner live in media/<owner public id>/Y/m/ (docs/specs/multisite.md, "Several owners", stage B); owner 1 keeps media/Y/m/. */
#[CoversClass(MediaFolder::class)]
final class MediaFolderTest extends TestCase
{
    private const string OWNER = '7a2d0c9e-0b0e-4a53-9a58-3c1f6f0f2b0b';

    protected function tearDown(): void
    {
        MediaFolder::useOwner('');
    }

    public function testTheFolderFollowsTheOwnerBeingServed(): void
    {
        $this->assertSame('media', MediaFolder::dir());
        MediaFolder::useOwner(self::OWNER);
        $this->assertSame('media/' . self::OWNER, MediaFolder::dir());
        $this->assertSame('media/' . self::OWNER . '/icon-32.png', MediaFolder::icon(32));
        $this->assertSame('media/icon-32.png', MediaFolder::dirOf('') . '/icon-32.png');
        MediaFolder::useOwner('../../etc');
        $this->assertSame('media', MediaFolder::dir(), 'only a public id is a folder name');
    }

    public function testOwnerFoldersAreToldApartFromTheDatesOfOwnerOne(): void
    {
        $this->assertTrue(MediaFolder::isOwnerDir(self::OWNER));
        foreach (['2026', 'icon-32.png', self::OWNER . 'x', 'UPPER' . substr(self::OWNER, 5), ''] as $name) {
            $this->assertFalse(MediaFolder::isOwnerDir($name), $name);
        }
    }

    public function testPatternsAcceptTheOwnerFolderAndTheOldShape(): void
    {
        $staging = new \ReflectionClassConstant(MediaStaging::class, 'PATH');
        foreach (['media/2026/10/a-1b2c3d.jpg', 'media/' . self::OWNER . '/2026/10/a-1b2c3d.jpg', 'media/' . self::OWNER . '/2026/10/a-1b2c3d-1200.jpg.webp'] as $path) {
            $this->assertSame(1, preg_match((string) $staging->getValue(), $path), $path);
        }
        foreach (['media/' . self::OWNER . '/x/2026/10/a-1b2c3d.jpg', 'media/other/2026/10/a-1b2c3d.jpg'] as $path) {
            $this->assertSame(0, preg_match((string) $staging->getValue(), $path), $path);
        }
        $this->assertSame(1, preg_match('#^(media/' . MediaFolder::OWNER . '\d{4}/\d{2}/[a-z0-9-]+)\.(jpg|png)$#', 'media/' . self::OWNER . '/2026/10/a-1b2c3d.png', $m));
        $this->assertSame('media/' . self::OWNER . '/2026/10/a-1b2c3d', $m[1]);
    }

    public function testReferencesInContentKeepTheOwnerFolderAndStripVariants(): void
    {
        $content = '<img src="/media/' . self::OWNER . '/2026/10/a-1b2c3d-nahled.jpg.webp"> {"image":"media\\/' . self::OWNER . '\\/2026\\/10\\/b-4e5f6a.png"} media/2026/10/c-7b8c9d.jpg';
        $this->assertEqualsCanonicalizing(['media/' . self::OWNER . '/2026/10/a-1b2c3d.jpg', 'media/' . self::OWNER . '/2026/10/b-4e5f6a.png', 'media/2026/10/c-7b8c9d.jpg'], MediaHygiene::paths($content));
    }
}
