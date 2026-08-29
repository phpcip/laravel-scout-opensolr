<?php

namespace Opensolr\ScoutOpensolr;

use GuzzleHttp\Client as Guzzle;
use RuntimeException;

/**
 * Thin REST client for the Opensolr platform.
 *
 * Management API lives on opensolr.com, AI API (embeddings) on
 * api.opensolr.com. Direct Solr access (select/update) goes to the index's
 * own host, resolved via get_core_info (connection_url + HTTP basic auth).
 */
class OpensolrClient
{
    protected const MGMT_BASE = 'https://opensolr.com/solr_manager/api';
    protected const AI_BASE = 'https://api.opensolr.com/solr_manager/api';
    protected const BATCH_EMBED_MAX = 50;

    /**
     * The default RAG instruction, re-exported from AiPrompt.
     *
     * It used to be an inline string inside aiAnswer(), which is how it drifted out of step with
     * the platform and the sibling packages without anyone noticing: nothing outside the method
     * body could name it, so no test could assert parity on it. The four Python packages each
     * expose theirs at module scope; this is the PHP equivalent, aliased rather than copied so
     * there is still exactly one definition (AiPrompt::DEFAULT_RAG_INSTRUCTION).
     */
    public const DEFAULT_RAG_INSTRUCTION = AiPrompt::DEFAULT_RAG_INSTRUCTION;

    /**
     * Generation temperature for the default prompt.
     *
     * 0.1, because every candidate phrasing behind that prompt was scored at 0.1 — the wording
     * and the temperature were measured together and only hold together.
     */
    protected const DEFAULT_RAG_TEMPERATURE = '0.1';

    /**
     * Fresh Results Bias — recency as a MULTIPLICATIVE score boost on creation_date.
     *
     * The single definition for this package: OpensolrEngine::performSearch() reads it from
     * here rather than writing the string out again, so the two can never disagree. Copied
     * byte for byte from the platform's own Hybrid_search::FRESH_BIAS_FUNCTION, which is what
     * makes a query built client-side and the same query built server-side rank identically —
     * change it on the platform and change it here.
     *
     * 3.16e-11 is 1/(one year in ms): the multiplier is 1.0 for a document published today,
     * 0.5 at a year old, 0.33 at two. max(0, ...) is a crash guard rather than a tuning choice —
     * a creation_date in the future (bad metadata off a crawled page is common) makes ms()
     * negative, and far enough negative the reciprocal divides by zero and Solr fails the
     * whole query.
     *
     * It re-orders and never filters: numFound is unchanged, and a document with no
     * creation_date is simply left unboosted rather than dropped. Public because the engine
     * lives in its own class and is the one place that applies it.
     */
    public const FRESH_BIAS_FUNCTION = 'recip(max(0,ms(NOW,creation_date)),3.16e-11,1,1)';

    /**
     * Default Fresh Results Bias strength when the app does not set one.
     */
    public const FRESH_BIAS_WEIGHT_DEFAULT = 0.5;

    /**
     * Build the recency function for a 0.0-1.0 weight.
     *
     * Mirrors Hybrid_search::fresh_bias_function() on opensolr.com and the same helper in the
     * Drupal module, the WordPress plugin and the four Python clients. recip(ms, c, 1, 1)
     * halves at ms = 1/c, so the weight is a HALF-LIFE on a geometric scale: 365 days at 0.0,
     * 9.6 days at 0.5, 6 hours at 1.0. Apps configure the weight, never the constant.
     *
     * @param float|null $weight 0.0-1.0, or null for the default.
     */
    public static function freshBiasFunction(?float $weight = null): string
    {
        $w = ($weight === null) ? self::FRESH_BIAS_WEIGHT_DEFAULT : (float) $weight;
        $w = max(0.0, min(1.0, $w));
        $halfLifeDays = 365.0 * pow(0.25 / 365.0, $w);
        $c = sprintf('%.4g', 1.0 / ($halfLifeDays * 86400000.0));

        return 'recip(max(0,ms(NOW,creation_date)),' . $c . ',1,1)';
    }

