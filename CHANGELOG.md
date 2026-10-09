# Changelog

## 0.1.0 - 2026-10-09

### Added

- `Isapp\StatamicMcp\Server`: the base addon's MCP server plus `backups_history` and
  `backups_restore`.
- A backup of the one file that an MCP write of an entry, a global set, a navigation or a
  collection tree changes, made by spatie/laravel-backup, with cleanup after `keep_days`
  and spatie's failure notification.
- The settings, as a `history` block in the base addon's `config/statamic/mcp.php`.
