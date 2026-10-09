<?php

namespace Isapp\StatamicMcp\History;

use Carbon\CarbonImmutable;

/**
 * A zip's name says what it holds, so the history is read from the names
 * alone: "20261008-141503-123456--entry--<id>.zip". The time is in UTC, so
 * the names sort in order also in the hour when summer time ends.
 */
class BackupNames
{
    public function for(ItemRef $ref, CarbonImmutable $at): string
    {
        return "{$at->format('Ymd-His-u')}--{$ref->kind->value}--{$ref->key}.zip";
    }

    /**
     * Null for a file in the history folder that this package did not name.
     */
    public function parse(string $path): ?HistoryBackup
    {
        if (! preg_match('#(\d{8}-\d{6}-\d{6})--([a-z]+)--(.+)\.zip$#', basename($path), $parts)) {
            return null;
        }

        $kind = Kind::tryFrom($parts[2]);

        if ($kind === null) {
            return null;
        }

        return new HistoryBackup(
            id: basename($path, '.zip'),
            ref: new ItemRef($kind, $parts[3]),
            createdAt: CarbonImmutable::createFromFormat('Ymd-His-u', $parts[1], 'UTC'),
            path: $path,
        );
    }
}