    /**
     * The four candidate-selection modes the {!hybrid} parser understands.
     *
     * Validated rather than trusted, because the failure is silent: the mode is interpolated
     * into the {!hybrid} local params and the Solr plugin does not reject a value it does not
     * know — it falls back to union. A one-letter typo therefore returned the union result set
     * with no error anywhere (measured 2026-08-29 on the Python twin: 18 hits where
     * intersection returns 2). Same list, same reason, in all five packages.
     */
    public const HYBRID_MODES = ['union', 'keywords_required', 'meaning_required', 'intersection'];

    protected Guzzle $http;

    /** @var array<string, array> per-index core info cache */
    protected array $coreInfo = [];

    public function __construct(
        protected string $email,
        protected string $apiKey,
        int $timeout = 120,
    ) {
        $this->http = new Guzzle(['timeout' => $timeout]);
    }

    protected function request(string $base, string $method, array $params = []): mixed
    {
        $response = $this->http->post("{$base}/{$method}", [
            'form_params' => array_merge(
                ['email' => $this->email, 'api_key' => $this->apiKey],
                $params
            ),
            'http_errors' => false,
        ]);
        $body = json_decode((string) $response->getBody(), true);
        if (is_array($body) && ($body['status'] ?? null) === false) {
            throw new RuntimeException("Opensolr {$method}: " . json_encode($body['msg'] ?? $body));
        }
        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException("Opensolr {$method}: HTTP " . $response->getStatusCode());
        }

