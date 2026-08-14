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

        return $this->request(self::MGMT_BASE, 'create_index', [
            'index_name' => $index,
            'core_type' => 'generic',
            'server_country' => $env,
        ]) ?? [];
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
}
