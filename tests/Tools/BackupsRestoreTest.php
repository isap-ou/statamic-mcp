<?php

namespace Isapp\StatamicMcp\Tests\Tools;

use Danielgnh\StatamicMcp\Tools\EntriesDelete;
use Danielgnh\StatamicMcp\Tools\EntriesUpdate;
use Danielgnh\StatamicMcp\Tools\GlobalsUpdate;
use Danielgnh\StatamicMcp\Tools\NavigationsUpdate;
use Illuminate\Support\Facades\File;
use Isapp\StatamicMcp\Server;
use Isapp\StatamicMcp\Tests\TestCase;
use Isapp\StatamicMcp\Tools\BackupsRestore;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use ZipArchive;

class BackupsRestoreTest extends TestCase
{
    public function test_brings_back_the_first_of_three_versions(): void
    {
        $page = $this->aboutPage();
        foreach (['About, first edit', 'About, second edit', 'About, third edit'] as $title) {
            $this->updateTitle($page, $title);
        }
        $oldest = $this->backupIdOf($this->historyZips()[0]);

        $response = Server::actingAs($this->editor())->tool(BackupsRestore::class, ['backup' => $oldest, 'confirm' => true]);

        $response->assertOk()->assertSee('"mode":"restored"');
        $this->assertSame('About', Entry::find($page->id())->get('title'));
    }

    public function test_a_dry_run_names_the_version_and_changes_nothing(): void
    {
        $page = $this->aboutPage();
        $this->updateTitle($page, 'About us');
        $now = File::get($page->path());

        $response = Server::actingAs($this->editor())->tool(BackupsRestore::class, ['backup' => $this->backupIdOf($this->historyZips()[0])]);

        $response->assertOk()
            ->assertSee('"mode":"dry run"')
            ->assertSee('"title":"About"')
            ->assertSee('"differs_from_now":true');
        $this->assertSame($now, File::get($page->path()));
        $this->assertCount(1, $this->historyZips());
    }

    public function test_brings_back_a_deleted_page_with_its_id(): void
    {
        $page = $this->aboutPage();
        config(['statamic.mcp.deletes' => true]);
        Server::actingAs($this->editor())->tool(EntriesDelete::class, ['id' => $page->id()])->assertOk();
        $this->assertNull(Entry::find($page->id()));

        $response = Server::actingAs($this->editor())->tool(BackupsRestore::class, [
            'backup' => $this->backupIdOf($this->historyZips()[0]),
            'confirm' => true,
        ]);

        $response->assertOk();
        $this->assertSame('About', Entry::find($page->id())->get('title'));
    }

    public function test_brings_back_a_deleted_page_into_its_tree(): void
    {
        $this->englishSite();
        $this->pagesCollection();
        $this->structurePages();
        $page = $this->page('about', 'About');
        Collection::findByHandle('pages')->structure()->in('en')->save();
        $this->inAnMcpRequest();
        config(['statamic.mcp.deletes' => true]);
        Server::actingAs($this->editor())->tool(EntriesDelete::class, ['id' => $page->id()])->assertOk();
        $entryZip = collect($this->historyZips())->first(fn (string $zip) => str_contains($zip, '--entry--'));

        $response = Server::actingAs($this->editor())->tool(BackupsRestore::class, [
            'backup' => $this->backupIdOf($entryZip),
            'confirm' => true,
        ]);

        $response->assertOk();
        $this->assertSame('About', Entry::find($page->id())->get('title'));
        $this->assertNotNull(Collection::findByHandle('pages')->structure()->in('en')->findByEntry($page->id()));
    }

    public function test_brings_back_the_old_slug_and_leaves_one_file_for_the_entry(): void
    {
        $page = $this->aboutPage();
        Server::actingAs($this->editor())->tool(EntriesUpdate::class, [
            'id' => $page->id(),
            'data' => ['title' => 'About us'],
            'slug' => 'about-us',
        ])->assertOk();
        $renamed = Entry::find($page->id())->path();

        Server::actingAs($this->editor())->tool(BackupsRestore::class, [
            'backup' => $this->backupIdOf($this->historyZips()[0]),
            'confirm' => true,
        ])->assertOk();

        $this->assertSame('about', Entry::find($page->id())->slug());
        $this->assertFileExists(Entry::find($page->id())->path());
        $this->assertFileDoesNotExist($renamed);
        $this->assertSame(['pages/about-us.md'], $this->filesIn($this->historyZips()[1]));
    }

    public function test_backs_up_the_current_version_before_it_restores(): void
    {
        $page = $this->aboutPage();
        $this->updateTitle($page, 'About us');
        $current = File::get($page->path());

        Server::actingAs($this->editor())->tool(BackupsRestore::class, [
            'backup' => $this->backupIdOf($this->historyZips()[0]),
            'confirm' => true,
        ])->assertOk();

        $zips = $this->historyZips();
        $this->assertCount(2, $zips);
        $this->assertSame($current, $this->firstFileIn($zips[1]));
    }

