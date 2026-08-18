<?php

namespace justinholtweb\scrub\tests\unit;

use justinholtweb\scrub\matching\Literal;
use PHPUnit\Framework\TestCase;

/**
 * The prefilter's literal extractor.
 *
 * Only one property really matters: whatever it returns must be a string that *every* subject
 * matching the pattern also contains. Return too little and the scan is slow; return something that
 * isn't actually required and rows are silently missed, which would make the preview a lie.
 *
 * So every test here is either "it found the run" or "it correctly gave up".
 */
class LiteralTest extends TestCase
{
    public function testFindsTheLongestLiteralRun(): void
    {
        self::assertSame('href="/old-docs/', Literal::requiredSubstring('href="/old-docs/[\w-]+"'));
    }

    public function testEscapedPunctuationIsLiteral(): void
    {
        // The `s?` is dropped, so the run starts at the colon — which is still required.
        self::assertSame('://example.com/', Literal::requiredSubstring('https?://example\.com/\w+'));
    }

    public function testGivesUpOnAlternation(): void
    {
        self::assertNull(Literal::requiredSubstring('ACME (Inc|Ltd)'));
    }

    /**
     * `colou?r` requires "colo", not "colou" — the character before a `?` is exactly the one that
     * might not be there.
     */
    public function testDropsTheCharacterBeforeAnOptionalQuantifier(): void
    {
        self::assertSame('colo', Literal::requiredSubstring('colou?r'));
    }

    public function testDropsTheCharacterBeforeAStar(): void
    {
        self::assertSame('foo', Literal::requiredSubstring('foos*bar'));
    }

    public function testKeepsTheCharacterBeforeAPlus(): void
    {
        // `foos+` requires at least one "s", so "foos" is genuinely required.
        self::assertSame('foos', Literal::requiredSubstring('foos+bar'));
    }

    public function testHandlesRangeQuantifiers(): void
    {
        self::assertSame('widge', Literal::requiredSubstring('widget{0,3}'));
        self::assertSame('widget', Literal::requiredSubstring('widget{2,}'));
    }

    public function testCharacterClassesEndTheRun(): void
    {
        self::assertSame('-report', Literal::requiredSubstring('[0-9]+-report'));
    }

    public function testClosingBracketInsideAClassIsNotTheClose(): void
    {
        // `[]]` is a class matching one square bracket. Mis-parsing it would end the class early and
        // treat "abc" as literal when it is inside the class.
        self::assertSame('after', Literal::requiredSubstring('[]]after'));
    }

    public function testGroupsEndTheRun(): void
    {
        self::assertSame('-report', Literal::requiredSubstring('(\d{4})-report'));
    }

    public function testShortRunsAreNotWorthIt(): void
    {
        self::assertNull(Literal::requiredSubstring('a\d+b'));
    }

    public function testAnchorsAndDotsEndTheRun(): void
    {
        self::assertSame('the company', Literal::requiredSubstring('^the company.*$'));
    }

    public function testClassShorthandIsNotLiteral(): void
    {
        self::assertNull(Literal::requiredSubstring('\d\w\s'));
    }
}
