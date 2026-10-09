<?php

namespace Isapp\StatamicMcp\History;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One zip in the history folder: the item it holds, when it was made, and
 * where it lives on the history disk.
 */
class HistoryBackup extends Data
{
    public function __construct(
        public string $id,
        public ItemRef $ref,
        public CarbonImmutable $createdAt,
        public string $path,
    ) {}
}
