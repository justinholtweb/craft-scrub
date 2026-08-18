<?php

namespace justinholtweb\scrub\matching;

/**
 * Runs a matcher over every string inside a field value, however deeply it is buried.
 *
 * Craft field values serialize to wildly different shapes — a plain text field is a string, a table
 * field is a list of rows of cells, a link field is a hash, an entries field is a list of integers.
 * Rather than teach Scrub about each field type (and be wrong about the next one somebody installs),
 * it walks the serialized value and treats every string it finds as text.
 *
 * That is right far more often than it looks. Relation fields serialize to IDs, so they are skipped
 * for free. Dropdowns serialize to their stored values, which is what somebody renaming an option
 * actually wants changed. And any field type that stores prose anywhere ends up covered without
 * anybody having written a line of code for it.
 */
final class TextWalker
{
    /**
     * @param Hit[] $hits Appended to, in place. Bounded by `$hitLimit`.
     * @param int $count Incremented by the number of replacements made.
     * @return mixed The value with every match replaced.
     */
    public static function replace(
        mixed $value,
        Matcher $matcher,
        array &$hits,
        int &$count,
        int $hitLimit = 25,
    ): mixed {
        if (is_string($value)) {
            if (!$matcher->matches($value)) {
                return $value;
            }

            if (count($hits) < $hitLimit) {
                foreach ($matcher->hits($value, $hitLimit - count($hits)) as $hit) {
                    $hits[] = $hit;
                }
            }

            $replaced = $matcher->replace($value, $made);
            $count += $made;

            return $replaced;
        }

        if (is_array($value)) {
            foreach (array_keys($value) as $key) {
                // Keys are walked by name rather than by reference: assigning into an array while
                // foreach holds it copies the whole structure on every iteration.
                $value[$key] = self::replace($value[$key], $matcher, $hits, $count, $hitLimit);
            }

            return $value;
        }

        // Integers, floats, booleans, nulls and objects are left exactly as they were. An object
        // here means a field whose serialized form isn't serializable, which is not text.
        return $value;
    }

    /**
     * Whether there is anything to replace, without building the replacement.
     */
    public static function matches(mixed $value, Matcher $matcher): bool
    {
        if (is_string($value)) {
            return $matcher->matches($value);
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::matches($item, $matcher)) {
                    return true;
                }
            }
        }

        return false;
    }
}
