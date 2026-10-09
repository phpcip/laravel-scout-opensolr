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
[Opensolr control panel](https://opensolr.com) — free forever, no card.
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
and grounded answers work the moment you connect. You also get the write path:
create your own index on the account, ingest into it and query it. Deletion is not
available on this shared key: no index here can be deleted or reconfigured by hand.
Whatever you create is removed automatically after 3 days.

Know what you are working with:

- **Anything you create there is deleted after 3 days.** Automatically, without warning
  or export. That includes indexes you created and every document in them.
- **The account is shared with everyone reading this.** Your index is visible to them
  and they can add documents to it, as you can to theirs. Nobody can delete or
  reconfigure an index here (that is switched off for this key), but never put anything
  real, private or client-owned in it.
- **The limits are per index, and deliberately small.** 200 MB of bandwidth and 50 MB
  of disk per index. Bandwidth is the one you will hit first: it covers a demo, a
  tutorial and a proof of concept, and it will not carry an application.

When you want an index that is private, yours and still there next week, get your own
key — [free forever, no card](https://opensolr.com/register) — and change the two
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

## Search operators

The query string understands the operators people already expect from a search box. They work
in every mode — keyword, hybrid and pure vector.

| Operator | Meaning | Example |
| --- | --- | --- |
| `"word1 word2"` | Phrase — those words together, in that order | `"machine learning"` |
| `+word` | Required — every result must contain it | `+laptop 15 inch gaming` |
| `+"word1 word2"` | Required phrase | `+"13 inch"` |
| `-word` | Excluded — drop any document containing it | `laptop -refurbished` |
| `-"word1 word2"` | Excluded phrase | `-"open box"` |

They compose: `+laptop +"13 inch" -refurbished` returns only 13-inch laptops and never a
refurbished one.

A prefixed term (`+` or `-`, word or phrase) becomes a **filter**, applied to the whole result
set. That matters as soon as a vector is involved: the semantic side of a hybrid search has no
concept of negation, so left as query text `-refurbished` would actually pull refurbished
listings *towards* the top rather than removing them. As a filter it binds every document,
whichever side of the search found it, and the exclusion is absolute.

An unprefixed phrase (`"machine learning"` with no `+` in front) is a keyword-side relevance
signal rather than a filter — use `+"machine learning"` when you need it enforced.

`+` and `-` only count at the start of a word, so `e-mail`, `covid-19` and `1+1` are searched
for literally.

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

- Vector-enabled indexes run in Opensolr's vector regions (Solr 9 and newer) —
  `us` (Chicago), `de` (Germany), `fi` (Finland) and any other region the live
  `vector_regions` list names. **Additional dedicated regions can be deployed on
  request** (paid add-on): [support@opensolr.com](mailto:support@opensolr.com).
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
zero AI quota. It searches the `title`, `description` and `text` fields, so it
also runs on an index outside the vector regions or on an older Solr version, as
long as the index has those fields. Indexing (Data Ingestion) still needs an index
in a vector region — see [Index requirements](#index-requirements).

## Search by image

Search with a **photo** instead of a text query. Opensolr reads the picture
three ways — visual labels (what it depicts), OCR text (words printed on it),
and any barcode / QR code — and turns that into words. Nothing new is stored in
Solr; the picture simply becomes a query you run through the normal search.

```php
use Opensolr\ScoutOpensolr\OpensolrClient;

$client = new OpensolrClient(config('scout-opensolr.email'), config('scout-opensolr.api_key'));

// What the picture reads as (labels, OCR text, barcodes) — no search yet.
// Accepts a file path or raw image bytes:
$read = $client->imageToWords('my-index', storage_path('app/photo.jpg'));
// ['text' => 'red running shoe', 'mode' => 'clip',
//  'labels' => ['running shoe', 'sneaker'], 'codes' => ['0123456789012']]

// Feed those words into the ordinary hybrid search:
$body = $client->embedAndSearch('my-index', $read['text'], 5, ['search_mode' => 'union']);
$docs = $body['response']['docs'];
```

Pick which reading drives the search by choosing which field of `$read` you
search with — `text` (the engine's choice), `labels` (what it depicts), or
`codes` (an exact barcode match). `imageToText()` returns the raw API response
if you need the thumbnails and every label.

## Your index schema

Documents follow the Opensolr document model. The whole schema, every field and every type suffix,
is explained in the [Index Schema Reference](https://opensolr.com/opensolr-platform-user-documentation/schema-reference).
To inspect your own copy:
**Control Panel → click your index → Configuration → Edit File → schema.xml**.

## Index requirements

Everything the driver does works on an index that is **in a vector region**
(`us`, `de`, `fi` and the rest of the live `vector_regions` list) and runs the
**Opensolr vector schema** — the configuration every vector index created there
gets. The driver checks this for you:

- **Once per index, one request.** The first operation that needs it asks
  Opensolr what the index can do (`check_schema`); the answer is kept in your
  Laravel cache for `schema_check_ttl` seconds (default 3600, store
  `schema_cache_store`). It is never asked on every search. An `OpensolrClient`
  you build yourself keeps it for the life of the object (pass `cache:` to share it).
- **It never blocks what works today.** It only stops what cannot work anyway,
  and before it costs anything:
  - **indexing** (Data Ingestion) on an index outside the vector regions;
  - **semantic and hybrid search** on an index without the vector fields — before
    the query is embedded, so no AI quota is spent on it.

  Keyword search (`OPENSOLR_MODE=lexical`) is never blocked.
- **If the check cannot run** (network, the platform unreachable), nothing is
  concluded: the operation runs exactly as it did without the check, and the
  check is tried again after 5 minutes.
- An index that is not on the Opensolr vector schema gets **one warning in your
  log** each time the check runs, with what differs and what to do.

When something cannot work, the driver throws
`Opensolr\ScoutOpensolr\OpensolrSchemaException` (a `RuntimeException`, like every
error the driver threw before) with the exact reason, for example:

```
Index 'myapp__dense' cannot take documents through Data Ingestion. Index myapp__dense
is on IT-SOLR-9-6 (Milano, Italy), which is not a vector region: Data Ingestion,
server-side embeddings and hybrid search run only on the servers of a vector region
(DE-SOLR-9 (Germany, Solr 9.00), FINLAND9 (Finland, Solr 9.60), ...). This index cannot
be made ready: create a new index in one of the vector regions ..., and send your
documents there.
```

`getReport()` on the exception returns the full check. The check carries `ready`,
`capabilities` (`ingestion`, `vector_search`, `pdf_pages`, `keyword_search`), the
server and its Solr version, whether it is in a vector region, every field and type
that is missing or different from the Opensolr vector schema, `problems` and `fix`.

You can run it yourself, and install the schema:

```php
use Laravel\Scout\EngineManager;

$engine = app(EngineManager::class)->engine('opensolr');

$engine->checkSchema();            // what the index can do, and why not
$engine->applySchema();            // install the Opensolr vector schema
$engine->applySchema(force: true); // the same on an index that already holds documents
```

**Why `applySchema()` exists.** An index of a vector region can still carry an
older or a different configuration — created before the current schema, or edited
by hand. `applySchema()` installs the same configuration every Opensolr vector index
runs, so indexing, semantic and hybrid search and PDF pages all work on it. It
**replaces the index configuration**: on an index that already holds documents it
needs `force: true`, and those documents keep the fields they were written with, so
send them again afterwards (`php artisan scout:import`). The driver never runs it on
its own.

**An index outside the vector regions cannot take the schema**: indexing,
server-side embeddings and hybrid search only run there. Create a new index in one of
the regions the check lists (`php artisan scout:index anything`, or the Opensolr
control panel) and point `OPENSOLR_INDEX` at it.

Solr's own errors (an HTTP 4xx/5xx from the index) are thrown as
`OpensolrSolrException` / `OpensolrSolrServerException` with Solr's reason and the
index name in the message; they are still Guzzle `ClientException` /
`ServerException`, so code that caught those keeps working. Every exception the
driver throws on purpose implements `OpensolrExceptionInterface`.

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
