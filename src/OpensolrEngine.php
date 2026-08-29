<?php

namespace Opensolr\ScoutOpensolr;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;

/**
 * Laravel Scout engine for Opensolr.
 *
 * All searchable models share ONE vector-enabled Opensolr index (configured
 * in scout.opensolr.index). Each document carries meta_model = searchableAs()
 * and every search is scoped to it — so a single $50/mo index serves the
 * whole application. Embeddings are computed server-side at index and query
 * time (multilingual E5, 1024-dim); search is hybrid BM25 + kNN by default.
 */
class OpensolrEngine extends Engine
{
    public function __construct(
        protected OpensolrClient $client,
        protected string $index,
        protected bool $hybrid = true,
        protected float $alpha = 0.5,
        protected bool $softDelete = false,
        protected string $mode = 'hybrid',
        protected bool $ingestWait = false,
        // Fresh Results Bias, off unless the application asks for it
        // (scout-opensolr.fresh_bias / OPENSOLR_FRESH_BIAS). A constructor flag rather
        // than a per-query argument because Scout's Builder has no place to carry search
        // options — the same reason $hybrid, $alpha and $mode live here.
        protected bool $freshBias = false,
        // How hard the bias pushes, 0.0-1.0. Only consulted when $freshBias is on:
        // the flag decides WHETHER recency counts, this decides HOW MUCH. Null uses
        // OpensolrClient::FRESH_BIAS_WEIGHT_DEFAULT.
        protected ?float $freshBiasWeight = null,
    ) {
    }

    /**
     * Grounded RAG answer from the Scout index: hybrid retrieval picks the
     * top hits, whose content becomes the LLM context. Returns plain text.
     * Usage: app(EngineManager::class)->engine('opensolr')->aiAnswer('...')
     *
     * $ragDocs mirrors OpensolrClient::aiAnswer() — four, the platform's own
     * number. A default that disagreed with the client's would silently
     * override it for every caller coming through Scout, which is all of them.
     */
    public function aiAnswer(
        string $query,
        ?string $filterQuery = null,
        int $ragDocs = 4,
        int $ragWords = 1500,
        ?string $instruction = null,
        array $tuning = [],
    ): string {
        return $this->client->aiAnswer($this->index, $query, $filterQuery, $ragDocs, $ragWords, $instruction, $tuning);
    }

    /** Deterministic ingestion URI for a model document (id = md5(uri)). */
    protected function docUri(string $model, mixed $key): string
    {
        return 'https://ingest.opensolr.com/' . $this->index . '/' . rawurlencode($model . '__' . $key);
    }

    protected function docId(string $model, mixed $key): string
    {
        return $model . '__' . $key;
    }

    /**
     * Flatten a searchable array into indexable text (values only, recursive).
     */
    protected function searchableText(array $searchable): string
    {
        $parts = [];
        array_walk_recursive($searchable, function ($value) use (&$parts) {
            if (is_scalar($value) && $value !== '' && $value !== null) {
                $parts[] = (string) $value;
            }
        });

        return trim(implode(' ', $parts)) ?: ' ';
    }

    public function update($models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $docs = [];
        foreach ($models as $model) {
            $searchable = $model->toSearchableArray();
            if (empty($searchable)) {
                continue;
            }
            $meta = array_merge($searchable, $model->scoutMetadata());
            $text = $this->searchableText($searchable);
            $doc = [
                'uri' => $this->docUri($model->searchableAs(), $model->getScoutKey()),
                'title' => mb_substr((string) ($meta['title'] ?? $text), 0, 250),
                'description' => (string) ($meta['description'] ?? mb_substr($text, 0, 200)),
                'text' => $text ?: ' ',
                'meta_model' => $model->searchableAs(),
                'meta_scout_key' => (string) $model->getScoutKey(),
                'meta_lc_json' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            ] + $this->metaFields($meta);
            if (!empty($meta['timestamp'])) {
                $doc['timestamp'] = $meta['timestamp'];
            }
            $docs[] = $doc;
        }

        if (empty($docs)) {
            return;
        }

        // Data Ingestion API (async): embeddings, sentiment, and all derived
        // fields are computed server-side; documents become searchable within
        // ~1 minute. Progress is visible in the Opensolr Control Panel.
        foreach (array_chunk($docs, 50) as $chunk) {
            $this->client->ingest($this->index, $chunk, $this->ingestWait);
        }
    }

