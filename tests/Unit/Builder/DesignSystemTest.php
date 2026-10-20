<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;

final class DesignSystemTest extends TestCase
{
    public function testContrastAndEnglishTokens(): void
    {
        $this->assertSame(21.0, round(\Talea\Builder\DesignSystem::contrast('#ffffff', '#000000'), 1), 'DesignSystem::contrast: black on white');
        // 2.1: the design system tokens are English custom properties – color, font, width, text-width, radius, step, space, shadow, type
        $dsCss = \Talea\Builder\DesignSystem::css(\Talea\Builder\DesignSystem::DEFAULTS);
        preg_match_all('/^\t(--tl-[a-z0-9-]+):/m', substr($dsCss, 0, (int) strpos($dsCss, '@media')), $dsStored);
        $this->assertSame([[], true, true, true], [array_values(array_filter($dsStored[1], fn (string $name): bool => !preg_match('/^--tl-(color|font|radius|step|space|shadow|type)-|^--tl-(width|text-width|radius|accent)$/', $name))),
            str_contains($dsCss, '--tl-color-primary: #2b5be3;'), str_contains($dsCss, '@layer tokens {'), str_contains($dsCss, '--tl-type-lead: 400 var(--tl-step-1)/1.55 var(--tl-font-body);')], '2.1: the design system tokens are English');
    }

    public function testLayersAndSanitize(): void
    {
        $this->assertTrue(str_starts_with(\Talea\Builder\DesignSystem::css(\Talea\Builder\DesignSystem::DEFAULTS), \Talea\Builder\DesignSystem::LAYERS), 'DesignSystem::css: layer order at the start');
        $this->assertSame(\Talea\Builder\DesignSystem::DEFAULTS['colors']['primary'], \Talea\Builder\DesignSystem::sanitize(['colors' => ['primary' => 'red;}']])['colors']['primary'], 'DesignSystem::sanitize: nonsense is replaced by the default');
    }
}
