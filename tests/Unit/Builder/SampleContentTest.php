<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Talea\Builder\Library;
use Talea\Builder\SampleContent;

/** #54: no invented content – starter sections carry bracketed placeholders and the detector reports them (and the old demo texts). */
#[CoversClass(SampleContent::class)]
final class SampleContentTest extends TestCase
{
    /** @param array<string, mixed> $content */
    private function build(string $type, array $content): array
    {
        return ['v' => 1, 'children' => [['id' => 'a1', 'type' => 'section', 'content' => [], 'children' => [['id' => 'b2', 'type' => $type, 'content' => $content, 'children' => []]]]]];
    }

    public function testPlaceholdersAreFoundInAnyContentField(): void
    {
        $found = SampleContent::find($this->build('testimonial', ['text' => 'Great.', 'author' => '[Customer’s name]', 'position' => '']));
        $this->assertSame([['id' => 'b2', 'text' => '[Customer’s name]']], $found);
        $this->assertCount(1, SampleContent::find($this->build('faq', ['items' => [['question' => 'Price?', 'answer' => '<p>From <strong>[price]</strong>.</p>']]])));
    }

    public function testEarlierInventedReviewsAndNamesAreFoundToo(): void
    {
        $this->assertCount(1, SampleContent::find($this->build('testimonial', ['text' => 'Fast, clean and for the agreed price.', 'author' => 'Real Person', 'position' => ''])));
        $this->assertCount(1, SampleContent::find($this->build('heading', ['text' => ' jane  doe '])));
    }

    public function testRealContentIsLeftAlone(): void
    {
        $this->assertSame([], SampleContent::find($this->build('text', ['html' => '<p>See [1] and {{fact.projects}}; call 555 [0].</p>'])));
        $this->assertSame([], SampleContent::find($this->build('counter', ['number' => '{{years_since:2004}}', 'caption' => 'years'])));
        $this->assertSame([], SampleContent::find(['v' => 1, 'children' => []]));
    }

    public function testTextPagesAndNewsAreScannedForPlaceholders(): void
    {
        $this->assertSame(['[price]', '[Name]'], SampleContent::inText('<p>From [price] &ndash; ask [Name] [price]</p>'));
        $this->assertSame([], SampleContent::inText('<p>Nothing to fill in [2].</p>'));
    }

    public function testFindingsAndSummaryNameWhatIsLeft(): void
    {
        $build = $this->build('counter', ['number' => '[number]', 'caption' => 'projects']);
        $this->assertSame([['id' => 'b2', 'message' => 'Sample content “[number]” – replace it with your own or delete it.']], SampleContent::findings($build));
        $this->assertStringContainsString('[number]', SampleContent::summary(SampleContent::find($build)));
    }

    public function testNoStarterSectionBringsAnInventedReviewNameOrPrice(): void
    {
        $with = 0;
        foreach (array_column(Library::listAll(), 'key') as $key) {
            $build = ['v' => 1, 'children' => [Library::section($key, 'en')['element']]];
            foreach (SampleContent::find($build) as $f) {
                $this->assertMatchesRegularExpression(SampleContent::PLACEHOLDER, $f['text'], $key . ' still has an invented text: ' . $f['text']);
            }
            $with += SampleContent::find($build) === [] ? 0 : 1;
        }
        $this->assertGreaterThanOrEqual(8, $with, 'testimonials, reviews, numbers, pricing, team, branches … carry placeholders');
    }
}
