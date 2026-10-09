# Statamic MCP with history

One package that lets an AI client change Statamic content safely. It brings:

- **The MCP server** of [danielgnh/statamic-mcp](https://github.com/danielgnh/statamic-mcp).
  The AI lists, reads and changes entries, global sets and navigations. That addon
  validates the data against the blueprint and saves through Statamic.
- **A backup before each AI write.** Before Statamic changes the file of an entry, a global
  set, a navigation or a collection tree during an MCP request,
  [spatie/laravel-backup](https://github.com/spatie/laravel-backup) zips **only that file**.
  spatie removes old zips and reports a failed backup with its own notifications.
- **History and restore for the AI.** `backups_history` lists the earlier versions of an
  item, and `backups_restore` brings one back.

A save in the Control Panel or from a sync process makes no backup. Only MCP writes do.

## Requirements

- PHP 8.3 or later
- Statamic 6.31 or later, on flat files (the default Stache storage)
- The PHP `zip` extension

## Install

```bash
composer require isapp/statamic-mcp
```

Then set up the base addon as its README says, with token mode or OAuth. Token mode needs
no database. Each person gets a named token that acts as a Statamic user:

```bash
php artisan statamic:mcp:token admin@example.com --name="Laptop of Jan" --expires-days=180
```

Without `--expires-days`, a token never expires. The zips do not record which token made
the write, so give each person their own token and revoke it when they leave.

This package makes `Isapp\StatamicMcp\Server` the MCP server. If
`config/statamic/mcp.php` names the base `Server`, this package's server replaces it at
boot. If your app has its own server class, extend `Isapp\StatamicMcp\Server`. Any other
class logs a warning, because the AI then has no history tools.

## Settings

The settings live in the base addon's config, `config/statamic/mcp.php`, as a `history`
block. Set only the keys you change:

```php
'history' => [
    'disk' => env('STATAMIC_MCP_HISTORY_DISK', 'local'),
],
```

| Key under `history` | Default | Meaning |
| --- | --- | --- |
| `disk` | `local` | The filesystem disk for the zips. |
| `folder` | `mcp-history` | The folder on that disk. |
| `keep_days` | `90` | Each backup removes zips older than this. The newest zip always stays. |
| `max_megabytes` | `5000` | Above this, the oldest zips go first. |

On Laravel 11 and later, the `local` disk root is `storage/app/private`. A deploy that
makes a new release folder must share `storage/` between releases. A disk on another
server, such as S3, also keeps the history when the server is lost.

The zips use the password of the site's own backups, `backup.backup.password` in
`config/backup.php`, when it is set. The package reads it from the site's config, so it
also works when the config is cached.

## Monitoring

A failed backup cancels the save, so nothing is saved without a backup. The package then
fires spatie's `BackupHasFailed`, and spatie sends the failure notification that your
`config/backup.php` sets up (mail, Slack and others). Success notifications are off for
these zips, so an AI write sends no mail.

Publish spatie's config and set `notifications.mail.to`. Without it, spatie mails a
placeholder address. If the notification itself fails, the package reports that error,
and the save is still cancelled.

## History and restore

One zip holds one file. Its name says when and what:
`mcp-history/20261008-141503-123456--entry--<id>.zip`. Kinds are `entry`, `global`,
`nav` and `tree`.

Through MCP:

- `backups_history`: newest first. Filter by `entry` (an id, also of a deleted entry),
  `global` or `navigation` (a handle, with `site`), or `since`. Each line shows the title
  the item had then, and whether that version differs from now. It lists only items that
  the user may read through the base addon's tools.
- `backups_restore`: a dry run by default. With `confirm: true` it saves that version.
  The site backs up the current version first, so a restore can be undone too. It asks
  for the same rights as the base addon's write tools: `publish` when the restore changes
  the published state, and `create` to bring back a deleted entry. The file in the zip
  must hold the item that the zip's name says. An entry in a collection with revisions
  is refused; use its revision history in the Control Panel.

By hand, without the addon:

1. Take the file out of the zip. Its path inside the zip starts at the folder of its
   Stache store, for example `pages/about.md` under `content/collections/`.
2. Put it back in that folder.
3. Run `php please stache:refresh`. Without it, a production site does not see the file,
   because the Stache watcher runs only in the local environment.

## Limits

- Flat files only. A site on the Eloquent driver has no files to zip.
- One item at a time. To undo many changes, restore them one by one.
- A new entry has no earlier file, so it gets no zip. To undo it, delete it.
- Terms and assets get no zip.
- Statamic saves a new entry's place in the tree after the entry, and it does not check
  that save. If that tree backup fails, the entry is saved and appears at the end of the
  tree. The same is true for the tree change of a delete.

## Testing

```bash
composer test
```

## License

MIT. See [LICENSE.md](LICENSE.md).
