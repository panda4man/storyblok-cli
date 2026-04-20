<?php

declare(strict_types=1);

use App\Storyblok\ApiFactory;
use Storyblok\ManagementApi\Data\Enum\Region;
use Storyblok\ManagementApi\Data\Story;
use Storyblok\ManagementApi\Data\StoryCollectionItem;
use Storyblok\ManagementApi\Data\StoryComponent;
use Storyblok\ManagementApi\Endpoints\StoryApi;
use Storyblok\ManagementApi\Endpoints\StoryBulkApi;
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\Response\StoryResponse;

/**
 * Build a StoryCollectionItem for test fixtures.
 */
function makeCollectionItem(string $id, string $name, string $fullSlug, string $contentType): StoryCollectionItem
{
    $item = StoryCollectionItem::make([
        'id' => $id,
        'name' => $name,
        'slug' => basename($fullSlug),
        'full_slug' => $fullSlug,
        'content_type' => $contentType,
    ]);

    return $item;
}

/**
 * Build a full Story as returned by StoryApi::get().
 */
function makeFullStory(string $id, string $name, string $slug, string $contentType): Story
{
    $component = new StoryComponent($contentType);
    $component->set('title', 'Test Title');

    $story = new Story($name, $slug, $component);
    $story->set('id', $id);

    return $story;
}

/**
 * Return a generator yielding the given StoryCollectionItems.
 *
 * @param array<StoryCollectionItem> $items
 * @return Generator<StoryCollectionItem>
 */
function makeStoriesGenerator(array $items): Generator
{
    yield from $items;
}

/**
 * Bind a mock ApiFactory into the application container and return the mocked
 * StoryBulkApi and StoryApi so individual tests can set expectations on them.
 *
 * @return array{factory: \Mockery\MockInterface, bulkApi: \Mockery\MockInterface, storyApi: \Mockery\MockInterface}
 */
function bindMockFactory(\Illuminate\Foundation\Application $app): array
{
    $mockBulkApi = Mockery::mock(StoryBulkApi::class);
    $mockStoryApi = Mockery::mock(StoryApi::class);
    $mockClient = Mockery::mock(ManagementApiClient::class);

    $mockFactory = Mockery::mock(ApiFactory::class);
    $mockFactory->shouldReceive('makeClient')->andReturn($mockClient);
    $mockFactory->shouldReceive('makeBulkApi')->andReturn($mockBulkApi);
    $mockFactory->shouldReceive('makeStoryApi')->andReturn($mockStoryApi);

    $app->instance(ApiFactory::class, $mockFactory);

    return ['factory' => $mockFactory, 'bulkApi' => $mockBulkApi, 'storyApi' => $mockStoryApi];
}

// ─── Credential & validation tests ───────────────────────────────────────────

it('fails with an error when no token is provided', function (): void {
    $origEnv = $_ENV['STORYBLOK_PERSONAL_ACCESS_TOKEN'] ?? null;
    $origServer = $_SERVER['STORYBLOK_PERSONAL_ACCESS_TOKEN'] ?? null;
    unset($_ENV['STORYBLOK_PERSONAL_ACCESS_TOKEN'], $_SERVER['STORYBLOK_PERSONAL_ACCESS_TOKEN']);
    putenv('STORYBLOK_PERSONAL_ACCESS_TOKEN');
    \Illuminate\Support\Env::enablePutenv();

    $this->artisan('story:update:content-type', ['content-type' => 'page', '--space-id' => '12345'])
        ->expectsOutputToContain('No personal access token provided')
        ->assertExitCode(1);

    // Restore
    if ($origEnv !== null) { $_ENV['STORYBLOK_PERSONAL_ACCESS_TOKEN'] = $origEnv; }
    if ($origServer !== null) { $_SERVER['STORYBLOK_PERSONAL_ACCESS_TOKEN'] = $origServer; }
    if ($origEnv !== null) { putenv("STORYBLOK_PERSONAL_ACCESS_TOKEN={$origEnv}"); }
    \Illuminate\Support\Env::enablePutenv();
});

it('fails with an error when no space id is provided', function (): void {
    $origEnv = $_ENV['STORYBLOK_SPACE_ID'] ?? null;
    $origServer = $_SERVER['STORYBLOK_SPACE_ID'] ?? null;
    unset($_ENV['STORYBLOK_SPACE_ID'], $_SERVER['STORYBLOK_SPACE_ID']);
    putenv('STORYBLOK_SPACE_ID');
    \Illuminate\Support\Env::enablePutenv();

    $this->artisan('story:update:content-type', ['content-type' => 'page', '--token' => 'fake-token'])
        ->expectsOutputToContain('No space ID provided')
        ->assertExitCode(1);

    // Restore
    if ($origEnv !== null) { $_ENV['STORYBLOK_SPACE_ID'] = $origEnv; }
    if ($origServer !== null) { $_SERVER['STORYBLOK_SPACE_ID'] = $origServer; }
    if ($origEnv !== null) { putenv("STORYBLOK_SPACE_ID={$origEnv}"); }
    \Illuminate\Support\Env::enablePutenv();
});

