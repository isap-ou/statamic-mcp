<?php

namespace Isapp\StatamicMcp\Tests\Tools;

use Danielgnh\StatamicMcp\Tools\EntriesUpdate;
use Illuminate\Support\Facades\File;
use Isapp\StatamicMcp\Server;
use Isapp\StatamicMcp\Tests\TestCase;
use Isapp\StatamicMcp\Tools\BackupsHistory;
use Statamic\Facades\Entry;

class BackupsHistoryTest extends TestCase
{
    public function test_lists_each_earlier_version_of_a_page_with_its_title_newest_first(): void
    {
        $this->englishSite();
        $this->pagesCollection();
        $page = $this->page('about', 'About');
        $this->inAnMcpRequest();
        foreach (['About, first edit', 'About, second edit', 'About, third edit'] as $title) {
            Server::actingAs($this->editor())->tool(EntriesUpdate::class, [
                'id' => $page->id(),
                'data' => ['title' => $title],
            ])->assertOk();
        }

        $all = Server::actingAs($this->editor())->tool(BackupsHistory::class, ['entry' => $page->id()]);
        $newest = Server::actingAs($this->editor())->tool(BackupsHistory::class, ['entry' => $page->id(), 'limit' => 1]);

        $all->assertSee(['"title":"About, second edit"', '"title":"About, first edit"', '"title":"About"'])
            ->assertDontSee('"title":"About, third edit"')
            ->assertSee('"differs_from_now":true');
        $newest->assertSee('"title":"About, second edit"')
            ->assertDontSee('"title":"About, first edit"');
    }

    public function test_lists_only_the_backups_of_the_given_entry(): void
    {
        $this->englishSite();
        $this->pagesCollection();
        $about = $this->page('about', 'About');
        $club = $this->page('club', 'Club');
        $this->inAnMcpRequest();
        foreach ([$about, $club] as $page) {
            Server::actingAs($this->editor())->tool(EntriesUpdate::class, [
                'id' => $page->id(),
                'data' => ['title' => 'Changed'],
            ])->assertOk();
        }

        $response = Server::actingAs($this->editor())->tool(BackupsHistory::class, ['entry' => $about->id()]);

        $response->assertSee('"key":"'.$about->id().'"')
            ->assertDontSee('"key":"'.$club->id().'"');
        $this->assertSame('Changed', Entry::find($club->id())->get('title'));
    }

    public function test_still_lists_the_other_backups_when_one_zip_is_corrupt(): void
    {
        $this->englishSite();
        $this->pagesCollection();
        $page = $this->page('about', 'About');
        $this->inAnMcpRequest();
        Server::actingAs($this->editor())->tool(EntriesUpdate::class, [
            'id' => $page->id(),
            'data' => ['title' => 'About us'],
        ])->assertOk();
        File::put("{$this->fixtures()}/history/20261009-120000-000000--entry--{$page->id()}.zip", 'not a zip');

        $response = Server::actingAs($this->editor())->tool(BackupsHistory::class, ['entry' => $page->id()]);

        $response->assertSee(['"title":"About"', '"backup":"20261009-120000-000000--entry--'.$page->id().'"']);
    }

    public function test_hides_the_backups_of_a_collection_the_user_may_not_view(): void
    {
        $this->englishSite();
        $this->pagesCollection();
        $page = $this->page('about', 'About');
        $this->inAnMcpRequest();
        Server::actingAs($this->editor())->tool(EntriesUpdate::class, [
            'id' => $page->id(),
            'data' => ['title' => 'About us'],
        ])->assertOk();

        $response = Server::actingAs($this->editorAllowedTo(['edit pages entries']))->tool(BackupsHistory::class);

        $response->assertSee('"backups":[]');
    }
}
