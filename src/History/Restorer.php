<?php

namespace Isapp\StatamicMcp\History;

use Danielgnh\StatamicMcp\Tools\ToolException;
use Illuminate\Support\Str;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Globals\Variables;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav;
use Statamic\Facades\Stache;
use Statamic\Facades\YAML;
use Statamic\Structures\Tree;

/**
 * Saves one backed-up file back through Statamic, so caches and events act
 * as for a Control Panel save. Inside an MCP request the hook backs up the
 * current file first, so a restore can be undone too.
 *
 * A file must hold the item that its backup's name says. The tool checks the
 * rights on that item, so a file that holds another item is refused.
 */
class Restorer
{
    public function restore(HistoryBackup $backup, BackedUpFile $file): void
    {
        $ref = $backup->ref;

        $saved = match ($ref->kind) {
            Kind::Entry => $this->entry($backup, $file)->save(),
            Kind::Global => $this->variables($backup, $file)->save(),
            Kind::Nav => $this->restoreTree(Nav::findByHandle($ref->handle())?->in($ref->site()), $backup, $file),
            Kind::Tree => $this->restoreTree(Collection::findByHandle($ref->handle())?->structure()?->in($ref->site()), $backup, $file),
        };

        if ($saved === false) {
            throw new ToolException("{$backup->id} was not restored: a listener cancelled the save");
        }
    }

    /**
     * The entry of the backup, not saved yet. A live entry keeps its own file:
     * Statamic writes the backed-up version and removes the live file when
     * the slug or date differs, and the hook backs up the live file first.
     */
    public function entry(HistoryBackup $backup, BackedUpFile $file): EntryContract
    {
        if ((YAML::file($file->path)->parse($file->contents)['id'] ?? null) !== $backup->ref->key) {
            throw $this->doesNotHoldItsItem($backup);
        }

        $collection = $this->collectionOf($file->path);

        if (Collection::findByHandle($collection) === null) {
            throw new ToolException("the collection '{$collection}' of backup '{$backup->id}' no longer exists on the site");
        }

        $live = Entry::find($backup->ref->key);

        if ($live !== null && $live->collectionHandle() !== $collection) {
            throw $this->doesNotHoldItsItem($backup);
        }

        $entry = Stache::store('entries')
            ->store($collection)
            ->makeItemFromFile($file->path, $file->contents);

        if ($live === null) {
            return $entry;
        }

        if ($entry->locale() !== $live->locale()) {
            throw $this->doesNotHoldItsItem($backup);
        }

        return $entry->initialPath($live->path());
    }

    /**
     * The global set variables of the backup, not saved yet.
     */
    public function variables(HistoryBackup $backup, BackedUpFile $file): Variables
    {
        $variables = Stache::store('global-variables')->makeItemFromFile($file->path, $file->contents);

        if ("{$variables->handle()}.{$variables->locale()}" !== $backup->ref->key) {
            throw $this->doesNotHoldItsItem($backup);
        }

        return $variables;
    }

    /**
     * Entries live in one child store per collection: the first folder under
     * the entries store.
     */
    public function collectionOf(string $entryPath): string
    {
        return Str::before(Str::after($entryPath, Stache::store('entries')->directory()), '/');
    }

    /**
     * A tree made from a file has no original state, and its save() compares
     * against it. The live tree takes the backed-up branches instead. Statamic
     * leaves an empty tree out of the file, so a file without one is empty.
     */
    private function restoreTree(?Tree $tree, HistoryBackup $backup, BackedUpFile $file): bool
    {
        if ($tree === null) {
            throw new ToolException('the tree of this backup no longer exists on the site');
        }

        $branches = YAML::file($file->path)->parse($file->contents)['tree'] ?? [];

        if (! is_array($branches)) {
            throw $this->doesNotHoldItsItem($backup);
        }

        return $tree->tree($branches)->save() !== false;
    }

    private function doesNotHoldItsItem(HistoryBackup $backup): ToolException
    {
        return new ToolException("backup '{$backup->id}' does not hold the {$backup->ref->kind->value} '{$backup->ref->key}' that its name says");
    }
}