it('fails with an error when an invalid region is provided', function (): void {
    $this->artisan('story:update:content-type', [
        'content-type' => 'page',
        '--token' => 'fake-token',
        '--space-id' => '12345',
        '--region' => 'INVALID',
    ])
        ->expectsOutputToContain("Invalid region 'INVALID'")
        ->assertExitCode(1);
});

// ─── Dry run tests ────────────────────────────────────────────────────────────

it('shows matching stories in a table during dry run without making updates', function (): void {
    ['bulkApi' => $mockBulkApi, 'storyApi' => $mockStoryApi] = bindMockFactory($this->app);

    $items = [
        makeCollectionItem('111', 'Post One', 'blog/post-one', 'old-page'),
        makeCollectionItem('222', 'Post Two', 'blog/post-two', 'old-page'),
    ];

    $mockBulkApi->shouldReceive('all')->andReturn(makeStoriesGenerator($items));
    $mockStoryApi->shouldNotReceive('get');
    $mockStoryApi->shouldNotReceive('update');

    $this->artisan('story:update:content-type', [
        'content-type' => 'new-page',
        '--token' => 'fake-token',
        '--space-id' => '12345',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Dry run')
        ->expectsOutputToContain('Post One')
        ->expectsOutputToContain('Post Two')
        ->assertExitCode(0);
});

it('warns and exits successfully when no stories match the filters', function (): void {
    ['bulkApi' => $mockBulkApi] = bindMockFactory($this->app);

    $mockBulkApi->shouldReceive('all')->andReturn(makeStoriesGenerator([]));

    $this->artisan('story:update:content-type', [
        'content-type' => 'new-page',
        '--token' => 'fake-token',
        '--space-id' => '12345',
    ])
        ->expectsOutputToContain('No stories matched')
        ->assertExitCode(0);
});

// ─── Update tests ─────────────────────────────────────────────────────────────

it('updates the content type of all matching stories', function (): void {
    ['bulkApi' => $mockBulkApi, 'storyApi' => $mockStoryApi] = bindMockFactory($this->app);

    $items = [
        makeCollectionItem('111', 'Post One', 'blog/post-one', 'old-page'),
        makeCollectionItem('222', 'Post Two', 'blog/post-two', 'old-page'),
    ];

    $mockBulkApi->shouldReceive('all')->andReturn(makeStoriesGenerator($items));

    foreach ($items as $item) {
        $fullStory = makeFullStory($item->id(), $item->name(), $item->slug(), 'old-page');
        $storyResponse = Mockery::mock(StoryResponse::class);
        $storyResponse->shouldReceive('data')->andReturn($fullStory);

        $mockStoryApi->shouldReceive('get')
            ->with($item->id())
            ->andReturn($storyResponse);

        $mockStoryApi->shouldReceive('update')
            ->with($item->id(), Mockery::on(function (Story $story): bool {
                return $story->content()->getString('component') === 'new-page';
            }))
            ->once();
    }

    $this->artisan('story:update:content-type', [
        'content-type' => 'new-page',
        '--token' => 'fake-token',
        '--space-id' => '12345',
        '--force' => true,
    ])
        ->expectsOutputToContain('✓ Updated: 2')
        ->assertExitCode(0);
});

it('reports failure exit code when at least one story update fails', function (): void {
    ['bulkApi' => $mockBulkApi, 'storyApi' => $mockStoryApi] = bindMockFactory($this->app);

    $items = [makeCollectionItem('111', 'Post One', 'blog/post-one', 'old-page')];

    $mockBulkApi->shouldReceive('all')->andReturn(makeStoriesGenerator($items));
    $mockStoryApi->shouldReceive('get')->andThrow(new \RuntimeException('API error'));

    $this->artisan('story:update:content-type', [
        'content-type' => 'new-page',
        '--token' => 'fake-token',
        '--space-id' => '12345',
        '--force' => true,
    ])
        ->expectsOutputToContain('✗ Failed: 1')
        ->assertExitCode(1);
});

// ─── --from-content-type filter test ─────────────────────────────────────────

it('passes a QueryFilters object when --from-content-type is specified', function (): void {
    ['bulkApi' => $mockBulkApi] = bindMockFactory($this->app);

    $mockBulkApi->shouldReceive('all')
        ->withArgs(function ($params, $filters): bool {
            return $filters !== null;
        })
        ->andReturn(makeStoriesGenerator([]));

    $this->artisan('story:update:content-type', [
        'content-type' => 'new-page',
        '--from-content-type' => 'old-page',
        '--token' => 'fake-token',
        '--space-id' => '12345',
    ])->assertExitCode(0);
});

it('passes no QueryFilters when --from-content-type is omitted', function (): void {
    ['bulkApi' => $mockBulkApi] = bindMockFactory($this->app);

    $mockBulkApi->shouldReceive('all')
        ->withArgs(function ($params, $filters): bool {
            return $filters === null;
        })
        ->andReturn(makeStoriesGenerator([]));

    $this->artisan('story:update:content-type', [
        'content-type' => 'new-page',
        '--token' => 'fake-token',
        '--space-id' => '12345',
    ])->assertExitCode(0);
});
