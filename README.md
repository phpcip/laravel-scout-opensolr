# Laravel Scout driver for Opensolr

[Laravel Scout](https://laravel.com/docs/scout) engine backed by
[Opensolr](https://opensolr.com) — managed Apache Solr with **server-side
embeddings** and **hybrid (BM25 + kNN) search**.

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

## Hybrid search

Searches run hybrid by default: BM25 keyword scores and semantic kNN scores
fused **per document** via Opensolr's native `{!hybrid}` Solr query parser.
Tune in `config/scout-opensolr.php` (publish with
`php artisan vendor:publish --tag=scout-opensolr-config`):

```php
'hybrid' => true,   // false = pure semantic
'alpha'  => 0.5,    // 0 = all semantic … 1 = all lexical
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
within about a minute (progress visible in the Opensolr Control Panel).
This fits Scout's queue-based paradigm naturally.

## Lexical-only mode

Set `OPENSOLR_MODE=lexical` for pure keyword search: no embedding calls,
zero AI quota, and it works on **any** Opensolr index, including non-vector
ones and older Solr versions.

## Your index schema

Documents follow the Opensolr document model. To inspect the schema:
**Control Panel → click your index → Configuration → Edit File → schema.xml**.

MIT license.
