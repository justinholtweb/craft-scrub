<?php

namespace justinholtweb\scrub\models;

use Craft;
use craft\base\Model;
use craft\helpers\StringHelper;
use DateTime;
use InvalidArgumentException;
use justinholtweb\scrub\matching\Matcher;

/**
 * A find, a replace, and everything that decides where they apply.
 *
 * One class covers both halves of the plugin. An ad-hoc replacement from the Replace screen is a
 * Rule that is never saved; a saved rule is the same object with a name and a row in the database.
 * They behave identically because they *are* identical — the alternative is two nearly-equal code
 * paths and a slow accumulation of differences between what you previewed and what runs on save.
 */
class Rule extends Model
{
    public const CADENCE_DAILY = 'daily';
    public const CADENCE_WEEKLY = 'weekly';
    public const CADENCE_MONTHLY = 'monthly';

    public ?int $id = null;
    public ?string $uid = null;

    /** @var string|null Only saved rules have one. */
    public ?string $name = null;

    /** @var string|null Used by the console command and by `craft.scrub.rule()`. */
    public ?string $handle = null;

    public bool $enabled = true;

    public string $find = '';
    public string $replace = '';

    /** @var string One of the `Matcher::MODE_*` constants. */
    public string $mode = Matcher::MODE_PLAIN;

    public bool $caseSensitive = true;

    /**
     * @var bool Whether the replacement copies the capitalisation of whatever it replaced.
     *
     * Only useful alongside a case-insensitive search, which is the point: one rule instead of
     * three, and no chance of getting the third one wrong.
     */
    public bool $preserveCase = false;

    /** @var bool Regex only: `^` and `$` match at line breaks. */
    public bool $multiline = false;

    /** @var bool Regex only: `.` matches a line break. */
    public bool $dotAll = false;

    /** @var string[] Handles of the targets this rule runs against. */
    public array $targets = ['content'];

    public Scope $scope;

    // ---------------------------------------------------------------------------------------
    // Where else it applies (saved rules only)
    // ---------------------------------------------------------------------------------------

    /**
     * @var bool Whether the rule rewrites rendered output instead of stored content.
     *
     * The database is untouched. Nothing about this is reversible-by-undo because nothing was
     * changed — turning the rule off is the undo.
     */
    public bool $realtime = false;

    /**
     * @var string[] URI patterns the real-time rule applies to. `*` wildcards, empty means the whole
     *               site. Never the control panel.
     */
    public array $realtimeUris = [];

    /**
     * @var bool Whether real-time replacement skips markup.
     *
     * On, and it wants to stay on. Rendered HTML is full of words that are also class names, tag
     * names and URLs, and a rule that replaces "form" without this rewrites every `<form>` on the
     * site into something a browser can't parse.
     */
    public bool $realtimeHtmlAware = true;

    /**
     * @var bool Whether the rule is applied to elements as they're saved, so that new content
     *           arrives already corrected.
     */
    public bool $onSave = false;

    /** @var string|null A `CADENCE_*` value, or null for no schedule. */
    public ?string $cadence = null;

    public ?DateTime $dateLastRun = null;
    public int $sortOrder = 0;

    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;

    private ?Matcher $matcher = null;

    public function init(): void
    {
        parent::init();

        // A rule is never without a scope. Everything that reads one would otherwise need a null
        // check, and the null check that gets forgotten is the one that scans the whole site.
        if (!isset($this->scope)) {
            $this->scope = new Scope();
        }
    }

    /**
     * The compiled matcher, built once.
     *
     * @throws InvalidArgumentException if the rule doesn't compile.
     */
    public function matcher(): Matcher
    {
        return $this->matcher ??= Matcher::compile(
            $this->mode,
            $this->find,
            $this->replace,
            $this->caseSensitive,
            $this->preserveCase,
            ['multiline' => $this->multiline, 'dotAll' => $this->dotAll],
        );
    }

    /**
     * Discards a memoized matcher. Needed only where a rule object is reused across edits, which
     * the rule form does.
     */
    public function recompile(): void
    {
        $this->matcher = null;
    }

    public function isRegex(): bool
    {
        return $this->mode === Matcher::MODE_REGEX;
    }

