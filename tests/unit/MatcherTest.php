<?php

namespace justinholtweb\scrub\tests\unit;

use InvalidArgumentException;
use justinholtweb\scrub\matching\Matcher;
use PHPUnit\Framework\TestCase;

/**
 * The matching core.
 *
 * Every case here is one that a naive `str_replace` gets wrong, which is the entire argument for
 * the plugin existing.
 */
class MatcherTest extends TestCase
{
    public function testPlainReplacesEveryOccurrence(): void
    {
        $matcher = Matcher::compile(Matcher::MODE_PLAIN, 'cat', 'dog');

        self::assertSame('dog dog dogalogue', $matcher->replace('cat cat catalogue', $count));
        self::assertSame(3, $count);
    }

    public function testPlainIsCaseSensitiveByDefault(): void
    {
        $matcher = Matcher::compile(Matcher::MODE_PLAIN, 'cat', 'dog');

        self::assertSame('dog Cat CAT', $matcher->replace('cat Cat CAT'));
    }

    public function testPlainCanIgnoreCase(): void
    {
        $matcher = Matcher::compile(Matcher::MODE_PLAIN, 'cat', 'dog', caseSensitive: false);

        self::assertSame('dog dog dog', $matcher->replace('cat Cat CAT'));
    }

    /**
     * The reason word mode exists. Core's find and replace turns "catalogue" into "dogalogue" and
     * there is nothing you can do about it.
     */
    public function testWordModeLeavesSubstringsAlone(): void
    {
        $matcher = Matcher::compile(Matcher::MODE_WORD, 'cat', 'dog');

        self::assertSame('dog catalogue bobcat dog.', $matcher->replace('cat catalogue bobcat cat.'));
    }

    /**
     * `\b` cannot express this: there is no word boundary after the `+`, so `\bC\+\+\b` never
     * matches. Lookarounds can, which is why the pattern is built with them.
     */
    public function testWordModeHandlesNeedlesEndingInPunctuation(): void
    {
        $matcher = Matcher::compile(Matcher::MODE_WORD, 'C++', 'Rust');

        self::assertSame('Rust and Rust.', $matcher->replace('C++ and C++.'));
    }

    public function testWordModeRespectsAccentedLetters(): void
    {
        $matcher = Matcher::compile(Matcher::MODE_WORD, 'cafe', 'bar');

        // “café” is one word, so the “cafe” inside it is not a whole word.
        self::assertSame('bar café', $matcher->replace('cafe café'));
    }

    public function testRegexCapturesAreExpanded(): void
    {
        $matcher = Matcher::compile(Matcher::MODE_REGEX, '(\d{4})-(\d{2})-(\d{2})', '$3/$2/$1');

        self::assertSame('on 18/08/2026 at noon', $matcher->replace('on 2026-08-18 at noon'));
    }

    public function testRegexAcceptsBackslashAndBracedReferences(): void
    {
        $matcher = Matcher::compile(Matcher::MODE_REGEX, '(\w+)@(\w+)', '${2} of \1');

        self::assertSame('example of jane', $matcher->replace('jane@example'));
    }

    /**
     * A dollar amount in the replace box of a plain find is a dollar amount.
     */
    public function testPlainReplacementIsNotTreatedAsBackreference(): void
    {
        $matcher = Matcher::compile(Matcher::MODE_PLAIN, 'PRICE', '$1.00', caseSensitive: false);

        self::assertSame('costs $1.00', $matcher->replace('costs price'));
    }

    public function testPreserveCaseFollowsTheMatch(): void
    {
        $matcher = Matcher::compile(
            Matcher::MODE_WORD,
            'widget',
            'gadget',
            caseSensitive: false,
            preserveCase: true,
        );

        self::assertSame('gadget Gadget GADGET', $matcher->replace('widget Widget WIDGET'));
    }

    /**
     * The case that made the single-token version of this useless. “Acme Ltd” is not all-upper, not
     * all-lower and not one capitalised word, so a rule that looks at the match as a single token
     * gives up and returns the replacement exactly as typed — turning “Acme Ltd” into “acme inc”.
     */
    public function testPreserveCaseWorksWordByWord(): void
    {
        $matcher = Matcher::compile(
            Matcher::MODE_PLAIN,
            'acme ltd',
            'acme inc',
            caseSensitive: false,
            preserveCase: true,
        );

        self::assertSame('Acme Inc', $matcher->replace('Acme Ltd'));
        self::assertSame('ACME INC', $matcher->replace('ACME LTD'));
        self::assertSame('acme inc', $matcher->replace('acme ltd'));
        self::assertSame('Acme INC', $matcher->replace('Acme LTD'));
    }

