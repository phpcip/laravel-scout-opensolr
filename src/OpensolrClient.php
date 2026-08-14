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
    ): array {
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

        return $this->solrSelect($index, $params);
    }

    /**
     * Grounded RAG answer generated only from the index's own content.
     *
     * Two-step pattern: hybrid retrieval picks the top $ragDocs hits (first
     * $ragWords words of text each), whose title/description/text become the
     * LLM context — the same pipeline as Opensolr's hosted search UI. Pass
     * $instruction to fully control the prompt (e.g. "Answer in German,
     * cite the sources you used"). Returns plain text.
     */
    public function aiAnswer(
        string $index,
        string $query,
        ?string $filterQuery = null,
        int $ragDocs = 3,
        int $ragWords = 1500,
        ?string $instruction = null,
    ): string {
        $context = '';
        try {
            $body = $this->hybridSearch($index, $query, $ragDocs, 'union', 0.5, 'title,description,text', $filterQuery);
            foreach ($body['response']['docs'] ?? [] as $doc) {
                $flat = static function ($v): string {
                    return is_array($v) ? implode(' ', array_map('strval', $v)) : (string) ($v ?? '');
                };
                $words = array_slice(preg_split('/\s+/', $flat($doc['text'] ?? '')) ?: [], 0, $ragWords);
                $context .= $flat($doc['title'] ?? '') . ' - '
                    . $flat($doc['description'] ?? '') . ' - '
                    . implode(' ', $words) . ' - ';
            }
        } catch (RuntimeException) {
            $context = ''; // fall back to server-side retrieval
        }

        $params = [
            'email' => $this->email,
            'api_key' => $this->apiKey,
            'index_name' => $index,
            'query' => $query,
            'stream' => 'false',
        ];
        if ($instruction !== null) {
            $params['instruction'] = $instruction;
        }
        if ($context !== '') {
            $params['context'] = $context;
            $params['instruction'] ??= "Read and understand the full context below, and formulate "
                . "a clear, concise and factual answer to: '{$query}'.\n"
                . "Answer ONLY from the context. Format the answer in Markdown, use bold "
                . "section headers where they help, and cite exact titles or names from "
                . "the context when referring to them.\n";
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
