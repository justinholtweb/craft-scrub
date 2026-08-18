<?php

namespace justinholtweb\scrub\matching;

/**
 * Pulls the longest string that a regular expression *must* contain out of the pattern.
 *
 * This exists for one reason: SQL. Plain and word searches can hand their needle straight to a
 * `LIKE`, which turns "look at every element on the site" into "look at the forty rows that could
 * possibly match". A regular expression can't do that, so without help a regex scan reads and
 * decodes every row in scope.
 *
 * Most real patterns still contain a literal run — `href="/old-docs/[\w-]+"`, `\bACME (Inc|Ltd)\b` —
 * and if a subject must match the pattern it must also contain that run. That makes the run a legal
 * prefilter: it can only ever return a superset, never miss a row.
 *
 * The whole thing is built to give up. Anything it isn't certain about returns null, and null just
 * means "scan everything", which is correct, only slower.
 */
final class Literal
{
    /** Below this a prefilter selects most of the table anyway, so it isn't worth the risk. */
    private const MIN_LENGTH = 3;

    /**
     * @return string|null The longest guaranteed literal substring, or null if there isn't a usable
     *                     one — including whenever the pattern is too clever to be sure about.
     */
    public static function requiredSubstring(string $pattern): ?string
    {
        // Alternation anywhere means no single substring is required by every branch. Detecting
        // which alternations are harmless is exactly the kind of cleverness that turns a prefilter
        // into a bug, so: no.
        if (str_contains($pattern, '|')) {
            return null;
        }

        $runs = [];
        $run = '';
        $length = strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];

            switch ($char) {
                case '\\':
                    $next = $pattern[$i + 1] ?? '';
                    $i++;

                    // `\.` is a full stop; `\d` is a class. Only punctuation survives as a literal.
                    if ($next !== '' && !ctype_alnum($next)) {
                        $run .= $next;
                    } else {
                        $runs[] = $run;
                        $run = '';
                    }
                    break;

                case '[':
                    // A character class matches one of several things, so nothing in it is required.
                    $i = self::skipClass($pattern, $i);
                    $runs[] = $run;
                    $run = '';
                    break;

                case '?':
                case '*':
                    // The *previous* character was optional after all, so it can't be required.
                    $run = substr($run, 0, -1);
                    $runs[] = $run;
                    $run = '';
                    break;

                case '{':
                    // `{0,3}` makes the previous character optional; `{2,}` does not. Rather than
                    // parse the range, drop the character whenever the minimum could be zero.
                    $close = strpos($pattern, '}', $i);

                    if ($close !== false && str_starts_with(substr($pattern, $i + 1, $close - $i - 1), '0')) {
                        $run = substr($run, 0, -1);
                    }

                    $i = $close !== false ? $close : $length;
                    $runs[] = $run;
                    $run = '';
                    break;

                case '+':
                    // The previous character is still required — but a following `?` would make the
                    // whole thing lazy, not optional, so the run simply ends here.
                    $runs[] = $run;
                    $run = '';
                    break;

                case '(':
                case ')':
                case '^':
                case '$':
                case '.':
                    $runs[] = $run;
                    $run = '';
                    break;

                default:
                    $run .= $char;
            }
        }

        $runs[] = $run;

        usort($runs, static fn(string $a, string $b) => strlen($b) <=> strlen($a));
        $longest = $runs[0] ?? '';

        return strlen($longest) >= self::MIN_LENGTH ? $longest : null;
    }

    /**
     * Returns the index of the `]` that closes a character class opened at `$start`.
     *
     * A `]` immediately after the opening bracket (or after a negating `^`) is a literal `]`, not
     * the close — `[]]` is a class matching one square bracket.
     */
    private static function skipClass(string $pattern, int $start): int
    {
        $i = $start + 1;
        $length = strlen($pattern);

        if (($pattern[$i] ?? '') === '^') {
            $i++;
        }

        if (($pattern[$i] ?? '') === ']') {
            $i++;
        }

        for (; $i < $length; $i++) {
            if ($pattern[$i] === '\\') {
                $i++;
                continue;
            }

            if ($pattern[$i] === ']') {
                return $i;
            }
        }

        return $length;
    }
}
