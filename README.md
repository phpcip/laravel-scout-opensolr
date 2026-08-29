# Laravel Scout driver for Opensolr

[Laravel Scout](https://laravel.com/docs/scout) engine backed by
[Opensolr](https://opensolr.com) — managed Apache Solr with **server-side
embeddings** and **hybrid (BM25 + kNN) search**.

**See it live (real news index, hybrid + AI answer):** https://search.opensolr.com/news__dense?q=how+am+I+supposed+to+save+money%3F

Your models get semantic search that understands meaning — "sleepy pets"
finds the post about cats napping — fused with classic keyword relevance,
on managed infrastructure. No embedding model, no vector database to run.

**One index serves your whole app**: every searchable model shares a single
vector-enabled Opensolr index, scoped per model automatically — so the $50/mo
tier covers all your models.

```bash
composer require opensolr/laravel-scout-opensolr
```

## Setup

```dotenv
SCOUT_DRIVER=opensolr
OPENSOLR_EMAIL=you@example.com
OPENSOLR_API_KEY=your-api-key
OPENSOLR_INDEX=myapp__dense
```

Create a vector-enabled index (locations: us, de, fi) in the
[Opensolr control panel](https://opensolr.com) — free 15-day trial, no card.
Then Scout works exactly as documented:

```php
use Laravel\Scout\Searchable;

class Post extends Model
{
    use Searchable;

    public function toSearchableArray(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'category' => $this->category,
        ];
    }
}
```

```php
Post::search('how do keyword and semantic search combine?')->get();
Post::search('budget dining')->where('category', 'restaurants')->paginate(15);
```

## Try it without an account

There is a public demo account. Point the package at it and everything in this
README works immediately, with no signup:

```bash
export OPENSOLR_EMAIL=mcp@opensolr.com
export OPENSOLR_API_KEY=420b8b23e7b12dc8ab838932145a5065
```

`mcp_demo_d1__dense` is already loaded with 300 news articles, so search, filtering
and grounded answers work the moment you connect. You also get the full write path:
create your own index on the account, ingest into it, query it, delete it.

Know what you are working with:

- **Anything you create there is deleted after 3 days.** Automatically, without warning
  or export. That includes indexes you created and every document in them.
- **The account is shared with everyone reading this.** Your index is visible to them,
  they can change or delete it, and you can do the same to theirs. Never put anything
  real, private or client-owned in it.
- **The limits are per index, and deliberately small.** 200 MB of bandwidth and 50 MB
  of disk per index. Bandwidth is the one you will hit first: it covers a demo, a
  tutorial and a proof of concept, and it will not carry an application.

When you want an index that is private, yours and still there next week, get your own
key — [free 15-day trial, no card](https://opensolr.com/register) — and change the two
variables above. Nothing else in your code changes.

## Hybrid search

Searches run hybrid by default: BM25 keyword scores and semantic kNN scores
fused **per document** via Opensolr's native `{!hybrid}` Solr query parser.
Tune in `config/scout-opensolr.php` (publish with
`php artisan vendor:publish --tag=scout-opensolr-config`):

```php
// config/scout-opensolr.php
return [
    // ...
    'hybrid' => env('OPENSOLR_HYBRID', true),  // false = pure semantic
    'alpha'  => env('OPENSOLR_ALPHA', 0.5),    // 0 = all semantic … 1 = all lexical
];
```

## How it maps

| Scout | Opensolr |
|---|---|
| `$model->searchable()` | doc indexed + embedded server-side (batched) |
| `Model::search($q)` | hybrid BM25 + kNN query, embedded server-side |
| `->where('field', $v)` / `->whereIn()` | Solr `fq` on `meta_field` (supports `=`, `!=`, `>`, `>=`, `<`, `<=`) |
| `->paginate($n)` | Solr `start`/`rows` + real `numFound` totals |
| `$model->unsearchable()` | delete by id |
| `Model::removeAllFromSearch()` | delete by model scope |

## Notes

- Vector-enabled indexes run on Opensolr's Solr 9.x environments — currently
  `us` (Chicago), `de` (Germany), `fi` (Finland). **Additional dedicated
  regions can be deployed on request** (paid add-on):
  [support@opensolr.com](mailto:support@opensolr.com).
- Every index is also plain Apache Solr with the native `/select` API —
  facets, highlighting, spellcheck available beyond Scout.
- Siblings: [`langchain-opensolr`](https://pypi.org/project/langchain-opensolr/) ·
  [`llama-index-opensolr`](https://pypi.org/project/llama-index-opensolr/) ·
  [`opensolr-haystack`](https://pypi.org/project/opensolr-haystack/) ·
  [`opensolr-mcp`](https://pypi.org/project/opensolr-mcp/)

## How indexing works (Data Ingestion API)

Writes go through Opensolr's [Data Ingestion API](https://opensolr.com/learn/api-data-ingestion/204/data-ingestion-api-push-documents-to-your-opensolr-index-programmatically)
— the same pipeline the Drupal and WordPress connectors use. It is
**asynchronous**: models are queued on save, then embeddings, sentiment, and
all derived fields are computed **server-side**; documents become searchable
within about a minute. Progress is visible in **Control Panel → Data
Ingestion** — a per-job status board with detailed document counts — and
via the `ingestStatus` API.
This fits Scout's queue-based paradigm naturally.

## Lexical-only mode

Set `OPENSOLR_MODE=lexical` for pure keyword search: no embedding calls,
zero AI quota, and it works on **any** Opensolr index, including non-vector
ones and older Solr versions.

## Your index schema

Documents follow the Opensolr document model. To inspect the schema:
**Control Panel → click your index → Configuration → Edit File → schema.xml**.

## Grounded RAG answers

One call: hybrid retrieval picks the top hits, whose content becomes the LLM
context, and Opensolr's server-side LLM answers — no LLM key needed:

```php
use Laravel\Scout\EngineManager;

$answer = app(EngineManager::class)->engine('opensolr')->aiAnswer(
    'what does the refund policy say?',
    filterQuery: null,
    ragDocs: 4,       // how many hybrid hits feed the LLM (default 4)
    ragWords: 1500,   // words of text taken from each hit (default 1500)
    // instruction: 'Answer in German, cite the exact titles you used', // optional
);
```


### Search tuning

Retrieval (search and RAG grounding) runs through the platform's tuned
pipeline: global defaults → your index's saved **Search Tuning** (Control
Panel → Index Settings → Search Tuning: semantic↔lexical balance, field
weights, minimum match, search mode, vector candidate pool, content quality
boost) → optional per-call overrides via `tuning`:

```php
$answer = app(EngineManager::class)->engine('opensolr')->aiAnswer(
    'what does the refund policy say?',
    tuning: [
        'search_mode' => 'keywords_required',
        'fw_title' => 0.2,
        'mm' => 'strict',
        'vector_topk' => 500,
        'quality_boost' => 0.3,
    ],
);
```

Defaults match the platform's PHP configuration exactly — customize in the
Control Panel once, or per call from code.

#### Fresh Results Bias

Rank newer documents higher without hiding anything older. Every score is
multiplied by a recency curve on `creation_date` — full weight for a document
published today, about half after a year:

```php
$client->hybridSearch($index, $query, freshBias: true);
$client->aiAnswer($index, $question, tuning: ['fresh_bias' => 1]);
```

For Scout-driven searches set it once in `config/scout-opensolr.php`
(`'fresh_bias' => env('OPENSOLR_FRESH_BIAS', false)`).

It **re-orders and never filters**: the hit count is identical either way,
nothing old becomes unreachable, and a document with no `creation_date` simply
keeps its place instead of being pushed to the bottom. It applies to all three
retrieval shapes — vector-only, keyword-only and the fused hybrid ranking —
because the boost wraps the final score rather than one half of it. Off by
default.

This is the same control visitors get as the **Fresh** toggle beside the sort
options on the hosted Opensolr search page, so a query behaves identically here
and there.

> `fresh_bias` and `freshness_boost` are two different knobs and the names
> invite confusion. `freshness_boost` is a hard window in **days** — anything
> older is filtered out and the hit count drops. `fresh_bias` filters nothing.

## How it's tested

Every release is validated against **live Opensolr infrastructure** — no mocks:

- **Unit tests** (offline): location aliases, filter→fq mapping, query building, escaping.
- **End-to-end suite**: the full write path through the async Data Ingestion
  queue (queued → server-side enrichment → searchable), semantic / hybrid /
  lexical retrieval, metadata round-trip, filters, id round-trip (your ids
  and the Solr `md5(uri)` ids), deletes by id and by query.
- **Real-corpus validation**: searches run against a 340-document replica of
  opensolr.com's own production search index. Verified: pure-semantic hits
  with zero keyword overlap ("how do I get my data back after a disaster" →
  backup &amp; restore docs), cross-lingual queries (Romanian query → English
  content), exact-term surfacing in hybrid mode, all four hybrid modes, and
  the full alpha range 0 → 1.
- **PDF ingestion**: a real PDF ingested via `rtf:true` — server-side text
  extraction (13k+ chars), automatic content-type detection, then retrieved
  with a purely semantic query against its contents.
- **Grounded RAG answers**: `ai_answer` verified end-to-end — a question answerable only from the ingested PDF returns the correct answer, sourced from the PDF's extracted text via hybrid retrieval.

The engine is exercised live via Orchestra Testbench (real Eloquent models
on in-memory SQLite): searchable() through the ingestion queue, semantic
relevance, where() filters, pagination totals, unsearchable() removal.

```bash
OPENSOLR_EMAIL=... OPENSOLR_API_KEY=... OPENSOLR_INDEX=... vendor/bin/phpunit
```

MIT license.
