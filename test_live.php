<?php

/**
 * LIVE production test for laravel-scout-opensolr.
 *
 *   php test_live.php
 *
 * Exercises every public method of OpensolrClient, AiPrompt and OpensolrEngine against the
 * REAL Opensolr platform (opensolr.com for management, api.opensolr.com for AI + ingestion),
 * using the public MCP demo account. Nothing is mocked and nothing is stubbed except the
 * Eloquent side of Scout (see LiveModel below) — every assertion below is about a VALUE that
 * came back over the wire.
 *
 * SAFETY
 * - The seeded index (mcp_demo_d1__dense, 300 real news articles) is only ever READ.
 * - Everything that writes goes to a throwaway index this script creates for itself,
 *   mcp_t_laravel_<random>__dense, deleted again in a finally block AND from a shutdown
 *   handler, so it goes away on a failure, an exception or a fatal error too.
 * - Safe to re-run: the temp index name is random per run, so two runs never collide.
 *
 * PACING
 * The account is documented at 30 requests/minute, so every call that reaches the management
 * or AI API is announced to the Rate limiter first and the script sleeps rather than burst.
 * Calls that go straight to Solr (select/update on the index host) do not pass through the
 * platform's rate limiter and are declared as 0.
 *
 * NOTE ON CLEANUP: OpensolrClient has no deleteIndex() — index deletion is deliberately not
 * exposed by the package (OpensolrEngine::deleteIndex() returns null on purpose). The cleanup
 * below therefore calls the management API's delete_index endpoint directly with Guzzle; it is
 * test scaffolding, not package code.
 */

require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client as Guzzle;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Opensolr\ScoutOpensolr\AiPrompt;
use Opensolr\ScoutOpensolr\OpensolrClient;
use Opensolr\ScoutOpensolr\OpensolrEngine;

// ---------------------------------------------------------------------------------------------
// Configuration — the public MCP demo account. Throwaway by design, swept after 3 days.
// ---------------------------------------------------------------------------------------------
const EMAIL = 'mcp@opensolr.com';
const API_KEY = '420b8b23e7b12dc8ab838932145a5065';
const DEMO_INDEX = 'mcp_demo_d1__dense';        // read-only, 300 seeded news articles
const MGMT_BASE = 'https://opensolr.com/solr_manager/api';
const RATE_PER_MIN = 25;                        // documented limit is 30/min — leave headroom
const INGEST_DEADLINE = 150;                    // seconds to wait for async ingestion (~60s typical)

/** Temp index for everything that writes. __dense marks it vector-enabled. */
$TEMP_INDEX = 'mcp_t_laravel_' . bin2hex(random_bytes(4)) . '__dense';

$PASS = 0;
$FAIL = 0;

// ---------------------------------------------------------------------------------------------
// Reporting helpers — one line per check, ✔ or ✘, and the asserted value in the message.
// ---------------------------------------------------------------------------------------------

/** Record a passing assertion. */
function ok(string $msg): void
{
    global $PASS;
    $PASS++;
    echo "✔ {$msg}\n";
}

/** Record a failing assertion. */
function bad(string $msg): void
{
    global $FAIL;
    $FAIL++;
    echo "✘ {$msg}\n";
}

/** Assert a condition and report it either way; returns the condition so callers can branch. */
function check(bool $cond, string $msg): bool
{
    $cond ? ok($msg) : bad($msg);

    return $cond;
}

/** Run a group of assertions; an exception inside becomes one failure instead of killing the run. */
function step(string $label, callable $fn): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        bad("{$label} — threw " . get_class($e) . ': ' . str_replace("\n", ' ', trim($e->getMessage())));
    }
}

/** Print a section header so the output is readable when a run is long. */
function section(string $title): void
{
    echo "\n── {$title}\n";
}

/** Shorten a value for a one-line assertion message. */
function brief(mixed $v, int $len = 90): string
{
    $s = is_scalar($v) ? (string) $v : json_encode($v);
    $s = preg_replace('/\s+/u', ' ', (string) $s);

    return mb_strlen($s) > $len ? mb_substr($s, 0, $len) . '…' : $s;
}

// ---------------------------------------------------------------------------------------------
// Rate limiter — a rolling 60s window over the requests that hit the platform API.
// ---------------------------------------------------------------------------------------------
final class Rate
{
    /** @var float[] timestamps of API requests made so far */
    private static array $hits = [];

    /**
     * Reserve $n API requests, sleeping until they fit inside the rolling minute.
     * Calls that go straight to Solr (select/update) are not rate limited: pass 0.
     */
    public static function reserve(int $n): void
    {
        if ($n <= 0) {
            return;
        }
        while (true) {
            $cutoff = microtime(true) - 60.0;
            self::$hits = array_values(array_filter(self::$hits, fn ($t) => $t > $cutoff));
            if (count(self::$hits) + $n <= RATE_PER_MIN) {
                break;
            }
            usleep(1_000_000);
        }
        $now = microtime(true);
        for ($i = 0; $i < $n; $i++) {
            self::$hits[] = $now;
        }
    }
}

// ---------------------------------------------------------------------------------------------
// Scout stubs.
//
// The engine only ever touches a model through the handful of methods Scout's Searchable trait
// provides, so a plain object supplies them: booting a full Laravel app (Testbench + sqlite +
// migrations) would prove nothing extra about THIS package and would hide the engine's own logic
// behind Eloquent's. Everything the engine does with what these return — the ingestion document
// shape, the meta_* filters, the re-ordering in map() — is still the package's real code running
// against the real index.
//
// Laravel\Scout\Builder is used unchanged (the engine type-hints it); it is a plain value object
// that needs no container.
// ---------------------------------------------------------------------------------------------
class LiveModel
{
    /** Rows this "table" holds, keyed by scout key — the stand-in for the database. */
    public static array $rows = [];

    public function __construct(public string $key = '0')
    {
    }

    /** Scout: the per-model index scope. The engine writes it to meta_model and filters on it. */
    public function searchableAs(): string
    {
        return 'scout_live_posts';
    }

    /** Scout: primary key. */
    public function getScoutKey(): string
    {
        return $this->key;
    }

    /** Scout: the indexable payload. */
    public function toSearchableArray(): array
    {
        return self::$rows[$this->key] ?? [];
    }

    /** Scout: extra metadata merged over the searchable array (used here for the timestamp). */
    public function scoutMetadata(): array
    {
        $ts = self::$rows[$this->key]['timestamp'] ?? null;

        return $ts ? ['timestamp' => $ts] : [];
    }

    /**
     * Scout: hydrate models for the ids a search returned.
     *
     * Returned deliberately in REVERSE order, the way a `whereIn` from a database would come
     * back in arbitrary order — that is what makes the assertion in map() meaningful: the engine
     * has to restore Solr's ranking, not just pass the rows through.
     */
    public function getScoutModelsByIds(Builder $builder, array $ids): Collection
    {
        return new Collection(array_map(fn ($id) => new self((string) $id), array_reverse($ids)));
    }

