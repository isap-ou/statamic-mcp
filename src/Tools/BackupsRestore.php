<?php

namespace Isapp\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\AuthorizesEntries;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesEntries;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Danielgnh\StatamicMcp\Tools\Tool;
use Danielgnh\StatamicMcp\Tools\ToolException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Isapp\StatamicMcp\History\BackedUpFile;
use Isapp\StatamicMcp\History\Backups;
use Isapp\StatamicMcp\History\HistoryBackup;
use Isapp\StatamicMcp\History\HistoryLines;
use Isapp\StatamicMcp\History\Kind;
use Isapp\StatamicMcp\History\Restorer;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

#[Name('backups_restore')]
#[Description('Bring one item back to the version in a backup from backups_history: an entry (also a deleted one), a global set, a navigation or a collection tree. Without confirm this is a dry run: it shows the item, its title in the backup, and whether that differs from now. With confirm: true it saves that version. The site first backs up the current version, so the restore can be undone the same way.')]
class BackupsRestore extends Tool
{
    use AuthorizesEntries;
    use ResolvesEntries;
    use ResolvesSites;

    public function __construct(
        private Backups $backups,
        private HistoryLines $lines,
        private Restorer $restorer,
    ) {}

    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'backup' => $schema->string()->description('A backup id from backups_history.')->required(),
            'confirm' => $schema->boolean()->description('true saves the version. Omitted or false is a dry run.'),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return $this->writesEnabled();
    }

    protected function execute(Request $request): Response
    {
        $this->ensureWritesEnabled();

        $validated = $request->validate([
            'backup' => ['required', 'string'],
            'confirm' => ['nullable', 'boolean'],
        ]);

        $backup = $this->backups->find($validated['backup'])
            ?? throw new ToolException("backup '{$validated['backup']}' not found — backups_history lists the ids");

        $file = $this->backups->read($backup)
            ?? throw new ToolException("backup '{$backup->id}' could not be read");

        $this->ensureMayRestore($this->user($request), $backup, $file);

        if (! ($validated['confirm'] ?? false)) {
            return $this->json(['mode' => 'dry run', ...$this->lines->for($backup)->toArray()]);
        }

        $this->restorer->restore($backup, $file);

        return $this->json(['mode' => 'restored', ...$this->lines->for($backup)->toArray()]);
    }

    /**
     * The same rights the base addon's own write tools ask for, checked on the
     * item that the file holds.
     */
    private function ensureMayRestore(UserContract $user, HistoryBackup $backup, BackedUpFile $file): void
    {
        $ref = $backup->ref;

        match ($ref->kind) {
            Kind::Entry => $this->ensureMayRestoreEntry($user, $this->restorer->entry($backup, $file)),
            Kind::Global => $this->ensureMayWrite($user, 'globals', $ref->handle(), "edit {$ref->handle()} globals", $ref->site()),
            Kind::Nav => $this->ensureMayWrite($user, 'navigations', $ref->handle(), "edit {$ref->handle()} nav", $ref->site()),
            Kind::Tree => $this->ensureMayWrite($user, 'collections', $ref->handle(), "reorder {$ref->handle()} entries", $ref->site()),
        };
    }

    /**
     * A restore can change the author and the published state, so it asks
     * for the rights that entries_update and entries_publish ask for. With
     * revisions, entries_update stages a working copy; a restore would write
     * the live file instead, so it is refused.
     */
    private function ensureMayRestoreEntry(UserContract $user, EntryContract $restored): void
    {
        if (Entry::find($restored->id()) === null) {
            $this->ensureMayRestoreDeletedEntry($user, $restored);

            return;
        }

        $live = $this->findExposedEntry($restored->id(), $user);

        $this->ensureEntryPermission($user, 'edit', $live);
        $this->ensureAuthorUnchanged($user, $live, ['author' => $restored->authors()->all()]);

        if ($live->revisionsEnabled()) {
            throw new ToolException("entry '{$live->id()}' is in a collection with revisions — bring the version back from its revision history in the Control Panel");
        }

        if ($restored->published() !== $live->published()) {
            $this->ensureEntryPermission($user, 'publish', $live);
        }
    }

    /**
     * A deleted entry comes back as a new one, so it asks for the rights of
     * entries_create: create, and "edit other authors" when the entry names
     * anyone but the user as its author. It also asks for publish when the
     * entry comes back published.
     */
    private function ensureMayRestoreDeletedEntry(UserContract $user, EntryContract $restored): void
    {
        $collection = $restored->collectionHandle();

        $this->ensureExposed('collections', $collection);
        $this->ensurePermission($user, "create {$collection} entries");

        if ($restored->blueprint()->hasField('author') && ! $this->sameAuthors($restored->authors()->all(), [$user->id()])) {
            $this->ensurePermission($user, "edit other authors {$collection} entries");
        }

        $this->ensureSiteAccess($user, $restored->locale());

        if ($restored->published()) {
            $this->ensureEntryPermission($user, 'publish', $restored);
        }
    }

    /**
     * @param  'collections'|'globals'|'navigations'  $type
     */
    private function ensureMayWrite(UserContract $user, string $type, string $handle, string $permission, string $site): void
    {
        $this->ensureExposed($type, $handle);
        $this->ensurePermission($user, $permission);
        $this->ensureSiteAccess($user, $site);
    }
}
