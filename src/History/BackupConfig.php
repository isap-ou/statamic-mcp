<?php

namespace Isapp\StatamicMcp\History;

use Spatie\Backup\Config\Config;

/**
 * spatie/laravel-backup settings for the history zips. Config::fromArray()
 * fills every key left out from spatie's own defaults, not from the site's
 * config/backup.php, so a site's own full backups stay as they are.
 *
 * The password comes from the site's config, not from spatie's defaults:
 * those read env() again, which is empty once the config is cached.
 */
class BackupConfig
{
    /**
     * A file under $relativeTo keeps only its path from there in the zip. The
     * hook passes the folder of the file's Stache store, so the name stays the
     * same when a deploy moves the app.
     */
    public function make(?string $relativeTo = null): Config
    {
        return Config::fromArray([
            'backup' => [
                'name' => $this->folder(),
                'source' => [
                    'files' => [
                        'include' => [],
                        'exclude' => [],
                        'follow_links' => false,
                        'ignore_unreadable_directories' => false,
                        'relative_path' => $relativeTo,
                    ],
                    'databases' => [],
                ],
                'destination' => [
                    'disks' => [$this->disk()],
                ],
                'password' => config('backup.backup.password'),
                'encryption' => config('backup.backup.encryption', 'default'),
            ],
            'cleanup' => [
                'default_strategy' => [
                    'keep_all_backups_for_days' => config('statamic.mcp.history.keep_days'),
                    'keep_daily_backups_for_days' => 0,
                    'keep_weekly_backups_for_weeks' => 0,
                    'keep_monthly_backups_for_months' => 0,
                    'keep_yearly_backups_for_years' => 0,
                    'delete_oldest_backups_when_using_more_megabytes_than' => config('statamic.mcp.history.max_megabytes'),
                ],
            ],
        ]);
    }

    public function disk(): string
    {
        return config('statamic.mcp.history.disk');
    }

    /**
     * spatie stores each backup name in a folder of that name on the disk.
     */
    public function folder(): string
    {
        return config('statamic.mcp.history.folder');
    }
}
