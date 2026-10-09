<?php

namespace Isapp\StatamicMcp\History;

use Spatie\LaravelData\Data;

/**
 * The one file a history zip holds, with the absolute path it had in the app.
 */
class BackedUpFile extends Data
{
    public function __construct(
        public string $path,
        public string $contents,
    ) {}
}