    public function test_brings_back_a_global_set(): void
    {
        $this->englishSite();
        $this->settingsGlobal();
        $this->inAnMcpRequest();
        Server::actingAs($this->editor())->tool(GlobalsUpdate::class, [
            'handle' => 'settings',
            'data' => ['site_name' => 'Rugby'],
        ])->assertOk();

        Server::actingAs($this->editor())->tool(BackupsRestore::class, [
            'backup' => $this->backupIdOf($this->historyZips()[0]),
            'confirm' => true,
        ])->assertOk();

        $this->assertSame('Acme', GlobalSet::findByHandle('settings')->in('en')->get('site_name'));
    }

    public function test_brings_back_a_navigation(): void
    {
        $this->englishSite();
        $this->pagesCollection();
        $this->mainNav();
        $page = $this->page('about', 'About');
        $this->inAnMcpRequest();
        Server::actingAs($this->editor())->tool(NavigationsUpdate::class, [
            'handle' => 'main',
            'tree' => [['entry' => $page->id()]],
        ])->assertOk();

        Server::actingAs($this->editor())->tool(BackupsRestore::class, [
            'backup' => $this->backupIdOf($this->historyZips()[0]),
            'confirm' => true,
        ])->assertOk();

        $this->assertSame([], Nav::findByHandle('main')->in('en')->tree());
    }

    public function test_rejects_a_backup_whose_file_name_leaves_its_folder(): void
    {
        $page = $this->aboutPage();
        $outside = Stache::store('entries')->directory().'../outside.md';
        File::put($outside, 'not content');
        $backup = $this->backupHolding($page->id(), 'pages/../../outside.md', File::get($page->path()));

        $response = Server::actingAs($this->editor())->tool(BackupsRestore::class, ['backup' => $backup, 'confirm' => true]);

        $response->assertHasErrors(['could not be read']);
        $this->assertSame('not content', File::get($outside));
    }

    public function test_rejects_a_backup_that_holds_another_entry(): void
    {
        $about = $this->aboutPage();
        $club = $this->page('club', 'Club');
        $backup = $this->backupHolding($about->id(), 'pages/club.md', "---\nid: {$club->id()}\ntitle: 'Not the club'\n---\n");

        $response = Server::actingAs($this->editor())->tool(BackupsRestore::class, ['backup' => $backup, 'confirm' => true]);

        $response->assertHasErrors(['does not hold']);
        $this->assertSame('Club', Entry::find($club->id())->get('title'));
    }

    public function test_rejects_a_backup_that_moves_the_entry_to_another_site(): void
    {
        config(['statamic.system.multisite' => true]);
        Site::setSites([
            'en' => ['name' => 'English', 'url' => '/', 'locale' => 'en_US'],
            'fr' => ['name' => 'French', 'url' => '/fr/', 'locale' => 'fr_FR'],
        ]);
        $this->pagesCollection();
        Collection::findByHandle('pages')->sites(['en', 'fr'])->save();
        $page = $this->page('about', 'About');
        $this->inAnMcpRequest();
        $backup = $this->backupHolding($page->id(), 'pages/fr/about.md', File::get($page->path()));

        $response = Server::actingAs($this->editor())->tool(BackupsRestore::class, ['backup' => $backup, 'confirm' => true]);

        $response->assertHasErrors(['does not hold']);
        $this->assertSame('en', Entry::find($page->id())->locale());
    }

    public function test_a_restore_that_removes_the_author_needs_the_other_authors_right(): void
    {
        $this->pagesWithAnAuthorField();
        $page = $this->page('about', 'About');
        $this->inAnMcpRequest();
        Entry::find($page->id())->set('author', ['limited'])->save();

        $response = Server::actingAs($this->editorAllowedTo(['view pages entries', 'edit pages entries']))->tool(BackupsRestore::class, [
            'backup' => $this->backupIdOf($this->historyZips()[0]),
            'confirm' => true,
        ]);

        $response->assertHasErrors(["'edit other authors pages entries'"]);
        $this->assertSame(['limited'], Entry::find($page->id())->authors()->all());
    }

    public function test_a_restore_that_publishes_the_entry_needs_the_publish_right(): void
    {
        $page = $this->aboutPage();
        $this->updateTitle($page, 'About us');
        Entry::find($page->id())->published(false)->save();

        $response = Server::actingAs($this->editorAllowedTo(['view pages entries', 'edit pages entries']))->tool(BackupsRestore::class, [
            'backup' => $this->backupIdOf($this->historyZips()[0]),
            'confirm' => true,
        ]);

        $response->assertHasErrors(["requires 'publish pages entries'"]);
        $this->assertFalse(Entry::find($page->id())->published());
    }

    public function test_bringing_back_a_deleted_entry_needs_the_create_right(): void
    {
        $page = $this->aboutPage();
        config(['statamic.mcp.deletes' => true]);
        Server::actingAs($this->editor())->tool(EntriesDelete::class, ['id' => $page->id()])->assertOk();

        $response = Server::actingAs($this->editorAllowedTo(['view pages entries', 'edit pages entries', 'publish pages entries']))->tool(BackupsRestore::class, [
            'backup' => $this->backupIdOf($this->historyZips()[0]),
            'confirm' => true,
        ]);

        $response->assertHasErrors(["requires 'create pages entries'"]);
        $this->assertNull(Entry::find($page->id()));
    }

