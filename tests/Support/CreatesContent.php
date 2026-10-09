<?php

namespace Isapp\StatamicMcp\Tests\Support;

use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\User;

/**
 * The content a test needs, made the way the Control Panel makes it.
 */
trait CreatesContent
{
    protected function englishSite(): void
    {
        Site::setSites([
            'en' => ['name' => 'English', 'url' => '/', 'locale' => 'en_US'],
        ]);
    }

    protected function editor(): UserContract
    {
        return User::make()->email('editor@example.com')->makeSuper();
    }

    /**
     * An editor who is not a super user: one role gives only these rights.
     *
     * @param  list<string>  $permissions
     */
    protected function editorAllowedTo(array $permissions): UserContract
    {
        Role::make('limited')->title('Limited')->permissions($permissions)->save();

        return User::make()->id('limited')->email('limited@example.com')->assignRole('limited');
    }

    protected function pagesCollection(): void
    {
        Collection::make('pages')
            ->title('Pages')
            ->sites(['en'])
            ->routes('/{slug}')
            ->save();

        Blueprint::makeFromFields([
            'title' => ['type' => 'text', 'validate' => 'required'],
        ])->setHandle('page')->setNamespace('collections.pages')->save();
    }

    /**
     * A tree for the pages collection, so a page has a parent and a position.
     */
    protected function structurePages(): void
    {
        Collection::findByHandle('pages')
            ->structureContents(['root' => false])
            ->routes('{parent_uri}/{slug}')
            ->save();
    }

    protected function page(string $slug, string $title): EntryContract
    {
        return tap(
            Entry::make()
                ->collection('pages')
                ->locale('en')
                ->slug($slug)
                ->data(['title' => $title])
                ->published(true)
        )->save();
    }

    protected function settingsGlobal(): void
    {
        Blueprint::makeFromFields([
            'site_name' => ['type' => 'text'],
        ])->setHandle('settings')->setNamespace('globals')->save();

        $set = GlobalSet::make('settings')->title('Settings');
        $set->save();

        $set->makeLocalization('en')->data(['site_name' => 'Acme'])->save();
    }

    protected function mainNav(): void
    {
        $nav = Nav::make('main')->title('Main')->collections(['pages']);
        $nav->save();

        $nav->makeTree('en')->save();
    }
}
