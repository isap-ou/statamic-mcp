<?php

namespace Isapp\StatamicMcp;

use Danielgnh\StatamicMcp\Server as BaseServer;
use Danielgnh\StatamicMcp\ToolRegistry;
use Isapp\StatamicMcp\Tools\BackupsHistory;
use Isapp\StatamicMcp\Tools\BackupsRestore;

/**
 * The base addon's server, plus the two tools that let the AI read the backup
 * history and bring one item back. A site that needs its own server extends
 * this class.
 */
class Server extends BaseServer
{
    #[\Override]
    protected function tools(ToolRegistry $tools): void
    {
        $tools->add(BackupsHistory::class, BackupsRestore::class);
    }
}
