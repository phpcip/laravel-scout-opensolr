<?php

/*
 * Opensolr Scout driver configuration.
 *
 * Set SCOUT_DRIVER=opensolr in .env and fill in the values below.
 * One vector-enabled Opensolr index serves ALL searchable models —
 * documents are scoped per model automatically.
 */

return [

    // Opensolr account credentials (Account > API in the control panel).
    'email' => env('OPENSOLR_EMAIL', ''),
    'api_key' => env('OPENSOLR_API_KEY', ''),

    // The vector-enabled Opensolr index used for this application.
    // Create one in the control panel (locations: us, de, fi) or via
    // Artisan: php artisan scout:index anything (uses this name).
    'index' => env('OPENSOLR_INDEX', ''),

    // Hybrid search: fuse BM25 keyword scores with semantic kNN per document.
    // Set to false for pure semantic search.
    'hybrid' => env('OPENSOLR_HYBRID', true),

    // Semantic <-> lexical balance for hybrid: 0 = all semantic, 1 = all lexical.
    'alpha' => env('OPENSOLR_ALPHA', 0.5),

];
