<?php

namespace Opensolr\ScoutOpensolr\Tests;

use Illuminate\Database\Schema\Blueprint;
use Laravel\Scout\ScoutServiceProvider;
use Opensolr\ScoutOpensolr\OpensolrScoutServiceProvider;
use Opensolr\ScoutOpensolr\Tests\Fixtures\Post;
use Orchestra\Testbench\TestCase;

/**
 * Live end-to-end test against a real Opensolr vector index.
 * Requires env: OPENSOLR_EMAIL, OPENSOLR_API_KEY, OPENSOLR_INDEX.
 */
class EngineLiveTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ScoutServiceProvider::class, OpensolrScoutServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('scout.driver', 'opensolr');
        $app['config']->set('scout.queue', false);
        $app['config']->set('scout-opensolr.email', getenv('OPENSOLR_EMAIL'));
        $app['config']->set('scout-opensolr.api_key', getenv('OPENSOLR_API_KEY'));
        $app['config']->set('scout-opensolr.index', getenv('OPENSOLR_INDEX'));
        $app['config']->set('scout-opensolr.ingest_wait', true);
        $app['config']->set('database.default', 'testing');
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!getenv('OPENSOLR_EMAIL') || !getenv('OPENSOLR_API_KEY') || !getenv('OPENSOLR_INDEX')) {
            $this->markTestSkipped('OPENSOLR_* env vars not set');
        }
        $this->app['db']->connection()->getSchemaBuilder()->create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->string('category');
            $table->timestamps();
        });
    }

    public function test_full_scout_round_trip(): void
    {
        $posts = [
            Post::create(['title' => 'Solr guide', 'body' => 'Apache Solr powers enterprise search platforms', 'category' => 'search']),
            Post::create(['title' => 'Cat facts', 'body' => 'Cats nap in sunny windowsills all afternoon', 'category' => 'animals']),
            Post::create(['title' => 'Hybrid ranking', 'body' => 'Hybrid retrieval merges BM25 with dense vectors', 'category' => 'search']),
        ];
        foreach ($posts as $post) {
            $post->searchable();
        }
        sleep(1);

        // semantic-leaning query finds the cat post first
        $found = Post::search('sleepy pets')->take(1)->get();
        $this->assertCount(1, $found);
        $this->assertSame('Cat facts', $found->first()->title);

        // where() filter scopes by metadata
        $found = Post::search('anything at all')->where('category', 'search')->get();
        $this->assertEqualsCanonicalizing(
            ['Solr guide', 'Hybrid ranking'],
            $found->pluck('title')->all()
        );

        // pagination + total count
        $page = Post::search('search platforms')->paginate(2);
        $this->assertGreaterThanOrEqual(2, $page->total());

        // unsearchable removes from the index
        foreach ($posts as $post) {
            $post->unsearchable();
        }
        sleep(1);
        $found = Post::search('windowsills')->get();
        $this->assertCount(0, $found);
    }
}
