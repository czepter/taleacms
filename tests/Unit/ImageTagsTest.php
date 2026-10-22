<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ImageTagsTest extends TestCase
{
    private const EXISTING = "v3.6.0\nv3.6.1\nv3.7.0\nv3.8.0-rc.1\nv0.9.0\n";

    /** tools/image-tags.sh: which tags a release moves (release.yml) */
    public function testTags(): void
    {
        $this->assertSame(['3.8.0', '3.8', '3', 'latest'], $this->tags('3.8.0'), 'a new stable release moves every line');
        $this->assertSame(['3.7.1', '3.7', '3', 'latest'], $this->tags('v3.7.1'), 'a patch of the newest line too');
        $this->assertSame(['3.6.2', '3.6'], $this->tags('3.6.2'), 'a patch of an older line keeps 3 and latest where they are');
        $this->assertSame(['3.9.0-rc.1'], $this->tags('3.9.0-rc.1'), 'a pre-release gets its own tag only');
        $this->assertSame(['3.8.0', '3.8', '3', 'latest'], $this->tags('3.8.0', "v3.8.0\nv3.7.0\n"), 'the release itself may be among the tags');
        $this->assertSame(['0.9.1', '0.9', 'latest'], $this->tags('0.9.1', ''), '0.x has no major tag; with no tags at all it is the newest');
    }

    public function testRefusesWhatIsNoVersion(): void
    {
        $this->assertNull($this->tags('main'));
    }

    /** @return list<string>|null the printed tags, null when the script fails */
    private function tags(string $version, string $existing = self::EXISTING): ?array
    {
        $process = proc_open(['bash', TALEA_ROOT . '/tools/image-tags.sh', $version], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        // the script may exit before it reads (not a version, a pre-release): a broken pipe is not a failure here
        @fwrite($pipes[0], $existing);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        return proc_close($process) === 0 ? array_values(array_filter(explode("\n", $out))) : null;
    }
}
