<?php

namespace Isapp\StatamicMcp\History;

use Spatie\LaravelData\Data;

/**
 * The file that a Statamic save is about to change, and the item it holds.
 */
class SavedFile extends Data
{
    public function __construct(
        public ItemRef $ref,
        public string $path,
    ) {}
}
