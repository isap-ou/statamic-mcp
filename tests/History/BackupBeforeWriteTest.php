<?php

namespace Isapp\StatamicMcp\Tests\History;

use Carbon\CarbonImmutable;
use Danielgnh\StatamicMcp\Tools\EntriesUpdate;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Isapp\StatamicMcp\Server;
use Isapp\StatamicMcp\Tests\TestCase;
use Isapp\StatamicMcp\Tools\BackupsHistory;
use Laravel\Mcp\Server\Testing\TestResponse;
use RuntimeException;
use Spatie\Backup\Events\BackupHasFailed;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use ZipArchive;

class BackupBeforeWriteTest extends TestCase
{
    public function test_zips_only_the_old_file_before_an_mcp_write(): void
    {
        $page = $this->aboutPage();
        $before = File::get($page->path());
        $this->inAnMcpRequest();

        $this->updateTitle($page, 'About us')->assertOk();

        $zips = $this->historyZips();
        $this->assertCount(1, $zips);
        $this->assertStringContainsString("--entry--{$page->id()}.zip", $zips[0]);
        $this->assertSame(['pages/about.md'], $this->filesIn($zips[0]));
        $this->assertSame($before, $this->firstFileIn($zips[0]));
        $this->assertSame('About us', Entry::find($page->id())->get('title'));
    }

    public function test_zips_the_entry_and_the_tree_when_a_write_changes_and_moves_the_entry(): void
    {
        $this->englishSite();
        $this->pagesCollection();
        $this->structurePages();
        $club = $this->page('club', 'Club');
        $history = $this->page('history', 'History');
        Collection::findByHandle('pages')->structure()->in('en')->save();
        $this->inAnMcpRequest();

        Server::actingAs($this->editor())->tool(EntriesUpdate::class, [
            'id' => $history->id(),
            'data' => ['title' => 'Our history'],
            'parent' => $club->id(),
        ])->assertOk();

        $names = implode("\n", $this->historyZips());
        $this->assertCount(2, $this->historyZips());
        $this->assertStringContainsString("--entry--{$history->id()}.zip", $names);
        $this->assertStringContainsString('--tree--pages.en.zip', $names);
    }

    public function test_makes_no_backup_for_a_save_outside_an_mcp_request(): void
    {
        $page = $this->aboutPage();

        $page->set('title', 'About us')->save();

        $this->assertSame([], $this->historyZips());
    }

    public function test_cancels_the_write_and_reports_it_when_the_backup_fails(): void
    {
        Event::fake([BackupHasFailed::class]);
        $page = $this->aboutPage();
        $before = File::get($page->path());
        $this->breakTheHistoryDisk();
        $this->inAnMcpRequest();

        $response = $this->updateTitle($page, 'About us');

        $response->assertHasErrors(['cancelled by a listener']);
        $this->assertSame($before, File::get($page->path()));
        Event::assertDispatched(BackupHasFailed::class);
    }

    public function test_cancels_the_write_also_when_the_failure_notice_fails(): void
    {
        $page = $this->aboutPage();
        $before = File::get($page->path());
        $this->breakTheHistoryDisk();
        Event::listen(BackupHasFailed::class, fn () => throw new RuntimeException('the mail server is down'));
        $this->inAnMcpRequest();

        $response = $this->updateTitle($page, 'About us');

        $response->assertHasErrors(['cancelled by a listener']);
        $this->assertSame($before, File::get($page->path()));
    }

    public function test_leaves_the_temporary_folder_of_another_backup_alone(): void
    {
        $page = $this->aboutPage();
        $otherBackup = config('backup.backup.temporary_directory').'/temp/manifest.txt';
        File::ensureDirectoryExists(dirname($otherBackup));
        File::put($otherBackup, 'another backup is running');
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory(config('backup.backup.temporary_directory')));
        $this->inAnMcpRequest();

        $this->updateTitle($page, 'About us')->assertOk();

        $this->assertSame('another backup is running', File::get($otherBackup));
    }

    public function test_encrypts_the_zip_with_the_password_of_the_site_backups(): void
    {
        config(['backup.backup.password' => 'secret']);
        $page = $this->aboutPage();
        $this->inAnMcpRequest();

        $this->updateTitle($page, 'About us')->assertOk();

        $zip = new ZipArchive;
        $zip->open($this->historyZips()[0]);
        $this->assertNotSame(ZipArchive::EM_NONE, $zip->statIndex(0)['encryption_method']);
        $zip->close();
        Server::actingAs($this->editor())->tool(BackupsHistory::class, ['entry' => $page->id()])
            ->assertSee('"title":"About"');
    }

    public function test_backs_up_when_the_route_is_set_with_a_leading_slash(): void
    {
        $page = $this->aboutPage();
        $this->inAnMcpRequest();
        config(['statamic.mcp.route' => '/mcp/statamic']);

        $this->updateTitle($page, 'About us')->assertOk();

        $this->assertCount(1, $this->historyZips());
    }

    public function test_names_the_zip_with_the_time_in_utc(): void
    {
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Brussels');
        $this->beforeApplicationDestroyed(fn () => date_default_timezone_set($timezone));
        $this->travelTo(CarbonImmutable::parse('2026-10-09 14:15:03', 'Europe/Brussels'));
        $page = $this->aboutPage();
        $this->inAnMcpRequest();

        $this->updateTitle($page, 'About us')->assertOk();

        $this->assertStringContainsString('/20261009-121503-000000--entry--', $this->historyZips()[0]);
    }

    public function test_sends_no_notification_for_a_backup_that_succeeds(): void
    {
        Notification::fake();
        $page = $this->aboutPage();
        $this->inAnMcpRequest();

        $this->updateTitle($page, 'About us')->assertOk();

        Notification::assertNothingSent();
    }

    public function test_removes_backups_older_than_the_kept_days(): void
    {
        $page = $this->aboutPage();
        $this->inAnMcpRequest();
        $this->updateTitle($page, 'About, first')->assertOk();
        [$first] = $this->historyZips();
        // spatie dates these zips by the file's modified time, which a time
        // travel in the test does not move.
        touch($first, now()->subDays(91)->getTimestamp());

        $this->updateTitle(Entry::find($page->id()), 'About, second')->assertOk();

        $zips = $this->historyZips();
        $this->assertCount(1, $zips);
        $this->assertNotSame($first, $zips[0]);
    }

    private function aboutPage(): EntryContract
    {
        $this->englishSite();
        $this->pagesCollection();

        return $this->page('about', 'About');
    }

    /**
     * A file where the root folder of the disk should be makes every write
     * to the disk fail.
     */
    private function breakTheHistoryDisk(): void
    {
        File::put("{$this->fixtures()}/not-a-folder", 'a file where the disk root should be');
        config([
            'filesystems.disks.broken' => ['driver' => 'local', 'root' => "{$this->fixtures()}/not-a-folder"],
            'statamic.mcp.history.disk' => 'broken',
        ]);
    }

    private function updateTitle(EntryContract $page, string $title): TestResponse
    {
        return Server::actingAs($this->editor())->tool(EntriesUpdate::class, [
            'id' => $page->id(),
            'data' => ['title' => $title],
        ]);
    }
}
