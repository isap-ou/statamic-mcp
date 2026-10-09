<?php

namespace Isapp\StatamicMcp\History;

use Statamic\Facades\YAML;

/**
 * Turns a backup into the line the AI reads: the title the item had then, and
 * whether that version differs from the file now.
 */
class HistoryLines
{
    public function __construct(private Backups $backups) {}

    public function for(HistoryBackup $backup): HistoryLine
    {
        $file = $this->backups->read($backup);

        return new HistoryLine(
            backup: $backup->id,
            createdAt: $backup->createdAt,
            kind: $backup->ref->kind,
            key: $backup->ref->key,
            title: $file === null ? null : $this->title($file),
            differsFromNow: $file === null ? null : $this->differsFromNow($file),
        );
    }

    private function title(BackedUpFile $file): ?string
    {
        $data = rescue(fn () => YAML::file($file->path)->parse($file->contents), [], report: false);

        return is_string($data['title'] ?? null) ? $data['title'] : null;
    }

    private function differsFromNow(BackedUpFile $file): bool
    {
        return ! is_file($file->path) || hash_file('sha256', $file->path) !== hash('sha256', $file->contents);
    }
}
