<?php

namespace Isapp\StatamicMcp\Tests;

use Danielgnh\StatamicMcp\ServiceProvider as BaseAddonServiceProvider;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Isapp\StatamicMcp\ServiceProvider;
use Isapp\StatamicMcp\Tests\Support\CreatesContent;
use Laravel\Mcp\Server\McpServiceProvider;
use Spatie\Backup\BackupServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Statamic\Addons\Manifest;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use ZipArchive;

abstract class TestCase extends AddonTestCase
{
    use CreatesContent;
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    #[\Override]
    protected function getPackageProviders($app)
    {
        // AddonTestCase skips package discovery, so laravel/mcp, laravel-data,
        // laravel-backup and the base addon are registered by hand. Production
        // apps discover all four.
        return [
            ...parent::getPackageProviders($app),
            McpServiceProvider::class,
            LaravelDataServiceProvider::class,
            BackupServiceProvider::class,
            BaseAddonServiceProvider::class,
        ];
    }

    #[\Override]
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $cipher = $app['config']->get('app.cipher', 'AES-256-CBC');
        $app['config']->set('app.key', 'base64:'.base64_encode(Encrypter::generateKey($cipher)));

        // Statamic boots an addon only when the manifest lists it, and
        // AddonTestCase lists only the addon under test. Without this entry the
        // base addon never merges its config, so it exposes nothing.
        $manifest = $app->make(Manifest::class);
        $manifest->manifest = [
            ...$manifest->manifest,
            'danielgnh/statamic-mcp' => [
                'id' => 'danielgnh/statamic-mcp',
                'slug' => null,
                'version' => '0.7.2',
                'namespace' => 'Danielgnh\\StatamicMcp',
                'autoload' => 'src',
                'provider' => BaseAddonServiceProvider::class,
            ],
        ];

        // Blueprints, forms and roles are not Stache stores, so the dev-null
        // redirect does not reach them. Without this they leak into the shared
        // Testbench skeleton.
        $fixtures = $this->fixtures();
        $app['config']->set('statamic.system.blueprints_path', "{$fixtures}/blueprints");
        $app['config']->set('statamic.system.fieldsets_path', "{$fixtures}/fieldsets");
        $app['config']->set('statamic.forms.forms', "{$fixtures}/forms");
        $app['config']->set('statamic.users.repositories.file.paths.roles', "{$fixtures}/users/roles.yaml");
        $app['config']->set('statamic.users.repositories.file.paths.groups', "{$fixtures}/users/groups.yaml");
        $app['config']->set('filesystems.disks.mcp-history-test', ['driver' => 'local', 'root' => $fixtures]);

        // A test's own #[DefineEnvironment] runs before this method, so a
        // value it set already is kept.
        if (! $app['config']->has('statamic.mcp.history')) {
            $app['config']->set('statamic.mcp.history', ['disk' => 'mcp-history-test', 'folder' => 'history']);
        }
    }

    protected function fixtures(): string
    {
        return __DIR__.'/__fixtures__/dev-null';
    }

    /**
     * The hook backs up only inside an MCP request.
     */
    protected function inAnMcpRequest(): void
    {
        $this->app->instance('request', Request::create('/'.config('statamic.mcp.route'), 'POST'));
    }

    /**
     * @return list<string> the history zips, oldest first
     */
    protected function historyZips(): array
    {
        $zips = glob("{$this->fixtures()}/history/*.zip") ?: [];
        sort($zips);

        return $zips;
    }

    /**
     * @return list<string> the names of the files inside a zip
     */
    protected function filesIn(string $zip): array
    {
        $archive = new ZipArchive;
        $archive->open($zip);

        $names = [];

        for ($index = 0; $index < $archive->numFiles; $index++) {
            $names[] = $archive->getNameIndex($index);
        }

        $archive->close();

        return $names;
    }

    protected function firstFileIn(string $zip): string
    {
        $archive = new ZipArchive;
        $archive->open($zip);
        $contents = $archive->getFromIndex(0);
        $archive->close();

        return $contents;
    }
}
