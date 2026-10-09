<?php

namespace Isapp\StatamicMcp\Tests;

use Danielgnh\StatamicMcp\Server as BaseServer;
use Illuminate\Support\Facades\File;
use Isapp\StatamicMcp\Server;
use Isapp\StatamicMcp\Tests\Support\HostServer;
use Isapp\StatamicMcp\Tests\Support\UnguardedServer;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class ServiceProviderTest extends TestCase
{
    public function test_uses_its_own_server_when_the_host_names_none(): void
    {
        $this->assertSame(Server::class, config('statamic.mcp.server'));
    }

    #[DefineEnvironment('namesTheBaseServer')]
    public function test_replaces_the_base_server_that_the_published_config_names(): void
    {
        $this->assertSame(Server::class, config('statamic.mcp.server'));
    }

    #[DefineEnvironment('namesAServerThatExtendsItsOwn')]
    public function test_keeps_a_host_server_that_extends_its_own_without_a_warning(): void
    {
        $this->assertSame(HostServer::class, config('statamic.mcp.server'));
        $this->assertFileDoesNotExist($this->warningsLog());
    }

    #[DefineEnvironment('namesAServerWithoutHistory')]
    public function test_keeps_another_host_server_and_logs_a_warning(): void
    {
        $this->assertSame(UnguardedServer::class, config('statamic.mcp.server'));
        $this->assertStringContainsString(
            'so the AI has no backups_history or backups_restore tool',
            File::get($this->warningsLog()),
        );
    }

    #[DefineEnvironment('setsOnlyTheHistoryDisk')]
    public function test_a_history_block_with_one_key_keeps_the_other_defaults(): void
    {
        $this->assertSame('s3', config('statamic.mcp.history.disk'));
        $this->assertSame('mcp-history', config('statamic.mcp.history.folder'));
        $this->assertSame(90, config('statamic.mcp.history.keep_days'));
    }

    protected function namesTheBaseServer($app): void
    {
        $app['config']->set('statamic.mcp.server', BaseServer::class);
    }

    protected function namesAServerThatExtendsItsOwn($app): void
    {
        $app['config']->set('statamic.mcp.server', HostServer::class);
        $this->logWarningsToFile($app);
    }

    protected function namesAServerWithoutHistory($app): void
    {
        $app['config']->set('statamic.mcp.server', UnguardedServer::class);
        $this->logWarningsToFile($app);
    }

    protected function setsOnlyTheHistoryDisk($app): void
    {
        $app['config']->set('statamic.mcp.history', ['disk' => 's3']);
    }

    private function logWarningsToFile($app): void
    {
        $app['config']->set('logging.default', 'warnings');
        $app['config']->set('logging.channels.warnings', [
            'driver' => 'single',
            'path' => $this->warningsLog(),
            'level' => 'warning',
        ]);
    }

    private function warningsLog(): string
    {
        return __DIR__.'/__fixtures__/dev-null/warnings.log';
    }
}
