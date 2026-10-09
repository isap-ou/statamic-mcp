<?php

namespace Isapp\StatamicMcp\History;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\Stache;
use ZipArchive;

/**
 * The history zips on the history disk, read through their names. A zip is
 * opened only to take its one file out.
 */
class Backups
{
    public function __construct(
        private BackupConfig $config,
        private BackupNames $names,
    ) {}

    /**
     * @return Collection<int, HistoryBackup> newest first
     */
    public function all(): Collection
    {
        return collect($this->disk()->files($this->config->folder()))
            ->map(fn (string $path) => $this->names->parse($path))
            ->filter()
            ->sortByDesc(fn (HistoryBackup $backup) => $backup->id)
            ->values();
    }

    /**
     * Only an id from the listing matches, so an id from a client never
     * reaches the disk as a path.
     */
    public function find(string $id): ?HistoryBackup
    {
        return $this->all()->first(fn (HistoryBackup $backup) => $backup->id === $id);
    }

    /**
     * @return Collection<int, HistoryBackup> newest first
     */
    public function of(ItemRef $ref): Collection
    {
        return $this->all()->filter(fn (HistoryBackup $backup) => $backup->ref->is($ref))->values();
    }

    /**
     * The zip is copied to a temporary file first, so this works on any disk,
     * also one on another server. Null for a zip that does not open, and for
     * one whose file name could point outside the folder of its store.
     */
    public function read(HistoryBackup $backup): ?BackedUpFile
    {
        $local = tempnam(sys_get_temp_dir(), 'mcp-history');
        file_put_contents($local, $this->disk()->get($backup->path));

        $zip = new ZipArchive;
        $opened = $zip->open($local) === true;

        try {
            if (! $opened || $zip->numFiles < 1) {
                return null;
            }

            $password = $this->config->make()->backup->password;

            if ($password !== null) {
                $zip->setPassword($password);
            }

            $name = $zip->getNameIndex(0);
            $contents = $zip->getFromIndex(0);

            if ($name === false || $contents === false || ! $this->staysInItsStore($name)) {
                return null;
            }

            return new BackedUpFile($this->absolute($backup->ref->kind, $name), $contents);
        } finally {
            if ($opened) {
                $zip->close();
            }

            unlink($local);
        }
    }

    /**
     * The hook names each file by its path from the folder of its store. A
     * name that is absolute, or that holds "..", a backslash or a NUL byte,
     * did not come from the hook.
     */
    private function staysInItsStore(string $name): bool
    {
        return ! str_starts_with($name, '/')
            && ! str_contains($name, '..')
            && ! str_contains($name, '\\')
            && ! str_contains($name, "\0");
    }

    /**
     * The hook stores each file by its path from the folder of its Stache
     * store, so the store's folder today makes it absolute again.
     */
    private function absolute(Kind $kind, string $name): string
    {
        return Stache::store($kind->storeKey())->directory().ltrim($name, '/');
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->config->disk());
    }
}
