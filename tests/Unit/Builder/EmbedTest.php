<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;

final class EmbedTest extends TestCase
{
    public function testKnownServices(): void
    {
        // 2.3: the Embed element takes only known services and builds their frame address; image/web.js allows the same
        $embed = fn (string $u): ?string => \Talea\Builder\Elements\Embed::resolve($u)[1] ?? null;
        $this->assertSame(['https://calendly.com/acme/consultation?embed_type=Inline&hide_gdpr_banner=1', 'https://docs.google.com/forms/d/e/1FAIpQLSf_x-1/viewform?embedded=true',
            'https://tally.so/embed/w7ZyYq?alignLeft=1&transparentBackground=1', 'https://open.spotify.com/embed/track/4uLU6hMCjMI75M1A2tKUQC',
            'https://w.soundcloud.com/player/?url=https%3A%2F%2Fsoundcloud.com%2Fartist%2Ftrack-name', null, null, null], [$embed('https://calendly.com/acme/consultation'), $embed('https://docs.google.com/forms/d/e/1FAIpQLSf_x-1/viewform?usp=sf_link'),
            $embed('https://tally.so/r/w7ZyYq'), $embed('https://open.spotify.com/track/4uLU6hMCjMI75M1A2tKUQC?si=x'), $embed('https://soundcloud.com/artist/track-name'),
            $embed('https://evil.example/calendly.com/x'), $embed('javascript:alert(1)'), $embed('https://calendly.com/a/b"onload=x')], '2.3: Embed – known services only');
        preg_match('/if \(!(\/\^https:.*?\/)\.test\(address\)\)/', (string) file_get_contents(TALEA_ROOT . '/image/web.js'), $webJsAllow);
        $jsPattern = '#' . str_replace('\\/', '/', substr($webJsAllow[1] ?? '//', 1, -1)) . '#';
        $this->assertSame([], array_keys(array_filter(\Talea\Builder\Elements\Embed::SERVICES,
            fn (array $s): bool => preg_match($jsPattern, sprintf($s[3], 'x')) !== 1)), '2.3: every Embed frame address is allowed in image/web.js');
    }
}
