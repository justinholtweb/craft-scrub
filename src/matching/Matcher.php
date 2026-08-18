<?php

namespace justinholtweb\scrub\matching;

use InvalidArgumentException;
use RuntimeException;

/**
 * Turns a find/replace definition into something that can be run against a string.
 *
 * Deliberately free of Craft: this class is the part of Scrub that has to be *right*, and the
 * cheapest way to keep it right is to be able to test it without a database, an application or a
 * fixture. Everything else in the plugin decides *which* strings to hand to a Matcher; this decides
 * what happens to them.
 *
 * Three modes, and the difference between them is only ever how the pattern is built:
 *
 * - `plain` — the needle is literal. Runs through `strpos` rather than PCRE when it can, because a
 *   literal scan has no backtrack limit and content fields get very long.
 * - `word` — literal, but only where it isn't glued to another word character. Written with
 *   lookarounds rather than `\b` so that a needle starting or ending in punctuation (`C++`, `.env`)
 *   still behaves the way anyone would expect.
 * - `regex` — the pattern is the user's. They never supply delimiters or modifiers: the delimiter
 *   is picked here, and the modifiers come from checkboxes. That closes off `/e` and every other
 *   way a "search box" turns into code execution.
 */
final class Matcher
{
    public const MODE_PLAIN = 'plain';
    public const MODE_WORD = 'word';
    public const MODE_REGEX = 'regex';

    /** Delimiters tried in order. The first one absent from the pattern wins. */
    private const DELIMITERS = ['~', '#', '%', '!', '@', '/', '|', '+', '='];

    /** How much text either side of a match the context excerpt carries. */
    public const CONTEXT_CHARS = 60;

    private function __construct(
        private readonly string $mode,
        private readonly string $find,
        private readonly string $replace,
        private readonly bool $preserveCase,
        private readonly ?string $pattern,
    ) {
    }

    /**
     * @param string $mode One of the `MODE_*` constants.
     * @param array{multiline?: bool, dotAll?: bool} $flags Regex-only modifiers.
     * @throws InvalidArgumentException if the mode is unknown, the needle is empty, or the pattern
     *                                  doesn't compile.
     */
    public static function compile(
        string $mode,
        string $find,
        string $replace = '',
        bool $caseSensitive = true,
        bool $preserveCase = false,
        array $flags = [],
    ): self {
        if ($find === '') {
            throw new InvalidArgumentException('There is nothing to find.');
        }

        if (!in_array($mode, [self::MODE_PLAIN, self::MODE_WORD, self::MODE_REGEX], true)) {
            throw new InvalidArgumentException(sprintf('“%s” is not a match mode.', $mode));
        }

        $pattern = match ($mode) {
            // The literal path needs no pattern at all — see `literalHits()`.
            self::MODE_PLAIN => $caseSensitive && !$preserveCase ? null : self::literalPattern($find, $caseSensitive),
            self::MODE_WORD => self::wordPattern($find, $caseSensitive),
            self::MODE_REGEX => self::regexPattern($find, $caseSensitive, $flags),
        };

        if ($pattern !== null) {
            self::assertCompiles($pattern);
        }

        return new self($mode, $find, $replace, $preserveCase, $pattern);
    }

