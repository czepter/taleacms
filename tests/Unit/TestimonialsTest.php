<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\Testimonials;

final class TestimonialsTest extends TestCase
{
    /** 2.12: testimonial requests with consent (Core\Testimonials) */
    public function testRequests(): void
    {
        $this->assertSame(// check-english: allow
            [['text' => 'Skvělá spolupráce, doporučuji.', 'name' => 'Eva', 'role' => 'ředitelka ACME', 'words' => true, 'photo' => false], true, true, true], [ // check-english: allow
            Testimonials::clean(['text' => 'Skvělá spolupráce, <b>doporučuji</b>.', 'name' => ' Eva ', 'role' => "ředitelka\nACME", 'consent_words' => '1']), // check-english: allow
            Testimonials::clean(['text' => 'krátce', 'name' => 'Eva', 'consent_words' => '1']) === t('Please write a few words.'), // check-english: allow
            Testimonials::clean(['text' => 'Skvělá spolupráce s firmou.', 'name' => 'Eva']) === t('We can publish your words only with your consent.'), // check-english: allow
            Testimonials::clean(['text' => 'Skvělá spolupráce s firmou.', 'name' => '', 'consent_words' => '1']) === t('Please fill in your name.')], '2.12 Testimonials::clean – words, a name and the consent to publish them are required; tags never get through'); // check-english: allow
        $this->assertSame([30, 'references', 2], [Testimonials::DAYS, Testimonials::PRESET, (new \ReflectionMethod(Testimonials::class, 'find'))->getNumberOfParameters()], '2.12 Testimonials: the link is 32 hex characters and a valid-looking but unknown one finds nothing without the database');
    }
}
