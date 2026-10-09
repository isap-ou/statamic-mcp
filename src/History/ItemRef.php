<?php

namespace Isapp\StatamicMcp\History;

use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * Which item a backup belongs to. The key is the entry id, or for a global
 * set, a navigation or a collection tree its handle and site, as "main.en".
 */
class ItemRef extends Data
{
    public function __construct(
        public Kind $kind,
        public string $key,
    ) {}

    public function is(ItemRef $other): bool
    {
        return $this->kind === $other->kind && $this->key === $other->key;
    }

    /**
     * The handle of a global set, a navigation or a collection tree.
     */
    public function handle(): string
    {
        return Str::beforeLast($this->key, '.');
    }

    public function site(): string
    {
        return Str::afterLast($this->key, '.');
    }
}
