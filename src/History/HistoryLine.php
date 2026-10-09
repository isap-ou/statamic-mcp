<?php

namespace Isapp\StatamicMcp\History;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One line of the history the AI reads: which backup, when, of which item,
 * the title the item had then, and whether that version differs from now.
 */
#[MapName(SnakeCaseMapper::class)]
class HistoryLine extends Data
{
    public function __construct(
        public string $backup,
        public CarbonImmutable $createdAt,
        public Kind $kind,
        public string $key,
        public ?string $title = null,
        public ?bool $differsFromNow = null,
    ) {}
}