    /** Eloquent: the collection type the engine wraps results in. */
    public function newCollection(array $models = []): Collection
    {
        return new Collection($models);
    }

    /** Convenience: title of a row, for readable assertion messages. */
    public function title(): string
    {
        return (string) (self::$rows[$this->key]['title'] ?? '');
    }
}

// ---------------------------------------------------------------------------------------------
// Cleanup — runs from the finally block AND from a shutdown handler (fatal errors included).
// ---------------------------------------------------------------------------------------------
$CLEANED = false;

/**
 * Delete the temporary index through the management API.
 *
 * Idempotent: only the first call does anything, so the finally block and the shutdown handler
 * cannot double-delete or double-report.
 */
function cleanupTempIndex(string $index, bool $announce = true): void
{
    global $CLEANED;
    if ($CLEANED) {
        return;
    }
    $CLEANED = true;
    try {
        Rate::reserve(1);
        $http = new Guzzle(['timeout' => 90]);
        $res = $http->post(MGMT_BASE . '/delete_index', [
            'form_params' => ['email' => EMAIL, 'api_key' => API_KEY, 'index_name' => $index],
            'http_errors' => false,
        ]);
        $body = json_decode((string) $res->getBody(), true);
        $msg = is_array($body) ? ($body['msg'] ?? '') : '';
        if ($announce) {
            check(is_array($body) && ($body['status'] ?? false) === true && $msg === 'DELETED_OK',
                "cleanup: temp index {$index} deleted (msg=" . brief($msg) . ')');
        } elseif ($msg !== 'DELETED_OK') {
            echo "!! cleanup: temp index {$index} may still exist: " . brief($body) . "\n";
        }
    } catch (Throwable $e) {
        echo "!! cleanup FAILED for {$index}: " . $e->getMessage() . "\n";
        echo "!! delete it by hand: https://opensolr.com/admin/solr_manager\n";
    }
}

register_shutdown_function(function () use (&$TEMP_INDEX) {
    // Only fires as a safety net; the normal path already cleaned up in the finally block.
    cleanupTempIndex($TEMP_INDEX, false);
});

/**
 * Poll Solr directly until the index holds $expected documents matching $fq.
 *
 * Ingestion is asynchronous (a once-a-minute worker embeds and commits), so this polls the real
 * document count rather than sleeping a fixed time. Polling goes straight to the index host, so
 * it costs nothing against the API rate limit.
 *
 * @return array{0: bool, 1: int, 2: float} [reached, lastCount, elapsedSeconds]
 */
function waitForDocs(OpensolrClient $client, string $index, int $expected, ?string $fq, int $deadline): array
{
    $start = microtime(true);
    $found = -1;
    while ((microtime(true) - $start) < $deadline) {
        try {
            $params = ['q' => '*:*', 'rows' => 0];
            if ($fq !== null) {
                $params['fq'] = $fq;
            }
            $r = $client->solrSelect($index, $params);
            $found = (int) ($r['response']['numFound'] ?? -1);
            if ($found >= $expected) {
                return [true, $found, round(microtime(true) - $start, 1)];
            }
        } catch (Throwable) {
            // A brand-new core can 404 for a moment while Apache reloads its proxy config.
        }
        sleep(5);
    }

    return [false, $found, round(microtime(true) - $start, 1)];
}

/** Count how many of $needles appear in $hay (case-insensitive), returning the ones found. */
function foundTokens(string $hay, array $needles): array
{
    return array_values(array_filter($needles, fn ($n) => stripos($hay, $n) !== false));
}

/** Count distinct whole words from $words present in $text (unicode-aware, case-insensitive). */
function countWords(string $text, array $words): int
{
    $n = 0;
    foreach ($words as $w) {
        if (preg_match('/(?<![\p{L}])' . preg_quote($w, '/') . '(?![\p{L}])/ui', $text)) {
            $n++;
        }
    }

    return $n;
}

/** Cosine similarity of two equal-length vectors. */
function cosine(array $a, array $b): float
{
    $dot = $na = $nb = 0.0;
    foreach ($a as $i => $v) {
        $dot += $v * $b[$i];
        $na += $v * $v;
        $nb += $b[$i] * $b[$i];
    }

    return ($na > 0 && $nb > 0) ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
}

// =============================================================================================
echo "laravel-scout-opensolr — LIVE production test\n";
echo 'account: ' . EMAIL . "  |  read-only index: " . DEMO_INDEX . "  |  temp index: {$TEMP_INDEX}\n";

$client = new OpensolrClient(EMAIL, API_KEY, 180);