    /**
     * Compiles a user-supplied regular expression far enough to report whether it is usable, without
     * throwing. Used by the rule form, which wants to say "that pattern is broken" while you type
     * rather than when you press the button.
     *
     * @return string|null The PCRE error, or null if the pattern is fine.
     */
    public static function explain(string $mode, string $find, array $flags = []): ?string
    {
        try {
            self::compile($mode, $find, '', true, false, $flags);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /**
     * Whether the subject contains at least one match. Separate from {@see hits()} because the
     * scanner asks this question about far more strings than it ever needs excerpts for.
     */
    public function matches(string $subject): bool
    {
        if ($subject === '') {
            return false;
        }

        if ($this->pattern === null) {
            return str_contains($subject, $this->find);
        }

        $result = preg_match($this->pattern, $subject);
        self::assertRan($result === false);

        return $result === 1;
    }

    /**
     * Replaces every match in the subject.
     *
     * @param int|null $count Set to the number of replacements made.
     */
    public function replace(string $subject, ?int &$count = null): string
    {
        $count = 0;

        if ($subject === '') {
            return $subject;
        }

        if ($this->pattern === null) {
            $out = str_replace($this->find, $this->replace, $subject, $count);
            return $out;
        }

        $local = 0;
        $out = preg_replace_callback($this->pattern, function(array $m) use (&$local): string {
            $local++;
            return $this->replacementFor($m);
        }, $subject);

        self::assertRan($out === null);
        $count = $local;

        return $out;
    }

    /**
     * Every match in the subject, with enough surrounding text to recognise it.
     *
     * @param int $limit Stop after this many. The preview shows a sample, not a transcript.
     * @return Hit[]
     */
    public function hits(string $subject, int $limit = 25, int $context = self::CONTEXT_CHARS): array
    {
        if ($subject === '' || $limit <= 0) {
            return [];
        }

        if ($this->pattern === null) {
            return $this->literalHits($subject, $limit, $context);
        }

        $found = preg_match_all($this->pattern, $subject, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        self::assertRan($found === false);

        $hits = [];

        foreach ($matches as $set) {
            if (count($hits) >= $limit) {
                break;
            }

            [$matched, $offset] = $set[0];

            // `replacementFor()` wants the plain match array, not the offset-capture form. Every
            // element is a `[text, offset]` pair here, including the unmatched groups.
            $plain = array_map(static fn(array $pair) => $pair[0], $set);

            $hits[] = new Hit(
                matched: $matched,
                replacement: $this->replacementFor($plain),
                offset: $offset,
                before: self::excerpt($subject, max(0, $offset - $context), min($context, $offset)),
                after: self::excerpt($subject, $offset + strlen($matched), $context),
            );
        }

        return $hits;
    }

    /**
     * How many matches are in the subject. Cheaper than {@see hits()} when only the number is wanted.
     */
    public function count(string $subject): int
    {
        if ($subject === '') {
            return 0;
        }

        if ($this->pattern === null) {
            return substr_count($subject, $this->find);
        }

        $count = preg_match_all($this->pattern, $subject);
        self::assertRan($count === false);

        return (int)$count;
    }

    // -----------------------------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------------------------

    /**
     * The literal, case-sensitive path. Kept out of PCRE entirely: a 400 KB CKEditor field with a
     * needle that appears 3,000 times is a backtrack limit waiting to happen, and `strpos` has none.
     *
     * @return Hit[]
     */
    private function literalHits(string $subject, int $limit, int $context): array
    {
        $hits = [];
        $length = strlen($this->find);
        $offset = 0;

        while (count($hits) < $limit && ($offset = strpos($subject, $this->find, $offset)) !== false) {
            $hits[] = new Hit(
                matched: $this->find,
                replacement: $this->replace,
                offset: $offset,
                before: self::excerpt($subject, max(0, $offset - $context), min($context, $offset)),
                after: self::excerpt($subject, $offset + $length, $context),
            );

            $offset += $length;
        }

        return $hits;
    }

    /**
     * Works out what one match turns into: backreferences expanded first, then case restored.
     *
     * @param string[] $m
     */
    private function replacementFor(array $m): string
    {
        $replacement = $this->mode === self::MODE_REGEX
            ? self::expand($this->replace, $m)
            : $this->replace;

        if (!$this->preserveCase) {
            return $replacement;
        }

        return self::wearTheCaseOf($m[0], $replacement);
    }

    /**
     * Expands `$1`, `${1}` and `\1` in a replacement.
     *
     * Done here rather than by handing the replacement to `preg_replace` because every other mode
     * takes its replacement literally, and a user who types `$5.00` into the replace box of a plain
     * find is not writing a backreference.
     *
     * @param string[] $m
     */
    private static function expand(string $replacement, array $m): string
    {
        return preg_replace_callback(
            '~\\\\(\d{1,2})|\$\{(\d{1,2})\}|\$(\d{1,2})~',
            static function(array $ref) use ($m): string {
                // Exactly one of the three alternatives matched; the others are empty or absent.
                foreach ([1, 2, 3] as $group) {
                    if (($ref[$group] ?? '') !== '') {
                        return $m[(int)$ref[$group]] ?? '';
                    }
                }

                return '';
            },
            $replacement,
        ) ?? $replacement;
    }

    /**
     * Gives the replacement the same shape of capitalisation as the text it is replacing, so that
     * one rule handles “widget”, “Widget” and “WIDGET” without three rules and three chances to get
     * the third one wrong.
     *
     * Done **word by word**, which is the part that isn't obvious. Treating the match as one token
     * works for “widget” and fails for everything real: “Acme Ltd” is neither all-upper, nor
     * all-lower, nor a single capitalised word, so a single-token rule gives up on it and returns
     * the replacement exactly as typed — which is how “Acme Ltd” becomes “acme inc”. Aligning the
     * words pairwise gets “Acme Inc”, “ACME INC” and “acme inc” all right from one rule.
     *
     * Only three shapes are recognised per word, because only three are unambiguous. Anything else —
     * “wIdGeT”, “McDonald” — is left as the replacement was typed, which is the honest answer rather
     * than a guess.
     */
    private static function wearTheCaseOf(string $matched, string $replacement): string
    {
        if ($replacement === '' || $matched === '') {
            return $replacement;
        }

        $shapes = array_map(
            static fn(string $word) => self::shapeOf($word),
            self::words($matched),
        );

        if ($shapes === []) {
            return $replacement;
        }

        $index = 0;

        return preg_replace_callback(
            '~\p{L}[\p{L}\p{N}\x{2019}\']*~u',
            static function(array $m) use ($shapes, &$index): string {
                // A replacement with more words than the match reuses the last shape, so
                // “ACME LTD” → “ACME INCORPORATED LIMITED” rather than losing the case halfway.
                $shape = $shapes[$index] ?? $shapes[count($shapes) - 1];
                $index++;

                return match ($shape) {
                    'upper' => mb_strtoupper($m[0], 'UTF-8'),
                    'lower' => mb_strtolower($m[0], 'UTF-8'),
                    'title' => mb_strtoupper(mb_substr($m[0], 0, 1, 'UTF-8'), 'UTF-8')
                        . mb_strtolower(mb_substr($m[0], 1, null, 'UTF-8'), 'UTF-8'),
                    default => $m[0],
                };
            },
            $replacement,
        ) ?? $replacement;
    }

    /**
     * @return string[]
     */
    private static function words(string $text): array
    {
        preg_match_all('~\p{L}[\p{L}\p{N}\x{2019}\']*~u', $text, $matches);

        return $matches[0];
    }

    /**
     * `upper`, `lower`, `title`, or `mixed` for anything that isn't one of those.
     */
    private static function shapeOf(string $word): string
    {
        $letters = preg_replace('~[^\p{L}]+~u', '', $word) ?? '';

        if ($letters === '') {
            return 'mixed';
        }

        if (mb_strtolower($letters, 'UTF-8') === $letters) {
            return 'lower';
        }

        if (mb_strtoupper($letters, 'UTF-8') === $letters) {
            // A single capital letter is “A”, which is a capitalised word far more often than it is
            // an acronym.
            return mb_strlen($letters, 'UTF-8') > 1 ? 'upper' : 'title';
        }

        $rest = mb_substr($letters, 1, null, 'UTF-8');

        if (mb_strtolower($rest, 'UTF-8') === $rest) {
            return 'title';
        }

        return 'mixed';
    }

    private static function literalPattern(string $find, bool $caseSensitive): string
    {
        $delimiter = self::delimiterFor($find);

        return $delimiter . preg_quote($find, $delimiter) . $delimiter . 'u' . ($caseSensitive ? '' : 'i');
    }

    /**
     * Lookarounds, not `\b`. `\b` is defined against `\w`, so `\bC++\b` never matches “C++” — the
     * boundary it asks for is between `+` and whatever follows, and there is no word character on
     * the inside of it to anchor against.
     *
     * `(*UCP)` makes `\w` mean "word character in Unicode" rather than "one of `[A-Za-z0-9_]`",
     * without which “Café” splits in the middle for anyone whose content isn't English.
     */
    private static function wordPattern(string $find, bool $caseSensitive): string
    {
        $delimiter = self::delimiterFor($find);
        $quoted = preg_quote($find, $delimiter);

        return $delimiter . '(*UCP)(?<!\w)' . $quoted . '(?!\w)' . $delimiter . 'u' . ($caseSensitive ? '' : 'i');
    }

    /**
     * @param array{multiline?: bool, dotAll?: bool} $flags
     */
    private static function regexPattern(string $find, bool $caseSensitive, array $flags): string
    {
        $delimiter = self::delimiterFor($find);

        $modifiers = 'u';
        $modifiers .= $caseSensitive ? '' : 'i';
        $modifiers .= !empty($flags['multiline']) ? 'm' : '';
        $modifiers .= !empty($flags['dotAll']) ? 's' : '';

        return $delimiter . $find . $delimiter . $modifiers;
    }

    /**
     * Picks a delimiter the pattern doesn't contain, so nothing has to be escaped on the user's
     * behalf — escaping their delimiter for them is how a working pattern silently starts matching
     * something else.
     */
    private static function delimiterFor(string $find): string
    {
        foreach (self::DELIMITERS as $delimiter) {
            if (!str_contains($find, $delimiter)) {
                return $delimiter;
            }
        }

        // A pattern containing all nine is possible but not plausible. Fall back to escaping.
        return '~';
    }

    private static function assertCompiles(string $pattern): void
    {
        // PCRE reports a *compilation* failure as a warning, not through `preg_last_error()` —
        // which only ever says "Internal error" here. The warning text is the part worth showing:
        // it names the construct and the offset, which is the difference between "invalid pattern"
        // and "missing closing bracket at offset 7".
        $message = null;

        $previous = set_error_handler(static function(int $_severity, string $text) use (&$message): bool {
            $message = $text;
            return true;
        });

        try {
            $ok = preg_match($pattern, '') !== false;
        } finally {
            set_error_handler($previous);
        }

        if (!$ok) {
            // "preg_match(): Compilation failed: …" — the prefix is noise to anyone reading a form.
            $message = $message !== null
                ? trim(preg_replace('~^\w+\(\):\s*~', '', $message) ?? $message)
                : null;

            throw new InvalidArgumentException($message ?? 'The pattern is not a valid regular expression.');
        }
    }

    /**
     * PCRE reports failure by returning false and setting a global. Nothing else in the plugin
     * should have to remember that, so every call site funnels through here.
     */
    private static function assertRan(bool $failed): void
    {
        if (!$failed) {
            return;
        }

        throw new RuntimeException(match (preg_last_error()) {
            PREG_BACKTRACK_LIMIT_ERROR => 'The pattern gave up backtracking on this content. Make it more specific, or raise pcre.backtrack_limit.',
            PREG_JIT_STACKLIMIT_ERROR => 'The pattern exhausted the PCRE JIT stack on this content.',
            PREG_BAD_UTF8_ERROR => 'This content is not valid UTF-8, so the pattern could not be run against it.',
            default => 'The pattern failed: ' . preg_last_error_msg(),
        });
    }

    /**
     * A byte-offset slice, cleaned up. Offsets from PCRE and `strpos` are in bytes, so a window cut
     * around one lands mid-character often enough to matter; invalid sequences are dropped rather
     * than shipped to a template that will render them as replacement characters.
     */
    private static function excerpt(string $subject, int $start, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $slice = substr($subject, $start, $length);

        return mb_convert_encoding($slice, 'UTF-8', 'UTF-8');
    }
}