    public function test_rejects_restoring_a_deleted_entry_onto_the_file_of_another_entry(): void
    {
        $page = $this->aboutPage();
        config(['statamic.mcp.deletes' => true]);
        Server::actingAs($this->editor())->tool(EntriesDelete::class, ['id' => $page->id()])->assertOk();
        $newer = $this->page('about', 'New about');

        $response = Server::actingAs($this->editor())->tool(BackupsRestore::class, [
            'backup' => $this->backupIdOf($this->historyZips()[0]),
            'confirm' => true,
        ]);

        $response->assertHasErrors(['another entry now uses its file']);
        $this->assertNull(Entry::find($page->id()));
        $this->assertFileExists($newer->path());
        $this->assertSame('New about', Entry::find($newer->id())->get('title'));
    }

    public function test_brings_back_a_deleted_entry_with_the_create_and_publish_rights_only(): void
    {
        $page = $this->aboutPage();
        config(['statamic.mcp.deletes' => true]);
        Server::actingAs($this->editor())->tool(EntriesDelete::class, ['id' => $page->id()])->assertOk();

        $response = Server::actingAs($this->editorAllowedTo(['view pages entries', 'create pages entries', 'publish pages entries']))->tool(BackupsRestore::class, [
            'backup' => $this->backupIdOf($this->historyZips()[0]),
            'confirm' => true,
        ]);

        $response->assertOk();
        $this->assertSame('About', Entry::find($page->id())->get('title'));
    }

    public function test_bringing_back_a_deleted_entry_of_another_author_needs_the_other_authors_right(): void
    {
        $this->pagesWithAnAuthorField();
        $page = $this->page('about', 'About')->set('author', ['someone-else']);
        $page->save();
        $this->inAnMcpRequest();
        config(['statamic.mcp.deletes' => true]);
        Server::actingAs($this->editor())->tool(EntriesDelete::class, ['id' => $page->id()])->assertOk();

        $response = Server::actingAs($this->editorAllowedTo(['view pages entries', 'create pages entries', 'publish pages entries']))->tool(BackupsRestore::class, [
            'backup' => $this->backupIdOf($this->historyZips()[0]),
            'confirm' => true,
        ]);

        $response->assertHasErrors(["'edit other authors pages entries'"]);
        $this->assertNull(Entry::find($page->id()));
    }

    public function test_rejects_an_entry_in_a_collection_with_revisions(): void
    {
        $page = $this->aboutPage();
        $this->updateTitle($page, 'About us');
        config(['statamic.editions.pro' => true, 'statamic.revisions.enabled' => true]);
        Collection::findByHandle('pages')->revisionsEnabled(true)->save();

        $response = Server::actingAs($this->editor())->tool(BackupsRestore::class, [
            'backup' => $this->backupIdOf($this->historyZips()[0]),
            'confirm' => true,
        ]);

        $response->assertHasErrors(['revision history']);
        $this->assertSame('About us', Entry::find($page->id())->get('title'));
    }

    public function test_rejects_an_id_that_is_not_in_the_history(): void
    {
        $response = Server::actingAs($this->editor())->tool(BackupsRestore::class, [
            'backup' => '../../.env',
            'confirm' => true,
        ]);

        $response->assertHasErrors(['not found']);
    }

    private function aboutPage(): EntryContract
    {
        $this->englishSite();
        $this->pagesCollection();
        $page = $this->page('about', 'About');
        $this->inAnMcpRequest();

        return $page;
    }

    /**
     * Statamic caches a collection's blueprint when it first reads it, so the
     * author field must exist before the first page does.
     */
    private function pagesWithAnAuthorField(): void
    {
        $this->englishSite();
        $this->pagesCollection();

        Blueprint::makeFromFields([
            'title' => ['type' => 'text', 'validate' => 'required'],
            'author' => ['type' => 'users', 'max_items' => 1],
        ])->setHandle('page')->setNamespace('collections.pages')->save();
    }

    private function updateTitle(EntryContract $page, string $title): void
    {
        Server::actingAs($this->editor())->tool(EntriesUpdate::class, [
            'id' => $page->id(),
            'data' => ['title' => $title],
        ])->assertOk();
    }

    private function backupIdOf(string $zip): string
    {
        return basename($zip, '.zip');
    }

    /**
     * A zip in the history folder that the hook did not make.
     */
    private function backupHolding(string $entryId, string $name, string $contents): string
    {
        $backup = "20261009-120000-000000--entry--{$entryId}";
        File::ensureDirectoryExists("{$this->fixtures()}/history");

        $zip = new ZipArchive;
        $zip->open("{$this->fixtures()}/history/{$backup}.zip", ZipArchive::CREATE);
        $zip->addFromString($name, $contents);
        $zip->close();

        return $backup;
    }
}
