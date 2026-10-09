<?php

/*
| The defaults of the "history" block in the base addon's config
| (config/statamic/mcp.php). A site sets only the keys it changes there; the
| service provider merges these defaults under them.
|
| Before an MCP request changes a file, spatie/laravel-backup zips that one
| file into the folder below on this disk. A local disk must survive a
| deploy: share storage/ between releases. A disk on another server, such as
| S3, also survives a lost server.
*/

return [
    'disk' => env('STATAMIC_MCP_HISTORY_DISK', 'local'),
    'folder' => 'mcp-history',
    'keep_days' => 90,
    'max_megabytes' => 5000,
];