try {
    // -----------------------------------------------------------------------------------------
    section('AiPrompt — the cross-repo prompt contract (offline, no API calls)');
    // -----------------------------------------------------------------------------------------
    step('AiPrompt::context/instruction', function () {
        // doc 2 scores below half of the best of the first topN rows: the relevance floor must
        // drop it, and the numbering must stay dense (1, 2) rather than leaving a gap.
        $docs = [
            ['id' => 'd1', 'score' => 10.0, 'title' => ['Alpha title'], 'description' => 'Alpha desc',
                'text' => 'alpha body one two three four five six seven'],
            ['id' => 'd2', 'score' => 1.0, 'title' => 'Weak doc', 'description' => '', 'text' => 'weak'],
            ['id' => 'd3', 'score' => 9.0, 'title' => 'Gamma', 'description' => 'Gamma desc',
                'text' => 'gamma tail', 'text_t' => str_repeat('structured ', 12)],
        ];
        $hl = ['d1' => ['text' => ['fragment <em>alpha</em> that was cut']]];
        $ctx = AiPrompt::context($docs, $hl, 4, 5);

        check(substr_count($ctx, '===== DOCUMENT ') === 2,
            'context(): 2 of 3 hits kept — relevance floor dropped the 1.0 against a 10.0 best (fences=' . substr_count($ctx, '===== DOCUMENT ') . ')');
        check(str_contains($ctx, '===== DOCUMENT 1 =====') && str_contains($ctx, '===== DOCUMENT 2 =====')
            && !str_contains($ctx, '===== DOCUMENT 3 ====='),
            'context(): documents numbered densely 1..2, no gap left by the dropped hit');
        check(str_contains($ctx, '===== END OF DOCUMENT 1 =====') && str_contains($ctx, '===== END OF DOCUMENT 2 ====='),
            'context(): every document is closed by its own ===== END OF DOCUMENT n ===== fence');
        check(!str_contains($ctx, 'Weak doc'), 'context(): the below-floor document is absent from the context');
        check(str_contains($ctx, 'Alpha title') && !str_contains($ctx, 'Array'),
            'context(): a multiValued (array) title is flattened to text, not cast to "Array"');
        check(str_contains($ctx, 'MOST RELEVANT EXCERPTS:')
            && str_contains($ctx, '... fragment alpha that was cut ...'),
            'context(): highlight fragment is included, <em> stripped, ellipsis-marked at both cut ends');
        check(str_contains($ctx, 'alpha body one two three') && !str_contains($ctx, 'four five six seven'),
            'context(): body cut at exactly maxWords=5 words');
        check(str_contains($ctx, 'structured structured') && str_contains($ctx, 'Gamma desc'),
            'context(): text_t is concatenated into the body when it carries real content');

        $instr = AiPrompt::instruction($ctx, 'What is alpha?');
        check(str_starts_with($instr, $ctx), 'instruction(): documents come FIRST, verbatim');
        check(str_ends_with($instr, "Question: What is alpha?\nAnswer:"),
            'instruction(): ends with the trailing "Question: … Answer:" slot');
        check(str_contains($instr, 'Those were the 2 documents.'),
            'instruction(): {document_count} substituted with the number of surviving fences (2)');
        check(!str_contains($instr, '{document_count}') && !str_contains($instr, '{question}'),
            'instruction(): no unsubstituted {placeholders} left');
        check(str_contains($instr, 'Never begin with "Based on"') && str_contains($instr, 'According to'),
            'instruction(): carries the shipped "never begin with Based on/According to" rule');

        $empty = AiPrompt::instruction(AiPrompt::context([], [], 4, 100), 'q');
        check(str_contains($empty, 'Those were the 1 documents.'),
            'instruction(): max(1,…) keeps the sentence grammatical when retrieval came back empty');

        check(OpensolrClient::DEFAULT_RAG_INSTRUCTION === AiPrompt::DEFAULT_RAG_INSTRUCTION,
            'OpensolrClient::DEFAULT_RAG_INSTRUCTION is an alias of AiPrompt::DEFAULT_RAG_INSTRUCTION (one definition)');
        check(OpensolrClient::FRESH_BIAS_FUNCTION === 'recip(max(0,ms(NOW,creation_date)),3.16e-11,1,1)',
            'OpensolrClient::FRESH_BIAS_FUNCTION matches the platform function byte for byte');
    });

    // -----------------------------------------------------------------------------------------
    section('OpensolrClient — read-only against ' . DEMO_INDEX);
    // -----------------------------------------------------------------------------------------
    $demoInfo = null;

    step('coreInfo()', function () use ($client, &$demoInfo) {
        Rate::reserve(1);
        $demoInfo = $client->coreInfo(DEMO_INDEX);
        check(str_contains((string) ($demoInfo['connection_url'] ?? ''), DEMO_INDEX),
            'coreInfo(): connection_url points at the index — ' . brief($demoInfo['connection_url'] ?? ''));
        check(!empty($demoInfo['auth_username']),
            'coreInfo(): HTTP basic auth username returned — ' . brief($demoInfo['auth_username'] ?? ''));
        check(($demoInfo['solr_version'] ?? '') !== '',
            'coreInfo(): solr_version reported — ' . brief($demoInfo['solr_version'] ?? ''));

        // Second call must come from the per-index cache (no request), third with refresh=true
        // must hit the API again and agree with it.
        $cached = $client->coreInfo(DEMO_INDEX);
        check($cached === $demoInfo, 'coreInfo(): second call served from the in-memory cache, identical array');
        Rate::reserve(1);
        $fresh = $client->coreInfo(DEMO_INDEX, true);
        check($fresh['connection_url'] === $demoInfo['connection_url'],
            'coreInfo(refresh:true): re-fetched from the API and agrees with the cached copy');
    });

    step('solrEndpoint()', function () use ($client, &$demoInfo) {
        // Protected — reached by reflection because it is the resolver every direct-Solr call
        // depends on, and the package deliberately keeps it internal.
        // (No setAccessible() call: it has been a no-op since PHP 8.1 and is deprecated in 8.5.)
        $m = new ReflectionMethod(OpensolrClient::class, 'solrEndpoint');
        [$base, $auth] = $m->invoke($client, DEMO_INDEX);
        check($base === ($demoInfo['connection_url'] ?? null),
            'solrEndpoint(): returns the coreInfo connection_url — ' . brief($base));
        check(is_array($auth) && $auth[0] === $demoInfo['auth_username'] && $auth[1] === $demoInfo['auth_password'],
            'solrEndpoint(): returns [username, password] taken from coreInfo');
    });

    step('solrSelect()', function () use ($client) {
        Rate::reserve(0); // direct to the index host, not the rate-limited platform API
        $all = $client->solrSelect(DEMO_INDEX, ['q' => '*:*', 'rows' => 0]);
        $total = (int) ($all['response']['numFound'] ?? 0);
        check($total >= 300, "solrSelect(): seeded index holds {$total} documents (>= 300)");

        $one = $client->solrSelect(DEMO_INDEX, ['q' => '*:*', 'rows' => 2, 'fl' => 'id,title,meta_domain']);
        $docs = $one['response']['docs'] ?? [];
        check(count($docs) === 2 && !empty($docs[0]['title']),
            'solrSelect(): rows/fl honoured — 2 docs back, first title ' . brief($docs[0]['title'] ?? ''));

        // Multi-valued params must be sent as repeated keys (fq=a&fq=b), not fq[0]=a.
        $lang = $client->solrSelect(DEMO_INDEX, ['q' => '*:*', 'rows' => 0, 'fq' => 'meta_detected_language:en']);
        $both = $client->solrSelect(DEMO_INDEX, ['q' => '*:*', 'rows' => 0,
            'fq' => ['meta_detected_language:en', 'meta_domain:newsweek.com']]);
        $n1 = (int) ($lang['response']['numFound'] ?? 0);
        $n2 = (int) ($both['response']['numFound'] ?? 0);
        check($n1 > 0 && $n2 > 0 && $n2 < $n1,
            "solrSelect(): two fq values sent as repeated keys and BOTH applied ({$n1} en → {$n2} en+newsweek.com)");
    });

    $qvec = null;
    step('embedQuery()', function () use ($client, &$qvec) {
        Rate::reserve(1);
        $qvec = $client->embedQuery(DEMO_INDEX, 'who won the tennis match');
        check(count($qvec) === 1024, 'embedQuery(): returned ' . count($qvec) . ' dimensions (expected 1024)');
        check(is_float($qvec[0]) || is_int($qvec[0]), 'embedQuery(): components are numeric — first is ' . brief($qvec[0]));
        $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $qvec)));
        check($norm > 0.1 && is_finite($norm), 'embedQuery(): vector has a finite non-zero L2 norm — ' . round($norm, 4));
    });

    step('batchEmbed()', function () use ($client) {
        Rate::reserve(1);
        $texts = ['a red bicycle leaning on a wall', 'a red bicycle leaning on a wall', 'quarterly revenue guidance'];
        $vecs = $client->batchEmbed(DEMO_INDEX, $texts);
        check(count($vecs) === 3, 'batchEmbed(): one vector per payload — ' . count($vecs) . ' back for 3 texts');
        check(count($vecs[0]) === 1024 && count($vecs[2]) === 1024,
            'batchEmbed(): each vector is 1024-dimensional');
        $same = cosine($vecs[0], $vecs[1]);
        $diff = cosine($vecs[0], $vecs[2]);
        check($same > 0.999, 'batchEmbed(): identical payloads embed identically — cos=' . round($same, 5));
        check($diff < $same - 0.05, 'batchEmbed(): unrelated payload embeds differently — cos=' . round($diff, 4));
    });

    step('embedAndSearch()', function () use ($client) {
        Rate::reserve(1);
        // $params carries per-call Search Tuning overrides on top of the index's saved settings.
        $body = $client->embedAndSearch(DEMO_INDEX, 'transfer deal agreed for a goalkeeper', 4,
            ['search_mode' => 'union', 'vector_topk' => 100]);
        check(($body['status'] ?? null) === true, 'embedAndSearch(): status true');
        $docs = $body['results']['docs'] ?? [];
        // The platform's hybrid pipeline reports the hit count as `num` (its own envelope),
        // not Solr's `numFound` — the package only consumes results.docs and results.hl.
        $numFound = (int) ($body['results']['num'] ?? $body['results']['numFound'] ?? 0);
        check(count($docs) > 0 && $numFound > 0,
            'embedAndSearch(): ' . count($docs) . ' docs of ' . $numFound . ' numFound, top: ' . brief($docs[0]['title'] ?? ''));
        $scored = array_filter($docs, fn ($d) => isset($d['score']) && (float) $d['score'] > 0);
        check(count($scored) === count($docs), 'embedAndSearch(): every hit carries a positive score');
        check(count($docs) <= 4, 'embedAndSearch(): rows=4 honoured (' . count($docs) . ' docs)');
        check(count($body['embeddings'] ?? []) === 1024, 'embedAndSearch(): the 1024-dim query vector is returned too');
    });

    $plainNumFound = 0;
    step('hybridSearch()', function () use ($client, &$plainNumFound) {
        Rate::reserve(1); // embed_query; the {!hybrid} select goes straight to Solr
        $r = $client->hybridSearch(DEMO_INDEX, 'roger federer tennis hall of fame', 5);
        $docs = $r['response']['docs'] ?? [];
        $plainNumFound = (int) ($r['response']['numFound'] ?? 0);
        check($plainNumFound > 0 && count($docs) > 0,
            "hybridSearch(): {$plainNumFound} numFound, " . count($docs) . ' docs, top: ' . brief($docs[0]['title'] ?? ''));
        check(count($docs) <= 5, 'hybridSearch(): rows=5 honoured (' . count($docs) . ' docs)');
        $scores = array_map(fn ($d) => (float) ($d['score'] ?? 0), $docs);
        check(count(array_filter($scores, fn ($s) => $s > 0)) === count($docs),
            'hybridSearch(): every hit has a positive fused score (top=' . round($scores[0] ?? 0, 4) . ')');
        $sorted = $scores;
        rsort($sorted);
        check($scores === $sorted, 'hybridSearch(): hits are ordered by descending score — ' . brief(array_map(fn ($s) => round($s, 3), $scores)));
        check(stripos(json_encode($docs), 'federer') !== false,
            'hybridSearch(): the Federer article is among the hits — retrieval is relevant');
    });

    step('hybridSearch(freshBias:true)', function () use ($client, &$plainNumFound) {
        Rate::reserve(1);
        $r = $client->hybridSearch(DEMO_INDEX, 'roger federer tennis hall of fame', 5, 'union', 0.5, '*,score', null, true);
        $docs = $r['response']['docs'] ?? [];
        $n = (int) ($r['response']['numFound'] ?? 0);
        check($n === $plainNumFound,
            "hybridSearch(freshBias): numFound unchanged — {$n} with the recency boost vs {$plainNumFound} without (re-orders, never filters)");
        check(count($docs) > 0 && (float) ($docs[0]['score'] ?? 0) > 0,
            'hybridSearch(freshBias): still returns scored hits (top=' . round((float) ($docs[0]['score'] ?? 0), 4) . ')');
    });

    step('hybridSearch(fq, fl)', function () use ($client, &$plainNumFound) {
        Rate::reserve(1);
        $r = $client->hybridSearch(DEMO_INDEX, 'donald trump politics', 5, 'union', 0.5,
            'title,meta_domain,score', 'meta_domain:newsweek.com');
        $docs = $r['response']['docs'] ?? [];
        $n = (int) ($r['response']['numFound'] ?? 0);
        check(count($docs) > 0 && $n > 0 && $n < $plainNumFound,
            "hybridSearch(fq): filter narrowed the result set to {$n} (unfiltered was {$plainNumFound})");
        $domains = array_unique(array_map(fn ($d) => (string) ($d['meta_domain'] ?? ''), $docs));
        check($domains === ['newsweek.com'],
            'hybridSearch(fq): every hit satisfies the filter — domains ' . brief($domains));
        check(isset($docs[0]['title']) && !array_key_exists('text', $docs[0] ?? []),
            'hybridSearch(fl): only the requested fields came back — ' . brief(array_keys($docs[0] ?? [])));
    });

    step('aiAnswer() — default prompt', function () use ($client) {
        Rate::reserve(2); // embed_and_search + ai_summary
        $q = 'Who did Roger Federer share a hotel room with on his first US Open trip, and who beat him in the junior singles final?';
        $a = $client->aiAnswer(DEMO_INDEX, $q);
        check($a !== '' && mb_strlen($a) > 40, 'aiAnswer(): non-empty answer, ' . mb_strlen($a) . ' chars');
        $opening = ltrim($a, "#*_ \n\r\t");
        check(stripos($opening, 'Based on') !== 0 && stripos($opening, 'According to') !== 0,
            'aiAnswer(): does not open with "Based on"/"According to" — the shipped instruction reached the model. Opens: "' . brief(mb_substr($opening, 0, 60), 60) . '"');
        check(stripos($a, 'There is no information about') === false,
            'aiAnswer(): answered instead of refusing — the context was retrieved and attached');
        $hits = foundTokens($a, ['Rochus', 'Nalbandian', 'Grand Hyatt', 'Grand Central']);
        check(count($hits) > 0,
            'aiAnswer(): grounded in the index — names only the documents carry: ' . brief($hits));
    });

    step('aiAnswer() — custom instruction (German)', function () use ($client) {
        Rate::reserve(2);
        // The instruction is English on purpose: a German answer then proves the custom
        // instruction reached the model. The specifics live in the QUESTION, so venue/time/
        // channels in the answer prove the question was attached to it as well — that branch
        // was shipping documents with no question at all until 2026-08-29.
        $q = 'Where is the Hamburger SV versus Borussia Dortmund match played, at what time does it kick off, and which TV channels and streaming services show it?';
        $a = $client->aiAnswer(DEMO_INDEX, $q, null, 4, 1500, 'Answer in German. Use only facts stated in the documents.');
        check($a !== '' && mb_strlen($a) > 40, 'aiAnswer(custom): non-empty answer, ' . mb_strlen($a) . ' chars');

        $de = countWords($a, ['und', 'nicht', 'für', 'über', 'wird', 'werden', 'ist', 'das', 'mit',
            'auf', 'sich', 'kann', 'können', 'dem', 'den', 'eine', 'einen', 'gegen', 'zwischen',
            'im', 'bei', 'Uhr', 'Spiel', 'Sender', 'sind', 'der', 'die']);
        $en = countWords($a, ['the', 'and', 'with', 'for', 'that', 'this', 'are', 'you', 'will', 'from', 'kick']);
        check($de >= 4 && $de > $en,
            "aiAnswer(custom): the answer is in German — {$de} German markers vs {$en} English ones");
        $facts = foundTokens($a, ['Signal Iduna', '12:30', 'Telemundo', 'Peacock', 'Fubo', 'USA Network']);
        check(count($facts) > 0,
            'aiAnswer(custom): grounded AND the question was attached — answers with document-only facts: ' . brief($facts));
    });

    step('aiAnswer() — filterQuery fallback path', function () use ($client) {
        Rate::reserve(2); // embed (client-side {!hybrid}) + ai_summary
        // A non-null $filterQuery skips embed_and_search and retrieves with the client-side
        // {!hybrid} query instead — a different retrieval path through the same prompt builder.
        $a = $client->aiAnswer(DEMO_INDEX, 'What is reported about Donald Trump?', 'meta_domain:newsweek.com');
        check($a !== '' && mb_strlen($a) > 40, 'aiAnswer(fq): non-empty answer via the fallback retrieval, ' . mb_strlen($a) . ' chars');
        $opening = ltrim($a, "#*_ \n\r\t");
        check(stripos($opening, 'Based on') !== 0 && stripos($opening, 'According to') !== 0,
            'aiAnswer(fq): still obeys the no-"Based on" opening rule');
        check(stripos($a, 'Trump') !== false, 'aiAnswer(fq): answer is on topic for the filtered documents');
    });

    // -----------------------------------------------------------------------------------------
    section('OpensolrClient — write path on the temporary index');
    // -----------------------------------------------------------------------------------------
    step('createIndex()', function () use ($client, $TEMP_INDEX) {
        Rate::reserve(1);
        $r = $client->createIndex($TEMP_INDEX, 'fi');
        check(($r['status'] ?? null) === true && ($r['msg'] ?? '') === 'CORE_CREATED_OK',
            "createIndex(): {$TEMP_INDEX} created — msg=" . brief($r['msg'] ?? $r));
        check(str_contains((string) ($r['core_hostname'] ?? ''), 'fi.solrcluster.com'),
            'createIndex(): the "fi" location alias resolved to the Finland cluster — ' . brief($r['core_hostname'] ?? ''));
    });

    step('coreInfo() on the new index', function () use ($client, $TEMP_INDEX) {
        Rate::reserve(1);
        $info = $client->coreInfo($TEMP_INDEX);
        check(str_contains((string) ($info['connection_url'] ?? ''), $TEMP_INDEX)
            && str_contains((string) ($info['connection_url'] ?? ''), 'fi.solrcluster.com'),
            'coreInfo(new index): ' . brief($info['connection_url'] ?? ''));
        check(($info['auth_username'] ?? '') === 'opensolr' && !empty($info['auth_password']),
            'coreInfo(new index): HTTP auth provisioned automatically (user=opensolr)');
    });

    $jobId = null;
    step('ingest()', function () use ($client, $TEMP_INDEX, &$jobId) {
        Rate::reserve(1);
        $now = time();
        $docs = [
            [
                'uri' => 'https://example.com/scout-live/sourdough',
                'title' => 'Sourdough bread baking with a wild yeast starter',
                'description' => 'How to feed a sourdough starter and bake a loaf at home.',
                'text' => 'A sourdough starter is a culture of wild yeast and lactic acid bacteria kept in flour and water. Feed it twice a day, let the dough bulk ferment, shape the loaf, and bake it in a very hot Dutch oven for a crisp crust.',
                'timestamp' => $now - 63072000, // two years old
            ],
            [
                'uri' => 'https://example.com/scout-live/telescope',
                'title' => 'The solar telescope that photographs sunspots',
                'description' => 'A ground based solar telescope resolving magnetic structures on the sun.',
                'text' => 'The telescope uses adaptive optics to freeze atmospheric turbulence and resolves convection cells and sunspot penumbrae only thirty kilometres across on the solar surface.',
                'timestamp' => $now - 3600, // brand new
            ],
            [
                'uri' => 'https://example.com/scout-live/pitstop',
                'title' => 'Anatomy of a two second Formula 1 pit stop',
                'description' => 'How twenty mechanics change four tyres in under two seconds.',
                'text' => 'The wheel guns run at high torque, the jacks lift the car front and rear simultaneously, and the lollipop man releases the driver the instant every wheel gun signals green.',
                'timestamp' => $now - 31536000, // one year old
            ],
        ];
        $r = $client->ingest($TEMP_INDEX, $docs);
        check(($r['status'] ?? null) === true && ($r['msg'] ?? '') === 'QUEUED',
            'ingest(): batch accepted — msg=' . brief($r['msg'] ?? $r));
        $jobId = $r['job_id'] ?? null;
        check(is_string($jobId) && preg_match('/^[0-9a-f]{32}$/', $jobId) === 1,
            'ingest(): returned a job id — ' . brief($jobId));
        check((int) ($r['total_docs'] ?? 0) === 3, 'ingest(): all 3 documents queued');
        check(count($r['doc_ids'] ?? []) === 3 && $r['doc_ids'][0] === md5('https://example.com/scout-live/sourdough'),
            'ingest(): document ids are md5(uri) as documented');
    });

    step('ingestStatus()', function () use ($client, $TEMP_INDEX, &$jobId) {
        if (!$jobId) {
            bad('ingestStatus(): skipped — no job id from ingest()');

            return;
        }
        Rate::reserve(1);
        $st = $client->ingestStatus($jobId);
        check(($st['status'] ?? null) === true, 'ingestStatus(): status true');
        $job = $st['job'] ?? [];
        check(($job['core_name'] ?? '') === $TEMP_INDEX,
            'ingestStatus(): job belongs to the temp index — ' . brief($job['core_name'] ?? ''));
        check((int) ($job['total_docs'] ?? 0) === 3, 'ingestStatus(): total_docs = 3');
        check(in_array((int) ($job['state'] ?? 99), [-1, 0, 1], true),
            'ingestStatus(): state is pending/processing/completed — ' . brief($job['state_label'] ?? $job['state'] ?? '?'));
    });

    step('async ingestion becomes searchable', function () use ($client, $TEMP_INDEX) {
        [$reached, $found, $secs] = waitForDocs($client, $TEMP_INDEX, 3, null, INGEST_DEADLINE);
        check($reached, $reached
            ? "ingestion: all 3 documents searchable after {$secs}s (polled the live document count)"
            : "ingestion: only {$found} of 3 documents searchable after {$secs}s — gave up (deadline " . INGEST_DEADLINE . 's)');
    });

    step('server-side embeddings + hybridSearch on the new index', function () use ($client, $TEMP_INDEX) {
        Rate::reserve(1);
        // Nothing in this query overlaps the bread document lexically except "bread": if the
        // semantic leg were missing (no embeddings computed at ingest) the {!knn} clause would
        // error or return nothing. Ranking it first proves the ingestion API embedded the docs.
        $r = $client->hybridSearch($TEMP_INDEX, 'how do I keep a wild yeast culture alive for baking?', 3, 'union', 0.5, 'title,score');
        $docs = $r['response']['docs'] ?? [];
        check(count($docs) > 0, 'hybridSearch(temp): ' . count($docs) . ' hits back');
        check(stripos((string) ($docs[0]['title'] ?? ''), 'Sourdough') === 0,
            'hybridSearch(temp): the semantically matching document ranks first — ' . brief($docs[0]['title'] ?? ''));
        check((float) ($docs[0]['score'] ?? 0) > 0, 'hybridSearch(temp): top hit scored ' . round((float) ($docs[0]['score'] ?? 0), 4));
    });

    step('solrUpdate()', function () use ($client, $TEMP_INDEX) {
        Rate::reserve(0); // direct to the index host
        $add = $client->solrUpdate($TEMP_INDEX, ['add' => ['doc' => [
            'id' => 'scout_live_manual_doc',
            'uri' => 'https://example.com/scout-live/manual',
            'title' => 'Manually added document',
            'description' => 'Added through solrUpdate to prove the write path.',
            'text' => 'This document was written directly to Solr by the package client.',
        ]]]);
        check((int) ($add['responseHeader']['status'] ?? -1) === 0,
            'solrUpdate(add): Solr accepted the document (responseHeader.status=0)');
        $n = (int) ($client->solrSelect($TEMP_INDEX, ['q' => 'id:scout_live_manual_doc', 'rows' => 0])['response']['numFound'] ?? 0);
        check($n === 1, "solrUpdate(add): the document is searchable immediately (commit=true) — numFound={$n}");

        $del = $client->solrUpdate($TEMP_INDEX, ['delete' => ['query' => 'id:scout_live_manual_doc']]);
        check((int) ($del['responseHeader']['status'] ?? -1) === 0, 'solrUpdate(delete): Solr accepted the delete-by-query');
        $n2 = (int) ($client->solrSelect($TEMP_INDEX, ['q' => 'id:scout_live_manual_doc', 'rows' => 0])['response']['numFound'] ?? 0);
        check($n2 === 0, "solrUpdate(delete): the document is gone — numFound={$n2}");
    });

    // -----------------------------------------------------------------------------------------
    section('OpensolrEngine — the Scout engine against the temporary index');
    // -----------------------------------------------------------------------------------------
    $engine = new OpensolrEngine(
        client: $client,
        index: $TEMP_INDEX,
        hybrid: true,
        alpha: 0.5,
        softDelete: false,
        mode: 'hybrid',
        ingestWait: true,   // blocks on the ingestion job, exercising the client's wait branch
        freshBias: false,
    );

    // Rows the stub "table" holds. Distinct topics so semantic ranking is provable, distinct
    // ages so Fresh Results Bias has something to re-order.
    $now = time();
    LiveModel::$rows = [
        '11' => ['title' => 'Alpine trail running shoes', 'description' => 'Grip and drainage on wet granite.',
            'body' => 'A trail shoe for steep alpine descents needs a sticky rubber outsole, a rock plate and drainage ports so it sheds water after every stream crossing.',
            'category' => 'gear', 'timestamp' => $now - 63072000],
        '22' => ['title' => 'Fermenting kimchi at home', 'description' => 'Salt, napa cabbage and gochugaru.',
            'body' => 'Salt the napa cabbage, rinse it, mix gochugaru with garlic and fish sauce, pack the jar tightly and let it ferment at room temperature for three days before refrigerating.',
            'category' => 'food', 'timestamp' => $now - 60],
        '33' => ['title' => 'Choosing a first sailing dinghy', 'description' => 'Stability versus speed for a beginner.',
            'body' => 'A beginner dinghy should be forgiving: a wide beam, a small mainsail and a centreboard that kicks up when it touches the bottom of the lake.',
            'category' => 'gear', 'timestamp' => $now - 31536000],
    ];
    $models = new Collection(array_map(fn ($k) => new LiveModel($k), array_keys(LiveModel::$rows)));
    $stub = new LiveModel();

    step('engine->createIndex()', function () use ($engine, $TEMP_INDEX) {
        Rate::reserve(1);
        // The engine ignores the name Scout passes and always uses the configured index — proven
        // here by the platform rejecting the name as taken (the temp index already exists).
        try {
            $engine->createIndex('a_name_scout_made_up');
            bad('engine->createIndex(): expected the configured index name to be used, but the call succeeded');
        } catch (RuntimeException $e) {
            check(str_contains($e->getMessage(), 'CORE_NAME_TAKEN'),
                'engine->createIndex(): forwards the CONFIGURED index name (' . $TEMP_INDEX . '), not Scout\'s argument — ' . brief($e->getMessage(), 70));
        }
    });

    step('engine->update() — ingestion through Scout', function () use ($engine, $client, $models, $TEMP_INDEX) {
        Rate::reserve(16); // 1 ingest + the wait loop's ingest_status polls (5s apart)
        $t0 = microtime(true);
        $engine->update($models);
        $secs = round(microtime(true) - $t0, 1);
        ok("engine->update(): ingested 3 models and blocked until the job completed ({$secs}s, ingestWait=true)");

        [$reached, $found, $waited] = waitForDocs($client, $TEMP_INDEX, 3, 'meta_model:"scout_live_posts"', 60);
        check($reached, $reached
            ? "engine->update(): 3 documents carry meta_model=scout_live_posts (visible after {$waited}s)"
            : "engine->update(): only {$found} of 3 model documents are searchable after {$waited}s");

        $doc = $client->solrSelect($TEMP_INDEX, ['q' => 'meta_scout_key:"22"', 'rows' => 1,
            'fl' => 'title,description,meta_model,meta_scout_key,meta_category,meta_lc_json,uri'])['response']['docs'][0] ?? [];
        check(($doc['title'] ?? '') === 'Fermenting kimchi at home',
            'engine->update(): title taken from the searchable array — ' . brief($doc['title'] ?? ''));
        check(($doc['meta_model'] ?? '') === 'scout_live_posts' && ($doc['meta_scout_key'] ?? '') === '22',
            'engine->update(): meta_model / meta_scout_key written for scoping and mapping');
        check(($doc['meta_category'] ?? '') === 'food',
            'engine->update(): scalar attributes became filterable meta_* fields — meta_category=' . brief($doc['meta_category'] ?? ''));
        check(str_contains((string) ($doc['uri'] ?? ''), 'scout_live_posts__22'),
            'engine->update(): deterministic ingestion uri — ' . brief($doc['uri'] ?? ''));
        $lc = json_decode((string) ($doc['meta_lc_json'] ?? ''), true);
        check(is_array($lc) && ($lc['category'] ?? '') === 'food',
            'engine->update(): meta_lc_json round-trips the full searchable array');
    });

    $results = null;
    step('engine->search() + getTotalCount() + mapIds() + map() + lazyMap()', function () use ($engine, $stub, &$results) {
        Rate::reserve(0); // query '*' takes the *:* branch — no embedding call at all
        $builder = new Builder($stub, '*');
        $results = $engine->search($builder);
        $docs = $results['response']['docs'] ?? [];
        check(count($docs) === 3, 'engine->search(): 3 hits, scoped to meta_model by the engine (' . count($docs) . ')');
        $models = array_unique(array_map(fn ($d) => (string) ($d['meta_model'] ?? ''), $docs));
        check($models === ['scout_live_posts'], 'engine->search(): every hit belongs to this model — ' . brief($models));
        check(isset($docs[0]['score']), 'engine->search(): fl=*,score — hits carry scores (' . brief($docs[0]['score'] ?? '') . ')');

        $total = $engine->getTotalCount($results);
        check($total === 3, "engine->getTotalCount(): {$total} (matches numFound)");

        $ids = $engine->mapIds($results);
        check($ids instanceof Collection && $ids->count() === 3,
            'engine->mapIds(): returned a Collection of 3 scout keys — ' . brief($ids->all()));
        check(array_diff(['11', '22', '33'], $ids->all()) === [],
            'engine->mapIds(): the keys are the model keys that were indexed');

        // getScoutModelsByIds hands them back REVERSED; map() must restore the search order.
        $mapped = $engine->map(new Builder($stub, '*'), $results, $stub);
        check($mapped instanceof Collection && $mapped->count() === 3,
            'engine->map(): hydrated 3 models (' . $mapped->count() . ')');
        check($mapped->map(fn ($m) => $m->getScoutKey())->all() === $ids->all(),
            'engine->map(): models come back in Solr rank order, not the order the "database" returned them');

        $lazy = $engine->lazyMap(new Builder($stub, '*'), $results, $stub);
        check($lazy instanceof LazyCollection && $lazy->count() === 3,
            'engine->lazyMap(): LazyCollection with the same 3 models');
        check($lazy->map(fn ($m) => $m->getScoutKey())->all() === $ids->all(),
            'engine->lazyMap(): same order as map()');

        // Empty result set must not explode and must not hit the database.
        $none = $engine->map(new Builder($stub, '*'), ['response' => ['docs' => []]], $stub);
        check($none instanceof Collection && $none->isEmpty(), 'engine->map(): empty results give an empty collection');
        check($engine->getTotalCount([]) === 0, 'engine->getTotalCount(): 0 for a malformed/empty response');
    });

    step('engine->search() — hybrid branch, limit, wheres and whereIns', function () use ($engine, $stub) {
        Rate::reserve(1); // a real query embeds server-side, then runs {!hybrid}
        $b = new Builder($stub, 'spicy fermented vegetables in a jar');
        $b->take(2);
        $r = $engine->search($b);
        $docs = $r['response']['docs'] ?? [];
        check(count($docs) === 2, 'engine->search(): take(2) honoured — ' . count($docs) . ' docs');
        check(($docs[0]['meta_scout_key'] ?? '') === '22',
            'engine->search(): hybrid semantic ranking put the kimchi model first — key ' . brief($docs[0]['meta_scout_key'] ?? ''));

        Rate::reserve(0);
        $bw = new Builder($stub, '*');
        $bw->where('category', 'gear');
        $rw = $engine->search($bw);
        $keys = array_map(fn ($d) => (string) $d['meta_scout_key'], $rw['response']['docs'] ?? []);
        sort($keys);
        check($keys === ['11', '33'], 'engine->search(): where(category, gear) filtered to the 2 gear models — ' . brief($keys));

        $bn = new Builder($stub, '*');
        $bn->where('category', '!=', 'gear');
        $rn = $engine->search($bn);
        $nkeys = array_map(fn ($d) => (string) $d['meta_scout_key'], $rn['response']['docs'] ?? []);
        check($nkeys === ['22'], 'engine->search(): where(category, !=, gear) negated the filter — ' . brief($nkeys));

        $bi = new Builder($stub, '*');
        $bi->whereIn('category', ['gear', 'food']);
        $ri = $engine->search($bi);
        check($engine->getTotalCount($ri) === 3,
            'engine->search(): whereIn(category, [gear, food]) matched all 3 — ' . $engine->getTotalCount($ri));
    });

    step('engine search modes — lexical and vector-only', function () use ($client, $stub, $TEMP_INDEX) {
        Rate::reserve(0);
        // mode=lexical: pure edismax, no embedding call at all (zero AI quota).
        $lex = new OpensolrEngine(client: $client, index: $TEMP_INDEX, mode: 'lexical');
        $rl = $lex->search(new Builder($stub, 'kimchi'));
        $lkeys = array_map(fn ($d) => (string) $d['meta_scout_key'], $rl['response']['docs'] ?? []);
        check($lkeys === ['22'], 'engine(mode:lexical): keyword-only search matched just the kimchi model — ' . brief($lkeys));

        Rate::reserve(1); // vector-only still embeds the query server-side
        // hybrid:false drops the lexical leg: the query becomes a bare {!knn}.
        $vec = new OpensolrEngine(client: $client, index: $TEMP_INDEX, hybrid: false);
        $rv = $vec->search(new Builder($stub, 'preserving vegetables by lacto fermentation'));
        $vdocs = $rv['response']['docs'] ?? [];
        check(count($vdocs) === 3, 'engine(hybrid:false): bare {!knn} still scoped to the model — ' . count($vdocs) . ' hits');
        check((string) ($vdocs[0]['meta_scout_key'] ?? '') === '22' && (float) ($vdocs[0]['score'] ?? 0) > 0,
            'engine(hybrid:false): nearest neighbour is the kimchi model, score ' . round((float) ($vdocs[0]['score'] ?? 0), 4));
    });

    step('engine->update() — no-op paths', function () use ($engine, $client, $TEMP_INDEX) {
        Rate::reserve(0); // both branches must return before any HTTP call is made
        $engine->update(new Collection([]));
        // A model whose searchable array is empty is skipped, leaving nothing to ingest.
        $engine->update(new Collection([new LiveModel('no_such_row')]));
        $n = (int) ($client->solrSelect($TEMP_INDEX, ['q' => '*:*', 'rows' => 0])['response']['numFound'] ?? -1);
        check($n === 6, "engine->update(): empty collection and empty searchable array are no-ops — still {$n} documents");
    });

    step('engine->paginate()', function () use ($engine, $stub) {
        Rate::reserve(0);
        $b = new Builder($stub, '*');
        $p1 = $engine->paginate($b, 2, 1);
        $p2 = $engine->paginate($b, 2, 2);
        $k1 = array_map(fn ($d) => (string) $d['meta_scout_key'], $p1['response']['docs'] ?? []);
        $k2 = array_map(fn ($d) => (string) $d['meta_scout_key'], $p2['response']['docs'] ?? []);
        check(count($k1) === 2, 'engine->paginate(perPage 2, page 1): 2 documents — ' . brief($k1));
        check(count($k2) === 1, 'engine->paginate(perPage 2, page 2): the remaining 1 document — ' . brief($k2));
        check(array_intersect($k1, $k2) === [], 'engine->paginate(): pages do not overlap (start offset applied)');
        check($engine->getTotalCount($p1) === 3 && $engine->getTotalCount($p2) === 3,
            'engine->paginate(): total stays 3 on both pages');
    });

    step('engine freshBias constructor flag', function () use ($client, $stub, $TEMP_INDEX) {
        Rate::reserve(0);
        // Same query, same index, only the flag differs. The '*' branch gives every document the
        // same constant score, so any re-ordering is the recency multiplier alone.
        $plain = new OpensolrEngine(client: $client, index: $TEMP_INDEX, freshBias: false);
        $fresh = new OpensolrEngine(client: $client, index: $TEMP_INDEX, freshBias: true);
        $rp = $plain->search(new Builder($stub, '*'));
        $rf = $fresh->search(new Builder($stub, '*'));
        check($plain->getTotalCount($rp) === $fresh->getTotalCount($rf) && $fresh->getTotalCount($rf) === 3,
            'engine(freshBias): numFound unchanged — ' . $plain->getTotalCount($rp) . ' vs ' . $fresh->getTotalCount($rf) . ' (re-orders, never filters)');
        $first = (string) ($rf['response']['docs'][0]['meta_scout_key'] ?? '');
        check($first === '22',
            'engine(freshBias): the newest document (kimchi, 1 minute old) ranks first — key ' . brief($first));
        $ordered = array_map(fn ($d) => (string) $d['meta_scout_key'], $rf['response']['docs'] ?? []);
        check($ordered === ['22', '33', '11'],
            'engine(freshBias): full order is newest → oldest (1min, 1yr, 2yr) — ' . brief($ordered));
    });

    step('engine->aiAnswer()', function () use ($engine) {
        Rate::reserve(2);
        $a = $engine->aiAnswer('What has to happen to the napa cabbage before it is packed into the jar?');
        check($a !== '' && mb_strlen($a) > 20, 'engine->aiAnswer(): non-empty answer from the Scout index, ' . mb_strlen($a) . ' chars');
        $opening = ltrim($a, "#*_ \n\r\t");
        check(stripos($opening, 'Based on') !== 0 && stripos($opening, 'According to') !== 0,
            'engine->aiAnswer(): obeys the no-"Based on" opening rule');
        $hits = foundTokens($a, ['salt', 'rinse', 'gochugaru', 'cabbage']);
        check(count($hits) > 0, 'engine->aiAnswer(): grounded in the indexed models — ' . brief($hits));
    });

    step('engine->delete()', function () use ($engine, $client, $TEMP_INDEX) {
        Rate::reserve(0);
        $engine->delete(new Collection([new LiveModel('33')]));
        $n = (int) ($client->solrSelect($TEMP_INDEX, ['q' => 'meta_model:"scout_live_posts"', 'rows' => 0])['response']['numFound'] ?? -1);
        check($n === 2, "engine->delete(): one model removed by (meta_model AND meta_scout_key) — 3 → {$n}");
        $gone = (int) ($client->solrSelect($TEMP_INDEX, ['q' => 'meta_scout_key:"33"', 'rows' => 0])['response']['numFound'] ?? -1);
        check($gone === 0, 'engine->delete(): the right document went — meta_scout_key 33 is gone');

        // An empty collection must be a no-op, not a delete-everything query.
        $engine->delete(new Collection([]));
        $still = (int) ($client->solrSelect($TEMP_INDEX, ['q' => 'meta_model:"scout_live_posts"', 'rows' => 0])['response']['numFound'] ?? -1);
        check($still === 2, "engine->delete([]): no-op on an empty collection — still {$still} documents");
    });

    step('engine->flush()', function () use ($engine, $client, $TEMP_INDEX) {
        Rate::reserve(0);
        $engine->flush(LiveModel::class);
        $n = (int) ($client->solrSelect($TEMP_INDEX, ['q' => 'meta_model:"scout_live_posts"', 'rows' => 0])['response']['numFound'] ?? -1);
        check($n === 0, "engine->flush(): every document of the model removed — numFound={$n}");
        $others = (int) ($client->solrSelect($TEMP_INDEX, ['q' => '*:*', 'rows' => 0])['response']['numFound'] ?? -1);
        check($others === 3, "engine->flush(): the 3 non-Scout documents were left alone — numFound={$others}");
    });

    step('engine->deleteIndex()', function () use ($engine) {
        Rate::reserve(0);
        $r = $engine->deleteIndex('anything');
        check($r === null,
            'engine->deleteIndex(): returns null — index deletion is deliberately not done from code');
    });
} finally {
    section('cleanup');
    cleanupTempIndex($TEMP_INDEX);
}

// ---------------------------------------------------------------------------------------------
echo "\n{$PASS} passed, {$FAIL} failed\n";
exit($FAIL > 0 ? 1 : 0);
