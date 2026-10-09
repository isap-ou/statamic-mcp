<?php

namespace Isapp\StatamicMcp\History;

use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Spatie\Backup\BackupDestination\BackupDestinationFactory;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Notifications\EventHandler;
use Spatie\Backup\Tasks\Backup\BackupJobFactory;
use Spatie\Backup\Tasks\Backup\FileSelection;
use Spatie\Backup\Tasks\Cleanup\CleanupJob;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Statamic\Events\CollectionTreeSaving;
use Statamic\Events\EntryDeleting;
use Statamic\Events\EntrySaving;
use Statamic\Events\GlobalVariablesSaving;
use Statamic\Events\NavTreeSaving;
use Statamic\Facades\Stache;
use Throwable;

/**
 * Before an MCP request changes a file of the Stache, spatie/laravel-backup
 * zips that one file. Each of these events halts on the first answer, so
 * false cancels the save: nothing is saved without a backup.
 */
class BackupBeforeWrite
{
    public function __construct(
        private SavedFiles $savedFiles,
        private BackupConfig $config,
        private BackupNames $names,
    ) {}

    public function handle(EntrySaving|EntryDeleting|GlobalVariablesSaving|NavTreeSaving|CollectionTreeSaving $event): ?bool
    {
        if (! request()->is(trim(config('statamic.mcp.route'), '/'))) {
            return null;
        }

        $file = $this->savedFiles->forEvent($event);

        if ($file === null || ! is_file($file->path)) {
            return null;
        }

        try {
            $this->backUp($file);
        } catch (Throwable $e) {
            $this->reportFailure($e);

            return false;
        }

        rescue(fn () => $this->cleanUp());

        return null;
    }

    /**
     * spatie mails on every successful backup by default. One mail per AI
     * write is noise, so its notifications are off while this zip is made.
     *
     * spatie's Zip names the files from the Config in the container, not from
     * the job's (Zip::createForManifest()). This zip binds its own Config for
     * the run; forgetInstance() then lets spatie's scoped binding build the
     * site's own Config again.
     *
     * Every spatie job empties and deletes one shared temporary folder. Two
     * MCP writes at the same time would empty each other's, so each run gets
     * its own.
     */
    private function backUp(SavedFile $file): void
    {
        $config = $this->config->make(Stache::store($file->ref->kind->storeKey())->directory());
        $temporaryFolder = sys_get_temp_dir().'/mcp-history-'.Str::uuid();

        EventHandler::disable();
        app()->instance(Config::class, $config);
        app()->instance('backup-temporary-project', app('backup-temporary-project')->location($temporaryFolder));

        try {
            BackupJobFactory::createFromConfig($config)
                ->dontBackupDatabases()
                ->disableSignals()
                ->setFileSelection(FileSelection::create([$file->path]))
                ->setFilename($this->names->for($file->ref, now('UTC')->toImmutable()))
                ->run();
        } finally {
            app()->forgetInstance(Config::class);
            app()->forgetInstance('backup-temporary-project');
            File::deleteDirectory($temporaryFolder);
            EventHandler::enable();
        }
    }

    private function cleanUp(): void
    {
        $config = $this->config->make();

        EventHandler::disable();

        try {
            (new CleanupJob(BackupDestinationFactory::createFromArray($config), new DefaultStrategy($config)))->run();
        } finally {
            EventHandler::enable();
        }
    }

    /**
     * spatie's own notifier sends the failure notice that the site configured
     * for its backups, so a failed AI write is reported the same way. A notice
     * that fails, such as a mail server that is down, is reported too, but
     * the save is still cancelled the normal way.
     */
    private function reportFailure(Throwable $e): void
    {
        report($e);

        rescue(fn () => event(new BackupHasFailed(
            exception: $e instanceof Exception ? $e : new Exception($e->getMessage(), 0, $e),
            diskName: $this->config->disk(),
            backupName: $this->config->folder(),
        )));
    }
}
