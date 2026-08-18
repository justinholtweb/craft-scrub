<?php

namespace justinholtweb\scrub\services;

use Craft;
use craft\base\Component;
use craft\events\TemplateEvent;
use craft\web\View;
use justinholtweb\scrub\models\Rule;
use justinholtweb\scrub\Plugin;
use Throwable;
use yii\base\Event;

/**
 * Rules that rewrite what the site renders, and leave the database alone.
 *
 * This is the half of the plugin that has no undo, because it has nothing to undo. The content is
 * untouched; the replacement happens on the way out. Switching the rule off is the reversal, and it
 * is instant.
 *
 * That sounds like a lesser version of a real replacement, and for a permanent rename it is. But a
 * great many replacements aren't permanent renames. A brand changes name in six weeks and the
 * content team hasn't finished. Legal wants a phrase gone from the public site today, while the
 * wording is still being argued about. A product is called one thing internally and another thing
 * publicly, and always will be. Rewriting a hundred thousand rows to solve any of those is how you
 * end up needing the undo in the first place.
 *
 * **It skips markup by default**, and this matters more than it sounds. Rendered HTML is full of
 * words that are also tag names, class names, attribute values and URLs. A naive `str_replace` of
 * "form" across a page rewrites every `<form>` element on it into something a browser can't parse,
 * and the person who wrote the rule sees a blank page rather than an error.
 */
class Realtime extends Component
{
    /** Cache key for the compiled list, cleared whenever a rule is saved. */
    public const CACHE_KEY = 'scrub:realtime-rules';

    /** @var Rule[]|null */
    private ?array $rules = null;

    /**
     * Attaches the render hook — but only if there is something for it to do.
     *
     * Called on every request, including every front-end request on a site that has never opened
     * Scrub, so the early exits matter. The expensive question ("which rules are there?") is behind
     * a cached flag, and the listener is never attached at all when the answer is none.
     */
    public function register(): void
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->isPro() || !$plugin->getSettings()->realtimeEnabled) {
            return;
        }

        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest() || $request->getIsCpRequest()) {
            return;
        }

        if ($this->activeRules() === []) {
            return;
        }

        Event::on(
            View::class,
            View::EVENT_AFTER_RENDER_PAGE_TEMPLATE,
            function(TemplateEvent $event) {
                // The *page* template, not every template: a partial rendered inside it would
                // otherwise be rewritten once on its own and again as part of the page.
                $event->output = $this->apply($event->output);
            }
        );
    }

    /**
     * The enabled real-time rules, cached.
     *
     * Not called `rules()`: this class extends `craft\base\Component`, and `yii\base\Model::rules()`
     * is the validation-rule hook. Overriding it with something that returns Scrub rules would break
     * validation on the component silently, and the two meanings of the word would never stop
     * causing trouble.
     */
    public function activeRules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        $cache = Craft::$app->getCache();
        $cached = $cache->get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $this->rules = array_map(
                static fn(array $rule) => Rule::fromArray($rule),
                $cached,
            );
        }

        $rules = Plugin::getInstance()->rules->realtime();

        $cache->set(self::CACHE_KEY, array_map(static fn(Rule $rule) => $rule->toArray(), $rules));

        return $this->rules = $rules;
    }

    /**
     * Drops the cached list. Called whenever a rule is saved, deleted or reordered.
     */
    public function invalidate(): void
    {
        $this->rules = null;
        Craft::$app->getCache()->delete(self::CACHE_KEY);
    }

    /**
     * Runs every applicable rule over a string.
     *
     * @param string|null $uri The request URI to match rules against. Defaults to the current one.
     */
    public function apply(string $output, ?string $uri = null): string
    {
        if ($output === '') {
            return $output;
        }

        $uri ??= Craft::$app->getRequest()->getIsConsoleRequest()
            ? ''
            : (string)Craft::$app->getRequest()->getPathInfo();

        foreach ($this->activeRules() as $rule) {
            if (!$this->appliesTo($rule, $uri)) {
                continue;
            }

            try {
                $matcher = $rule->matcher();

                $output = $rule->realtimeHtmlAware
                    ? $this->replaceInTextNodes($output, $matcher)
                    : $matcher->replace($output);
            } catch (Throwable $e) {
                // A broken rule must not take the page down. It's logged once per request and the
                // page renders as though the rule weren't there.
                Craft::warning(sprintf('Real-time rule “%s” failed: %s', $rule->name, $e->getMessage()), Plugin::LOG_CATEGORY);
            }
        }

        return $output;
    }

    /**
     * Whether a rule applies to this URI.
     */
    public function appliesTo(Rule $rule, string $uri): bool
    {
        if ($rule->realtimeUris === []) {
            return true;
        }

        $uri = ltrim($uri, '/');

        foreach ($rule->realtimeUris as $pattern) {
            $pattern = ltrim(trim((string)$pattern), '/');

            if ($pattern === '') {
                continue;
            }

            // `*` is the only wildcard, because it's the only one anybody guesses correctly. The
            // rest of the pattern is literal, so a URI containing a full stop or a bracket doesn't
            // quietly become a character class.
            $regex = '~^' . str_replace('\*', '.*', preg_quote($pattern, '~')) . '$~i';

            if (preg_match($regex, $uri) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replaces only in the parts of the HTML a reader actually sees.
     *
     * The document is split on tags, comments, and the contents of `<script>` and `<style>`; the
     * captured delimiters are passed through untouched and only the text between them is rewritten.
     * A regular expression is the right tool here despite the folklore: this isn't parsing HTML, it
     * is *finding the boundaries between markup and text* in a document already produced by Twig,
     * and doing it without building a DOM of a page that may be a megabyte long.
     */
    public function replaceInTextNodes(string $html, \justinholtweb\scrub\matching\Matcher $matcher): string
    {
        $parts = preg_split(
            '~(<script\b[^>]*>.*?</script>|<style\b[^>]*>.*?</style>|<!--.*?-->|<[^>]*>)~is',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        );

        if ($parts === false) {
            // The split failed — a backtrack limit on an enormous page. Leaving the output alone is
            // the only safe answer; a half-applied replacement is worse than none.
            return $html;
        }

        $out = '';

        foreach ($parts as $index => $part) {
            // Captured delimiters land on the odd indices. Those are the markup.
            $out .= $index % 2 === 1 ? $part : $matcher->replace($part);
        }

        return $out;
    }
}
