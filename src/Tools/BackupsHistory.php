<?php

namespace Isapp\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Danielgnh\StatamicMcp\Tools\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Isapp\StatamicMcp\History\Backups;
use Isapp\StatamicMcp\History\HistoryBackup;
use Isapp\StatamicMcp\History\HistoryLines;
use Isapp\StatamicMcp\History\ItemRef;
use Isapp\StatamicMcp\History\Kind;
use Isapp\StatamicMcp\History\Restorer;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

#[Name('backups_history')]
#[Description('The backup history of the content. Before an MCP write changes an entry, a global set, a navigation or a collection tree, the site stores a copy of the one file that the write changes. Terms and assets get no copy. This lists those copies that you may read, newest first: the backup id, the time, the kind (entry, global, nav, tree) and key of the item, the title the item had then, and whether that version differs from now. Filter by one entry id, one global set or navigation handle (with site), or a start time. Pass a backup id to backups_restore to bring that version back.')]
#[IsReadOnly]
class BackupsHistory extends Tool
{
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
            'entry' => $schema->string()->description('An entry id: only the versions of this entry. Works for a deleted entry too.'),
            'global' => $schema->string()->description('A global set handle: only the versions of this set.'),
            'navigation' => $schema->string()->description('A navigation handle: only the versions of this navigation.'),
            'site' => $schema->string()->description('The site, with global or navigation. Defaults to the default site.'),
            'since' => $schema->string()->description('Only backups made at or after this time, e.g. 2026-10-08T14:00:00+02:00.'),
            'limit' => $schema->integer()->description('How many backups to return. Default 20, at most 100.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate([
            'entry' => ['nullable', 'string'],
            'global' => ['nullable', 'string'],
            'navigation' => ['nullable', 'string'],
            'site' => ['nullable', 'string'],
            'since' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $this->user($request);

        $ref = $this->itemRef($validated);
        $since = isset($validated['since']) ? Carbon::parse($validated['since']) : null;

        $lines = ($ref === null ? $this->backups->all() : $this->backups->of($ref))
            ->lazy()
            ->filter(fn (HistoryBackup $backup) => $since === null || $backup->createdAt->gte($since))
            ->filter(fn (HistoryBackup $backup) => $this->mayView($user, $backup))
            ->take($validated['limit'] ?? 20)
            ->map(fn (HistoryBackup $backup) => $this->lines->for($backup)->toArray())
            ->values()
            ->all();

        return $this->json(['backups' => $lines]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function itemRef(array $filters): ?ItemRef
    {
        $site = $filters['site'] ?? Site::default()->handle();

        return match (true) {
            isset($filters['entry']) => new ItemRef(Kind::Entry, $filters['entry']),
            isset($filters['global']) => new ItemRef(Kind::Global, "{$filters['global']}.{$site}"),
            isset($filters['navigation']) => new ItemRef(Kind::Nav, "{$filters['navigation']}.{$site}"),
            default => null,
        };
    }

    /**
     * The rights of the base addon's read tools: an item that the user could
     * not read through MCP does not show its titles here either.
     */
    private function mayView(UserContract $user, HistoryBackup $backup): bool
    {
        $ref = $backup->ref;

        return match ($ref->kind) {
            Kind::Entry => $this->mayViewEntry($user, $backup),
            Kind::Global => $this->mayRead($user, 'globals', $ref->handle(), "edit {$ref->handle()} globals", $ref->site()),
            Kind::Nav => $this->mayRead($user, 'navigations', $ref->handle(), "view {$ref->handle()} nav", $ref->site()),
            Kind::Tree => $this->mayRead($user, 'collections', $ref->handle(), "view {$ref->handle()} entries", $ref->site()),
        };
    }

    /**
     * A deleted entry is read from its backup to learn its collection and
     * site. A backup that cannot be read that way is not shown.
     */
    private function mayViewEntry(UserContract $user, HistoryBackup $backup): bool
    {
        $entry = Entry::find($backup->ref->key) ?? $this->deletedEntry($backup);

        if ($entry === null) {
            return false;
        }

        return $this->mayRead($user, 'collections', $entry->collectionHandle(), "view {$entry->collectionHandle()} entries", $entry->locale());
    }

    private function deletedEntry(HistoryBackup $backup): ?EntryContract
    {
        $file = $this->backups->read($backup);

        if ($file === null) {
            return null;
        }

        return rescue(fn () => $this->restorer->entry($backup, $file), report: false);
    }

    /**
     * @param  'collections'|'globals'|'navigations'  $type
     */
    private function mayRead(UserContract $user, string $type, string $handle, string $permission, string $site): bool
    {
        return in_array($handle, $this->exposedHandles($type), true)
            && $this->can($user, $permission)
            && $this->canAccessSite($user, $site);
    }
}
