<?php

namespace Isapp\StatamicMcp\History;

use Statamic\Events\CollectionTreeSaving;
use Statamic\Events\EntryDeleting;
use Statamic\Events\EntrySaving;
use Statamic\Events\GlobalVariablesSaving;
use Statamic\Events\NavTreeSaving;

/**
 * Reads the item and its file from each Statamic event that comes before a
 * write. The file still holds the old content when the event fires.
 */
class SavedFiles
{
    /**
     * Null for an entry that Statamic has not given an id yet: a new entry has
     * no old file to back up.
     */
    public function forEvent(EntrySaving|EntryDeleting|GlobalVariablesSaving|NavTreeSaving|CollectionTreeSaving $event): ?SavedFile
    {
        if (($event instanceof EntrySaving || $event instanceof EntryDeleting) && $event->entry->id() === null) {
            return null;
        }

        return match (true) {
            $event instanceof EntrySaving, $event instanceof EntryDeleting => new SavedFile(
                new ItemRef(Kind::Entry, $event->entry->id()),
                $event->entry->path(),
            ),
            $event instanceof GlobalVariablesSaving => new SavedFile(
                new ItemRef(Kind::Global, "{$event->variables->handle()}.{$event->variables->locale()}"),
                $event->variables->path(),
            ),
            $event instanceof NavTreeSaving => new SavedFile(
                new ItemRef(Kind::Nav, "{$event->tree->handle()}.{$event->tree->locale()}"),
                $event->tree->path(),
            ),
            $event instanceof CollectionTreeSaving => new SavedFile(
                new ItemRef(Kind::Tree, "{$event->tree->handle()}.{$event->tree->locale()}"),
                $event->tree->path(),
            ),
        };
    }
}