    /** @return array<string, string> scalar metadata as filterable meta_* fields */
    protected function metaFields(array $meta): array
    {
        $fields = [];
        foreach ($meta as $key => $value) {
            if (is_scalar($value)) {
                $clean = trim(preg_replace('/[^a-z0-9_]+/', '_', strtolower((string) $key)), '_');
                if ($clean !== '' && $clean !== 'model' && $clean !== 'scout_key' && $clean !== 'lc_json') {
                    $fields["meta_{$clean}"] = (string) $value;
                }
            }
        }

        return $fields;
    }

    public function delete($models): void
    {
        if ($models->isEmpty()) {
            return;
        }
        $parts = $models->map(function ($model) {
            return '(meta_model:"' . addcslashes($model->searchableAs(), '"\\')
                . '" AND meta_scout_key:"' . addcslashes((string) $model->getScoutKey(), '"\\') . '")';
        })->values()->all();
        $this->client->solrUpdate($this->index, ['delete' => ['query' => implode(' OR ', $parts)]]);
    }

    public function search(Builder $builder)
    {
        return $this->performSearch($builder, [
            'rows' => $builder->limit ?: 100,
            'start' => 0,
        ]);
    }

    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->performSearch($builder, [
            'rows' => $perPage,
            'start' => ($page - 1) * $perPage,
        ]);
    }

    protected function performSearch(Builder $builder, array $options): array
    {
        $model = $builder->model->searchableAs();
        $query = $builder->query !== '' ? $builder->query : '*';
        $k = max((int) $options['rows'] + (int) $options['start'], 10);

        $params = [
            'rows' => $options['rows'],
            'start' => $options['start'],
            'fl' => '*,score',
        ];

        if ($query === '*') {
            $params['q'] = '*:*';
        } elseif ($this->mode === 'lexical') {
            // Pure keyword search — no embedding call, zero AI quota.
            $clean = str_replace(['{', '}', '"'], ' ', $query);
            $params['q'] = '{!edismax qf="title^100 description^20 text^1"}' . $clean;
        } else {
            $vector = $this->client->embedQuery($this->index, $query);
            $knn = '{!knn f=embeddings topK=' . $k . '}' . json_encode($vector);
            if ($this->mode === 'hybrid' && $this->hybrid) {
                $clean = str_replace(['{', '}', '"'], ' ', $query);
                $params['q'] = '{!hybrid lexical=$lexicalRaw vector=$vectorQuery mode=union alpha=' . $this->alpha . ' topN=' . $k . '}';
                $params['lexicalRaw'] = '{!edismax qf="title^100 text^1"}' . $clean;
                $params['vectorQuery'] = $knn;
            } else {
                $params['q'] = $knn;
            }
        }

        // Fresh Results Bias: multiply the FINAL score by the recency curve on
        // creation_date, so recent documents win ties and near-ties. Wraps whichever
        // shape was built above — *:*, edismax, fused {!hybrid} or bare {!knn} —
        // because {!boost} is the one form all four honour: under {!hybrid} an edismax
        // bf reaches only the lexical sub-query, where the plugin normalizes it and
        // scales it by (1-alpha), never touching a candidate that arrived through the
        // vector leg, and a bare {!knn} has no edismax to read a bf at all.
        //
        // Re-orders only, never filters: numFound is unchanged and a document with no
        // creation_date is simply left unboosted. The inner query moves into its own
        // parameter and is referenced by v=$... rather than inlined, so a '}' in the
        // user's text cannot close the {!boost} block and leave the rest to be parsed
        // as query syntax.
        if ($this->freshBias) {
            // A document with no creation_date evaluates recip() at its maximum, 1.0, so it
            // is scored as if published this instant and floats to the top. Require a date
            // rather than silently promoting the ones that have none (2026-08-29).
            $params['fq'] = array_values(array_unique(array_merge(
                (array) ($params['fq'] ?? []),
                ['+creation_date:[* TO *]']
            )));
            $params['freshBias'] = OpensolrClient::freshBiasFunction($this->freshBiasWeight);
            $params['freshBiasInner'] = $params['q'];
            $params['q'] = '{!boost b=$freshBias v=$freshBiasInner}';
        }

        $fq = ['meta_model:"' . addcslashes($model, '"\\') . '"'];
        foreach ($builder->wheres as $key => $where) {
            // Scout >=11 stores [field, operator, value]; Scout 10 stored field => value.
            if (is_array($where)) {
                $field = $where['field'];
                $op = $where['operator'] ?? '=';
                $value = $where['value'];
            } else {
                $field = $key;
                $op = '=';
                $value = $where;
            }
            $clean = trim(preg_replace('/[^a-z0-9_]+/', '_', strtolower((string) $field)), '_');
            $quoted = '"' . addcslashes((string) $value, '"\\') . '"';
            $fq[] = match ($op) {
                '!=', '<>' => "-meta_{$clean}:{$quoted}",
                '>' => "meta_{$clean}:{{$quoted} TO *]",
                '>=' => "meta_{$clean}:[{$quoted} TO *]",
                '<' => "meta_{$clean}:[* TO {$quoted}}",
                '<=' => "meta_{$clean}:[* TO {$quoted}]",
                default => "meta_{$clean}:{$quoted}",
            };
        }
        foreach ($builder->whereIns as $field => $values) {
            $clean = trim(preg_replace('/[^a-z0-9_]+/', '_', strtolower((string) $field)), '_');
            $quoted = array_map(fn ($v) => '"' . addcslashes((string) $v, '"\\') . '"', $values);
            $fq[] = 'meta_' . $clean . ':(' . implode(' OR ', $quoted) . ')';
        }
        $params['fq'] = $fq;

        return $this->client->solrSelect($this->index, $params);
    }

    public function mapIds($results)
    {
        $docs = $results['response']['docs'] ?? [];

        return collect($docs)->map(function ($doc) {
            $key = $doc['meta_scout_key'] ?? null;

            return is_array($key) ? ($key[0] ?? null) : $key;
        })->filter()->values();
    }

    public function map(Builder $builder, $results, $model)
    {
        $ids = $this->mapIds($results)->all();
        if (empty($ids)) {
            return $model->newCollection();
        }

        $models = $model->getScoutModelsByIds($builder, $ids)->keyBy(
            fn ($m) => (string) $m->getScoutKey()
        );

        return collect($ids)
            ->map(fn ($id) => $models[(string) $id] ?? null)
            ->filter()
            ->values()
            ->pipe(fn ($c) => $model->newCollection($c->all()));
    }

    public function lazyMap(Builder $builder, $results, $model)
    {
        return LazyCollection::make($this->map($builder, $results, $model)->all());
    }

    public function getTotalCount($results)
    {
        return (int) ($results['response']['numFound'] ?? 0);
    }

    public function flush($model)
    {
        $instance = new $model();
        $this->client->solrUpdate($this->index, [
            'delete' => ['query' => 'meta_model:"' . addcslashes($instance->searchableAs(), '"\\') . '"'],
        ]);
    }

    public function createIndex($name, array $options = [])
    {
        return $this->client->createIndex($this->index, $options['location'] ?? 'us');
    }

    public function deleteIndex($name)
    {
        // Index deletion is an account-level operation — do it from the
        // Opensolr control panel to avoid accidental data loss from code.
        return null;
    }
}