        return $body;
    }

    public function coreInfo(string $index, bool $refresh = false): array
    {
        if (!$refresh && isset($this->coreInfo[$index])) {
            return $this->coreInfo[$index];
        }
        $body = $this->request(self::MGMT_BASE, 'get_core_info', ['core_name' => $index]);
        $info = $body['msg']['info'] ?? null;
        if (!is_array($info)) {
            throw new RuntimeException("Opensolr get_core_info({$index}): unexpected response");
        }

        return $this->coreInfo[$index] = $info;
    }

    public function createIndex(string $index, string $location = 'CHICAGO-96'): array
    {
        $aliases = ['us' => 'CHICAGO-96', 'de' => 'DE-SOLR-9', 'fi' => 'FINLAND9'];
        $env = $aliases[strtolower($location)] ?? $location;

        // create_index reads its params from the query string (GET) server-side
        $response = $this->http->post(self::MGMT_BASE . '/create_index', [
            'query' => ['index_name' => $index, 'core_type' => 'generic', 'server_country' => $env],
            'form_params' => ['email' => $this->email, 'api_key' => $this->apiKey],
            'http_errors' => false,
        ]);
        $body = json_decode((string) $response->getBody(), true);
        if (is_array($body) && ($body['status'] ?? null) === false) {
            throw new RuntimeException('Opensolr create_index: ' . json_encode($body['msg'] ?? $body));
        }

        return is_array($body) ? $body : [];
    }

    /** @return array<int, array<float>> */
    public function batchEmbed(string $index, array $texts): array
    {
        $out = [];
        foreach (array_chunk($texts, self::BATCH_EMBED_MAX) as $chunk) {
            $response = $this->http->post(self::AI_BASE . '/batch_embed', [
                'json' => [
                    'email' => $this->email,
                    'api_key' => $this->apiKey,
                    'index_name' => $index,
                    'payloads' => array_values($chunk),
                ],
                'http_errors' => false,
            ]);
            $body = json_decode((string) $response->getBody(), true);
            $embeddings = $body['embeddings'] ?? null;
            if (!is_array($embeddings) || count($embeddings) !== count($chunk)) {
                throw new RuntimeException('Opensolr batch_embed: unexpected response: ' . substr((string) $response->getBody(), 0, 200));
            }
            $out = array_merge($out, $embeddings);
        }

        return $out;
    }


    /**
     * Queue documents through the Opensolr Data Ingestion API (async).
     * Embeddings and derived fields are computed server-side. Max 50/batch.
     * With $wait=true, polls ingestStatus until the job completes.
     */
    public function ingest(string $index, array $documents, bool $wait = false, int $timeout = 180): array
    {
        $response = $this->http->post(self::AI_BASE . '/ingest', [
            'json' => [
                'email' => $this->email,
                'api_key' => $this->apiKey,
                'core_name' => $index,
                'documents' => $documents,
            ],
            'http_errors' => false,
        ]);
        $body = json_decode((string) $response->getBody(), true);
        if (!is_array($body) || ($body['status'] ?? null) === false) {
            throw new RuntimeException('Opensolr ingest: ' . substr((string) $response->getBody(), 0, 300));
        }
        if ($wait && !empty($body['job_id'])) {
            $deadline = time() + $timeout;
            while (time() < $deadline) {
                $st = $this->ingestStatus($body['job_id']);
                $state = (int) ($st['job']['state'] ?? 0);
                if ($state === 1) {
                    return $body;
                }
                if (in_array($state, [3, 4], true)) {
                    throw new RuntimeException('Opensolr ingest job failed/stopped: ' . json_encode($st['job'] ?? []));
                }
                sleep(5);
            }
            throw new RuntimeException("Opensolr ingest: job {$body['job_id']} not completed within {$timeout}s");
        }

        return $body;
    }

    public function ingestStatus(string $jobId): array
    {
        return $this->request(self::AI_BASE, 'ingest_status', ['job_id' => $jobId]) ?? [];
    }

    /** @return array<float> */
    public function embedQuery(string $index, string $text): array
    {
        $response = $this->http->post(self::AI_BASE . '/embed', [
            'form_params' => [
                'email' => $this->email,
                'api_key' => $this->apiKey,
                'index_name' => $index,
                'payload' => $text,
                'is_query' => '1',
            ],
            'http_errors' => false,
        ]);
        $body = json_decode((string) $response->getBody(), true);
        if (!is_array($body) || count($body) < 100) {
            throw new RuntimeException('Opensolr embed: unexpected response: ' . substr((string) $response->getBody(), 0, 200));
        }

        return $body;
    }

    /** @return array{0: string, 1: array{0: string, 1: string}|null} [baseUrl, auth] */
    protected function solrEndpoint(string $index): array
    {
        $info = $this->coreInfo($index);
        $url = $info['connection_url'] ?? null;
        if (!$url) {
            throw new RuntimeException("Opensolr: no connection_url for {$index}");
        }
        $auth = null;
        if (!empty($info['auth_username'])) {
            $auth = [$info['auth_username'], $info['auth_password'] ?? ''];
        }

        return [$url, $auth];
    }

    public function solrSelect(string $index, array $params): array
    {
        [$base, $auth] = $this->solrEndpoint($index);
        // Solr expects repeated keys for multi-valued params (fq=a&fq=b);
        // http_build_query would produce fq[0]=a&fq[1]=b, which Solr ignores.
        $pairs = [];
        foreach (array_merge(['wt' => 'json'], $params) as $key => $value) {
            foreach ((array) $value as $v) {
                $pairs[] = rawurlencode($key) . '=' . rawurlencode((string) $v);
            }
        }
        $response = $this->http->post("{$base}/select", [
            'body' => implode('&', $pairs),
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'auth' => $auth,
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    public function solrUpdate(string $index, mixed $payload): array
    {
        [$base, $auth] = $this->solrEndpoint($index);
        $response = $this->http->post("{$base}/update?commit=true", [
            'json' => $payload,
            'auth' => $auth,
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    /**
     * Server-side one-shot: embed the query, run the platform's tuned hybrid
     * search (same machinery as the hosted search UI), return ranked docs.
     *
     * $params carries the per-call Search Tuning overrides on top of the index's saved
     * settings: fw_title, fw_description, fw_uri, fw_text, fw_text_t, lexical_weight,
     * vector_weight, vector_topk, search_mode (union / keywords_required /
     * meaning_required / intersection), quality_boost, min_score, freshness_boost,
     * fresh_bias, lexical_norm_k, mm (flexible / balanced / strict or raw Solr mm syntax).
     *
     * freshness_boost and fresh_bias are two different knobs whose names invite exactly
     * the confusion this note exists to prevent. freshness_boost is a hard window in DAYS:
     * anything older is filtered out and numFound drops. fresh_bias filters nothing — it
     * multiplies each score by a recency curve on creation_date, so recent documents win
     * ties and near-ties while everything older stays reachable, and a document with no
     * creation_date is simply left unboosted. Pass fresh_bias => 1 (the server also accepts
     * 'yes'/'true'/'on'); it is off unless asked for.
     */
    public function embedAndSearch(string $index, string $query, int $rows = 10, array $params = []): array
    {
        $body = $this->request(self::AI_BASE, 'embed_and_search', array_merge([
            'index_name' => $index,
            'q' => $query,
            'rows' => $rows,
            'in' => 'all',
            'fresh' => 'no',
        ], $params));

        return is_array($body) ? $body : [];
    }

    /**
     * Hybrid (BM25 + kNN) search via the native {!hybrid} parser. The query
     * is embedded server-side; scores are fused per document on the Solr side.
     */
    public function hybridSearch(
        string $index,
        string $query,
        int $rows = 5,
        string $mode = 'union',
        float $alpha = 0.5,
        string $fl = '*,score',
        ?string $fq = null,
        bool $freshBias = false,
    ): array {
        // Validate BEFORE embedding. Both checks are local and free; embedQuery() is a billed
        // GPU round-trip against the account's AI quota, so rejecting the caller's own typo
        // afterwards charged them for our argument validation.
        if (!in_array($mode, self::HYBRID_MODES, true)) {
            throw new RuntimeException(
                'mode must be one of ' . implode(', ', self::HYBRID_MODES) . " — got '{$mode}'"
            );
        }
        if ($alpha < 0.0 || $alpha > 1.0) {
            throw new RuntimeException("alpha must be between 0 and 1 — got {$alpha}");
        }

        $clean = str_replace(['{', '}', '"'], ' ', $query);
        $vector = $this->embedQuery($index, $query);
        $compact = json_encode($vector);
        $topN = max($rows, 10);
        $params = [
            'q' => "{!hybrid lexical=\$lexicalRaw vector=\$vectorQuery mode={$mode} alpha={$alpha} topN={$topN}}",
            'lexicalRaw' => '{!edismax qf="title^100 text^1"}' . $clean,
            'vectorQuery' => "{!knn f=embeddings topK={$topN}}" . $compact,
            'rows' => $rows,
            'fl' => $fl,
        ];
        if ($fq !== null) {
            $params['fq'] = $fq;
        }
        // Fresh Results Bias wraps the FUSED query so the recency multiplier reaches every
        // candidate, the vector-only ones included — an edismax bf would only ever touch the
        // lexical sub-query. The inner query moves into its own parameter and is referenced by
        // v=$..., so a '}' in the user's text cannot close the {!boost} block. Same shape as
        // OpensolrEngine::performSearch() and as the four Python clients.
        if ($freshBias) {
            $params['freshBias'] = self::FRESH_BIAS_FUNCTION;
            $params['freshBiasInner'] = $params['q'];
            $params['q'] = '{!boost b=$freshBias v=$freshBiasInner}';
        }

        return $this->solrSelect($index, $params);
    }

    /**
     * Grounded RAG answer generated only from the index's own content.
     *
     * Two-step pattern: hybrid retrieval picks the top $ragDocs hits (first
     * $ragWords words of text each), which AiPrompt turns into the fenced,
     * numbered context and the prompt around it — the same pipeline, and the
     * same bytes, as Opensolr's hosted search UI. The index's saved Search
     * Tuning (Control Panel) applies automatically; $tuning overrides any knob
     * per call (fw_title, lexical_weight, search_mode, mm, vector_topk,
     * quality_boost, freshness_boost, fresh_bias, ... — the full list is on
     * embedAndSearch() above, along with why freshness_boost, which filters by a
     * date window, and fresh_bias, which only re-orders, are not the same knob).
     * Pass $instruction to fully control the prompt. Returns plain text.
     *
     * $ragDocs is four to match the platform. It was three here alone, which
     * meant this package answered from one document fewer than every other
     * door onto the same index — and the instruction tells the model how many
     * documents it was given, so the count has to be the real one.
     */
    public function aiAnswer(
        string $index,
        string $query,
        ?string $filterQuery = null,
        int $ragDocs = 4,
        int $ragWords = 1500,
        ?string $instruction = null,
        array $tuning = [],
    ): string {
        // Retrieval via the platform's tuned server-side hybrid pipeline
        // (embed_and_search); client-side {!hybrid} when a custom fq is set.
        $hits = [];
        $hl = [];
        try {
            if ($filterQuery === null) {
                try {
                    $body = $this->embedAndSearch($index, $query, $ragDocs, $tuning);
                    $hits = $body['results']['docs'] ?? [];
                    // Highlight fragments, keyed by document id.
                    $hl = $body['results']['hl'] ?? [];
                } catch (RuntimeException) {
                    $hits = [];
                }
            }
            if ($hits === []) {
                // text_t is requested alongside text because the context builder concatenates the
                // two. Asking only for 'title,description,text' meant the fallback path could
                // never see a text_t field at all, so its 50-byte test was dead code and this
                // path silently handed the model a thinner context than the primary one — the
                // exact kind of divergence the golden fixture exists to catch. The Python twins
                // ask for all four fields; so does this one now (2026-08-29).
                $body = $this->hybridSearch($index, $query, $ragDocs, 'union', 0.5, 'title,description,text,text_t', $filterQuery);
                $hits = $body['response']['docs'] ?? [];
                // The fallback runs no highlighting component, so there are no fragments to pair
                // with these documents — drop anything the primary attempt may have left behind.
                $hl = [];
            }
        } catch (RuntimeException) {
            // Retrieval failed entirely: send the question with no documents rather than nothing.
            $hits = [];
            $hl = [];
        }

        // Context building lives in AiPrompt so a plain PHP script can diff it against the golden
        // fixture without booting Laravel — see the note on that class. The relevance floor, the
        // ===== fences, the highlight block and the word cap all live inside it, byte for byte as
        // on the platform. Nothing here may pre-filter, re-order or slice the hits first: the
        // floor is measured across the same leading rows that are kept, and the document numbers
        // are derived from what survives it.
        $context = AiPrompt::context((array) $hits, (array) $hl, $ragDocs, $ragWords);

        $params = [
            'email' => $this->email,
            'api_key' => $this->apiKey,
            'index_name' => $index,
            // 'no', not 'false': Api_lib::ai_summary() disables streaming on that exact string
            // and streams for anything else, so 'false' was asking for a stream and getting
            // one. Nothing broke — Guzzle buffers it and trim() strips the flush padding — but
            // the parameter did not do what its value said (2026-08-29).
            'stream' => 'no',
        ];
        if ($instruction !== null) {
            // A caller-supplied instruction is passed through byte for byte — those callers write
            // a bare directive ("answer in German, cite the exact titles") and wrapping their
            // words in ours would throw them away. What they do NOT write is the question or the
            // documents, so this branch composes both onto the end itself, in the platform's own
            // order and with the platform's own labels, and still sends ONE field.
            //
            // It used to send `context` (and rely on `query`) as separate form fields and let
            // Api_lib::ai_summary() join them. That stopped working the moment `query` was
            // dropped from the shared params for the default branch: the override branch went
            // out with documents and no question at all. Doing the composition here removes the
            // dependency on what the other branch happens to send, matches what the four Python
            // clients do, and keeps the wire format identical across every package.
            // trim() on both, matching Api_lib::ai_summary() and the Python clients exactly —
            // the guard has to test the same string it would append, or a context that differs
            // only by trailing whitespace gets appended twice.
            $prompt = $instruction;
            $q = trim($query);
            $c = trim($context);
            if ($q !== '' && !str_contains($prompt, $q)) {
                $prompt .= "\n\nQUERY:\n" . $q;
            }
            if ($c !== '' && !str_contains($prompt, $c)) {
                $prompt .= "\n\nCONTENT:\n" . $c;
            }
            $params['instruction'] = $prompt;
            // 0.1 on this branch too. It was absent, so the server fell back to 0.2 and the one
            // call site in five repos that ran warmer than everything else was this one.
            $params['temperature'] = self::DEFAULT_RAG_TEMPERATURE;
        } else {
            // The instruction IS the whole prompt (2026-08-26): documents first, question last,
            // and no context or query field alongside it. Sent as their own fields, the platform
            // appends them AFTER the instruction under a CONTENT: label, which puts the documents
            // last — the exact inversion of the ordering that was measured. Removing the trailing
            // question slot cost the runner-up phrasing 4 of its 7 adversarial points, so this is
            // not cosmetic. Temperature travels with the wording: both were scored together.
            $params['instruction'] = AiPrompt::instruction($context, $query);
            $params['temperature'] = self::DEFAULT_RAG_TEMPERATURE;
        }
        $response = $this->http->post(self::AI_BASE . '/ai_summary', [
            'form_params' => $params,
            'http_errors' => false,
        ]);
        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException('Opensolr ai_summary: HTTP ' . $response->getStatusCode());
        }

        // The stream is prefixed with flush-padding whitespace — strip it.
        return trim((string) $response->getBody());
    }
}
