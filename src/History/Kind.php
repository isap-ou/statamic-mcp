<?php

namespace Isapp\StatamicMcp\History;

/**
 * The kinds of file a backup holds. Each is one file of the Stache.
 */
enum Kind: string
{
    case Entry = 'entry';
    case Global = 'global';
    case Nav = 'nav';
    case Tree = 'tree';

    /**
     * The Stache store that holds this kind of file. A zip keeps its file's
     * path relative to this store's folder.
     */
    public function storeKey(): string
    {
        return match ($this) {
            self::Entry => 'entries',
            self::Global => 'global-variables',
            self::Nav => 'nav-trees',
            self::Tree => 'collection-trees',
        };
    }
}