    /**
     * Whether this rule needs Pro. Kept here rather than scattered through the controllers so that
     * the console command, the queue job and the CP all gate on the same answer.
     */
    public function needsPro(): bool
    {
        return $this->isRegex()
            || $this->preserveCase
            || $this->realtime
            || $this->onSave
            || $this->cadence !== null
            || $this->id !== null
            || array_intersect($this->targets, ['assets', 'database']) !== [];
    }

    /**
     * A sentence describing the rule, for the ledger and the queue description.
     */
    public function describe(): string
    {
        return Craft::t('scrub', 'Replacing “{find}” with “{replace}”', [
            'find' => StringHelper::safeTruncate($this->find, 60),
            'replace' => $this->replace === ''
                ? Craft::t('scrub', 'nothing')
                : StringHelper::safeTruncate($this->replace, 60),
        ]);
    }

    protected function defineRules(): array
    {
        return [
            [['find'], 'required'],
            [['find', 'replace', 'name', 'handle'], 'string'],
            [['mode'], 'in', 'range' => [Matcher::MODE_PLAIN, Matcher::MODE_WORD, Matcher::MODE_REGEX]],
            [['cadence'], 'in', 'range' => [self::CADENCE_DAILY, self::CADENCE_WEEKLY, self::CADENCE_MONTHLY], 'skipOnEmpty' => true],
            [['enabled', 'caseSensitive', 'preserveCase', 'multiline', 'dotAll', 'realtime', 'realtimeHtmlAware', 'onSave'], 'boolean'],
            [['targets', 'realtimeUris', 'scope'], 'safe'],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_\-]*$/', 'skipOnEmpty' => true],
            [['find'], 'validatePattern'],
            [['targets'], 'validateTargets', 'skipOnEmpty' => false],
        ];
    }

    /**
     * Reports a broken regular expression against the field the user typed it into, rather than as
     * a 500 twenty seconds into a queue job.
     */
    public function validatePattern(): void
    {
        if ($this->find === '') {
            return;
        }

        $error = Matcher::explain($this->mode, $this->find, [
            'multiline' => $this->multiline,
            'dotAll' => $this->dotAll,
        ]);

        if ($error !== null) {
            $this->addError('find', $error);
        }
    }

    public function validateTargets(): void
    {
        if (empty($this->targets)) {
            $this->addError('targets', Craft::t('scrub', 'Pick at least one thing to search.'));
        }
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'id' => $this->id,
            'uid' => $this->uid,
            'name' => $this->name,
            'handle' => $this->handle,
            'enabled' => $this->enabled,
            'find' => $this->find,
            'replace' => $this->replace,
            'mode' => $this->mode,
            'caseSensitive' => $this->caseSensitive,
            'preserveCase' => $this->preserveCase,
            'multiline' => $this->multiline,
            'dotAll' => $this->dotAll,
            'targets' => array_values($this->targets),
            'scope' => $this->scope->toArray(),
            'realtime' => $this->realtime,
            'realtimeUris' => array_values($this->realtimeUris),
            'realtimeHtmlAware' => $this->realtimeHtmlAware,
            'onSave' => $this->onSave,
            'cadence' => $this->cadence,
            'sortOrder' => $this->sortOrder,
        ];
    }

    public static function fromArray(?array $array): self
    {
        $array ??= [];
        $rule = new self();

        foreach ($array as $key => $value) {
            // `targets` and `realtimeUris` are typed `array` and are assigned properly just below,
            // so skip them here: a checkbox group with nothing ticked posts '', and assigning that
            // to an array property is a TypeError that would fire before the good assignment ran.
            if (in_array($key, ['scope', 'targets', 'realtimeUris'], true) || !$rule->canSetProperty($key)) {
                continue;
            }

            $rule->$key = $value;
        }

        $rule->scope = Scope::fromArray($array['scope'] ?? null);
        $rule->targets = array_values(array_filter((array)($array['targets'] ?? ['content']), static fn($t) => $t !== ''));
        $rule->realtimeUris = array_values(array_filter(
            (array)($array['realtimeUris'] ?? []),
            static fn($u) => trim((string)$u) !== '',
        ));

        return $rule;
    }
}
