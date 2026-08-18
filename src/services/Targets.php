<?php

namespace justinholtweb\scrub\services;

use craft\base\Component;
use justinholtweb\scrub\events\RegisterTargetsEvent;
use justinholtweb\scrub\Plugin;
use justinholtweb\scrub\targets\AssetTarget;
use justinholtweb\scrub\targets\ContentTarget;
use justinholtweb\scrub\targets\DatabaseTarget;
use justinholtweb\scrub\targets\SlugTarget;
use justinholtweb\scrub\targets\TargetInterface;

/**
 * The register of places Scrub knows how to look.
 */
class Targets extends Component
{
    /**
     * @event RegisterTargetsEvent Raised when the list is built.
     *
     * ```php
     * Event::on(Targets::class, Targets::EVENT_REGISTER_TARGETS, function(RegisterTargetsEvent $e) {
     *     $e->targets['knowledge-base'] = new MyKnowledgeBaseTarget();
     * });
     * ```
     */
    public const EVENT_REGISTER_TARGETS = 'registerTargets';

    /** @var TargetInterface[]|null */
    private ?array $targets = null;

    /**
     * Every registered target, supported or not.
     *
     * @return TargetInterface[] Keyed by handle.
     */
    public function all(): array
    {
        if ($this->targets !== null) {
            return $this->targets;
        }

        $event = new RegisterTargetsEvent([
            'targets' => [
                ContentTarget::handle() => new ContentTarget(),
                SlugTarget::handle() => new SlugTarget(),
                AssetTarget::handle() => new AssetTarget(),
                DatabaseTarget::handle() => new DatabaseTarget(),
            ],
        ]);

        $this->trigger(self::EVENT_REGISTER_TARGETS, $event);

        return $this->targets = $event->targets;
    }

    /**
     * The targets that can actually be used here — supported by this install, and covered by this
     * edition.
     *
     * @return TargetInterface[]
     */
    public function available(): array
    {
        $isPro = Plugin::getInstance()->isPro();

        return array_filter(
            $this->all(),
            static fn(TargetInterface $target) => $target->isSupported()
                && ($isPro || $target->edition() === Plugin::EDITION_LITE),
        );
    }

    public function get(string $handle): ?TargetInterface
    {
        return $this->all()[$handle] ?? null;
    }

    /**
     * Discards the memoized list. Only the tests and the settings screen need this.
     */
    public function reset(): void
    {
        $this->targets = null;
    }
}
