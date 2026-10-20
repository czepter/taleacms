<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;

final class BuildCheckTest extends TestCase
{
    public function testPrePublishCheck(): void
    {
        $buildCheck = \Talea\Builder\Check::builds(['v' => 1, 'children' => [['id' => 's', 'type' => 'section', 'children' => [
            ['id' => 'a', 'type' => 'heading', 'tag' => 'h2', 'content' => ['text' => 'Služby']], // check-english: allow
            ['id' => 'b', 'type' => 'heading', 'tag' => 'h4', 'content' => ['text' => 'Detail']],
            ['id' => 'c', 'type' => 'button', 'content' => ['text' => 'Poptat', 'link' => '#']],
            ['id' => 'd', 'type' => 'image', 'content' => ['src' => 'media/a.jpg', 'alt' => '']],
            ['id' => 'e', 'type' => 'image', 'content' => ['src' => '{{photo}}', 'alt' => '']],
            ['id' => 'f', 'type' => 'heading', 'tag' => 'p', 'content' => ['text' => '01']],
        ]]]], true);
        $this->assertSame(['c', 'd', 'a', 'b'], array_column($buildCheck, 'id'), 'Build check: button without a link, image without alt, missing h1 and a skipped level');
    }
}