    public function testPreserveCaseReusesTheLastShapeForExtraWords(): void
    {
        $matcher = Matcher::compile(
            Matcher::MODE_PLAIN,
            'acme ltd',
            'acme incorporated limited',
            caseSensitive: false,
            preserveCase: true,
        );

        self::assertSame('ACME INCORPORATED LIMITED', $matcher->replace('ACME LTD'));
    }

    public function testPreserveCaseLeavesAmbiguousCasingAlone(): void
    {
        $matcher = Matcher::compile(
            Matcher::MODE_WORD,
            'widget',
            'gadget',
            caseSensitive: false,
            preserveCase: true,
        );

        self::assertSame('gadget', $matcher->replace('wIdGeT'));
    }

    public function testHitsCarryTheirContext(): void
    {
        $matcher = Matcher::compile(Matcher::MODE_PLAIN, 'needle', 'pin');
        $hits = $matcher->hits('a long sentence with a needle buried in the middle of it', context: 10);

        self::assertCount(1, $hits);
        self::assertSame('needle', $hits[0]->matched);
        self::assertSame('pin', $hits[0]->replacement);
        self::assertSame('ce with a ', $hits[0]->before);
        self::assertSame(' buried in', $hits[0]->after);
    }

    public function testHitsAreCapped(): void
    {
        $matcher = Matcher::compile(Matcher::MODE_PLAIN, 'x', 'y');

        self::assertCount(5, $matcher->hits(str_repeat('x ', 100), limit: 5));
        self::assertSame(100, $matcher->count(str_repeat('x ', 100)));
    }

    public function testHitsReportPreserveCasedReplacements(): void
    {
        $matcher = Matcher::compile(
            Matcher::MODE_PLAIN,
            'widget',
            'gadget',
            caseSensitive: false,
            preserveCase: true,
        );

        $hits = $matcher->hits('WIDGET');

        self::assertSame('GADGET', $hits[0]->replacement);
    }

    public function testContextIsCutBackToValidUtf8(): void
    {
        // The window lands mid-character on purpose: “é” is two bytes and the excerpt is measured
        // in bytes, as PCRE offsets are.
        $matcher = Matcher::compile(Matcher::MODE_PLAIN, 'x', 'y');
        $hits = $matcher->hits('café x', context: 3);

        self::assertSame('é ', $hits[0]->before);
        self::assertTrue(mb_check_encoding($hits[0]->before, 'UTF-8'));
    }

    public function testMatchesIsCheapAndAgrees(): void
    {
        $matcher = Matcher::compile(Matcher::MODE_WORD, 'cat', 'dog');

        self::assertTrue($matcher->matches('one cat here'));
        self::assertFalse($matcher->matches('one catalogue here'));
    }

    public function testEmptyNeedleIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Matcher::compile(Matcher::MODE_PLAIN, '');
    }

    public function testBrokenPatternIsExplained(): void
    {
        $error = Matcher::explain(Matcher::MODE_REGEX, '(unclosed');

        self::assertNotNull($error);
        self::assertStringContainsString('offset', $error);
    }

    public function testGoodPatternExplainsNothing(): void
    {
        self::assertNull(Matcher::explain(Matcher::MODE_REGEX, '\d+'));
    }

    /**
     * The delimiter is chosen, not fixed, so a pattern full of slashes needs no escaping by the
     * person writing it.
     */
    public function testPatternContainingDelimitersStillCompiles(): void
    {
        $matcher = Matcher::compile(Matcher::MODE_REGEX, 'https?://old\.example\.com/(\w+)', '/$1');

        self::assertSame('go to /docs now', $matcher->replace('go to https://old.example.com/docs now'));
    }

    public function testUserModifiersCannotBeSmuggledIn(): void
    {
        // If the delimiter were fixed and appended blindly, this would close the pattern early and
        // add modifiers of the user's choosing. It compiles as a literal instead — or not at all.
        $error = Matcher::explain(Matcher::MODE_REGEX, 'a~i');

        self::assertNull($error);

        $matcher = Matcher::compile(Matcher::MODE_REGEX, 'a~i', 'X');

        self::assertSame('X', $matcher->replace('a~i'));
        self::assertSame('A~I', $matcher->replace('A~I'));
    }

    public function testMultilineAndDotAllAreOptIn(): void
    {
        $plain = Matcher::compile(Matcher::MODE_REGEX, 'a.b', 'X');
        self::assertSame("a\nb", $plain->replace("a\nb"));

        $dotAll = Matcher::compile(Matcher::MODE_REGEX, 'a.b', 'X', flags: ['dotAll' => true]);
        self::assertSame('X', $dotAll->replace("a\nb"));

        $multiline = Matcher::compile(Matcher::MODE_REGEX, '^b', 'X', flags: ['multiline' => true]);
        self::assertSame("a\nX", $multiline->replace("a\nb"));
    }
}
