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

    // Search mode: hybrid | semantic | lexical.
    // "lexical" = pure keyword search: no embedding calls, zero AI quota,
    // and works on ANY Opensolr index, including non-vector ones.
    'mode' => env('OPENSOLR_MODE', 'hybrid'),

    // Fresh Results Bias: bias the ranking toward recent documents by multiplying
    // each score by a recency curve on creation_date. It re-orders and never
    // filters — the hit count is unchanged, nothing old becomes unreachable, and a
    // document with no creation_date is simply left unboosted. Applies to all three
    // search modes above. Turn it on when newer should beat older on a tie.
    'fresh_bias' => env('OPENSOLR_FRESH_BIAS', false),

    // Block until the ingestion queue finishes each write (~1 minute).
    // Leave false in production — Scout works fine with async indexing.
    'ingest_wait' => env('OPENSOLR_INGEST_WAIT', false),

];
