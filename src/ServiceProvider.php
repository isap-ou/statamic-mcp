<?php

namespace Isapp\StatamicMcp;

use Danielgnh\StatamicMcp\Server as BaseServer;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Isapp\StatamicMcp\History\BackupBeforeWrite;
use Statamic\Events\CollectionTreeSaving;
use Statamic\Events\EntryDeleting;
use Statamic\Events\EntrySaving;
use Statamic\Events\GlobalVariablesSaving;
use Statamic\Events\NavTreeSaving;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $config = false;

    /**
     * The base addon reads statamic.mcp in its bootAddon(). Statamic runs
     * every bootAddon() in an app booted() callback, so after this boot().
     */
    #[\Override]
    public function boot(): void
    {
        $this->mergeHistoryConfig();

        $this->useOwnServer();

        $this->backUpBeforeEachMcpWrite();

        parent::boot();
    }

    #[\Override]
    public function bootAddon(): void
    {
        $this->warnAboutAServerWithoutHistory();
    }

    /**
     * The settings live in the base addon's config, as its "history" block. A
     * site that sets one key there keeps the defaults of the others.
     */
    protected function mergeHistoryConfig(): void
    {
        config(['statamic.mcp.history' => array_replace_recursive(
            require __DIR__.'/../config/history.php',
            config('statamic.mcp.history', []),
        )]);
    }

    /**
     * An unset server, or the base one that its published config names,
     * becomes this package's server.
     */
    protected function useOwnServer(): void
    {
        $server = config('statamic.mcp.server');

        if ($server !== null && $server !== BaseServer::class) {
            return;
        }

        config(['statamic.mcp.server' => Server::class]);
    }

    protected function backUpBeforeEachMcpWrite(): void
    {
        Event::listen([
            EntrySaving::class,
            EntryDeleting::class,
            GlobalVariablesSaving::class,
            NavTreeSaving::class,
            CollectionTreeSaving::class,
        ], BackupBeforeWrite::class);
    }

    protected function warnAboutAServerWithoutHistory(): void
    {
        $server = config('statamic.mcp.server');

        if (is_string($server) && is_a($server, Server::class, true)) {
            return;
        }

        Log::warning('statamic.mcp.server does not extend Isapp\StatamicMcp\Server, so the AI has no backups_history or backups_restore tool', [
            'server' => $server,
        ]);
    }
}
