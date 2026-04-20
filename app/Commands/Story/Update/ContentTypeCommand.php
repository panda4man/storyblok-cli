<?php

declare(strict_types=1);

namespace App\Commands\Story\Update;

use App\Storyblok\ApiFactory;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use Storyblok\ManagementApi\Data\Enum\Region;
use Storyblok\ManagementApi\Endpoints\StoryApi;
use Storyblok\ManagementApi\Endpoints\StoryBulkApi;
use Storyblok\ManagementApi\QueryParameters\Filters\Filter;
use Storyblok\ManagementApi\QueryParameters\Filters\QueryFilters;
use Storyblok\ManagementApi\QueryParameters\StoriesParams;
use Storyblok\ManagementApi\QueryParameters\Type\Direction;
use Storyblok\ManagementApi\QueryParameters\Type\SortBy;

class ContentTypeCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'story:update:content-type
        {content-type : The target content type (component name) to assign to matching stories}
        {--F|from-content-type= : Only update stories currently using this content type}
        {--s|starts-with= : Filter by full_slug prefix (e.g. blog/posts)}
        {--by-slugs= : Comma-separated full_slugs. Supports wildcards: posts/*}
        {--u|by-uuids= : Comma-separated story UUIDs}
        {--t|with-tag= : Filter by tag slug(s), comma-separated (OR logic)}
        {--S|search= : Full-text search term across name, slug, and content}
        {--in-workflow-stages= : Comma-separated workflow stage IDs}
        {--excluding-slugs= : Comma-separated full_slugs to exclude. Supports wildcards}
        {--excluding-ids= : Comma-separated story IDs to exclude}
        {--sort-by= : Sort field and direction, e.g. created_at:desc or slug:asc}
        {--D|dry-run : Preview matching stories without making any changes}
        {--f|force : Skip the confirmation prompt before updating}
        {--space-id= : Storyblok space ID (overrides STORYBLOK_SPACE_ID)}
        {--token= : Personal access token (overrides STORYBLOK_PERSONAL_ACCESS_TOKEN)}
        {--region= : Storyblok region: EU, US, AP, CA, CN (overrides STORYBLOK_REGION, defaults to EU)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Update the content type for a filtered set of Storyblok stories';

    /**
     * Execute the console command.
     *
     * ApiFactory is resolved via method injection so the container binding is
     * evaluated at call time rather than at command-registration time, which
     * ensures test mocks bound via app()->instance() are respected.
     */
    public function handle(ApiFactory $apiFactory): int
    {
        $token = $this->option('token') ?: env('STORYBLOK_PERSONAL_ACCESS_TOKEN');
        $spaceId = $this->option('space-id') ?: env('STORYBLOK_SPACE_ID');
        $regionStr = strtoupper((string) ($this->option('region') ?: env('STORYBLOK_REGION', 'EU')));
        $targetContentType = (string) $this->argument('content-type');
        $isDryRun = (bool) $this->option('dry-run');

        if (! $token) {
            $this->error('No personal access token provided. Use --token or set STORYBLOK_PERSONAL_ACCESS_TOKEN in .env.');

            return self::FAILURE;
        }

        if (! $spaceId) {
            $this->error('No space ID provided. Use --space-id or set STORYBLOK_SPACE_ID in .env.');

            return self::FAILURE;
        }

        if (! Region::isValid($regionStr)) {
            $this->error("Invalid region '{$regionStr}'. Valid values: " . implode(', ', Region::values()));

            return self::FAILURE;
        }

        $client = $apiFactory->makeClient((string) $token, Region::from($regionStr));
        $bulkApi = $apiFactory->makeBulkApi($client, (string) $spaceId);
        $storyApi = $apiFactory->makeStoryApi($client, (string) $spaceId);

        $this->info('Fetching matching stories...');

        $stories = $this->collectMatchingStories($bulkApi);

        $count = count($stories);

        if ($count === 0) {
            $this->warn('No stories matched the given filters.');

            return self::SUCCESS;
        }

        if ($isDryRun) {
            $this->info("Dry run: {$count} story(ies) would be updated to content type '{$targetContentType}'.");
            $this->table(
                ['ID', 'Name', 'Full Slug', 'Current Content Type'],
                array_map(fn ($s) => [
                    $s->id(),
                    $s->name(),
                    $s->fullSlug(),
                    $s->contentType(),
                ], $stories)
            );

            return self::SUCCESS;
        }

        $this->info("Found {$count} story(ies) to update to content type '{$targetContentType}'.");

        if (! $this->option('force') && ! $this->confirm('Proceed with updating these stories?')) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        return $this->performUpdates($storyApi, $stories, $targetContentType);
    }

    /**
     * Fetch all stories matching the given filter options.
     *
     * @return array<\Storyblok\ManagementApi\Data\StoryCollectionItem>
     */
    private function collectMatchingStories(StoryBulkApi $bulkApi): array
    {
        $params = $this->buildStoriesParams();
        $filters = $this->buildQueryFilters();

        $stories = [];
        foreach ($bulkApi->all(params: $params, filters: $filters) as $story) {
            $stories[] = $story;
        }

        return $stories;
    }

    /**
     * Perform the content type update on each story.
     *
     * @param array<\Storyblok\ManagementApi\Data\StoryCollectionItem> $stories
     */
    private function performUpdates(StoryApi $storyApi, array $stories, string $targetContentType): int
    {
        $count = count($stories);
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $updated = 0;
        $failed = 0;
        $errors = [];

        foreach ($stories as $story) {
            try {
                $fullStory = $storyApi->get($story->id())->data();
                $fullStory->set('content.component', $targetContentType);
                $storyApi->update($story->id(), $fullStory);
                $updated++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = [$story->id(), $story->name(), $e->getMessage()];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $this->info("✓ Updated: {$updated}  ✗ Failed: {$failed}");

        if ($errors !== []) {
            $this->newLine();
            $this->error('The following stories could not be updated:');
            $this->table(['Story ID', 'Name', 'Error'], $errors);
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Build a StoriesParams object from the provided CLI filter options.
     */
    private function buildStoriesParams(): StoriesParams
    {
        $sortBy = null;
        if ($sortByStr = $this->option('sort-by')) {
            $parts = explode(':', (string) $sortByStr, 2);
            $direction = isset($parts[1]) && strtolower($parts[1]) === 'desc'
                ? Direction::Desc
                : Direction::Asc;
            $sortBy = new SortBy($parts[0], $direction);
        }

        return new StoriesParams(
            textSearch: $this->option('search') ?: null,
            sortBy: $sortBy,
            byUuids: $this->option('by-uuids') ?: null,
            withTag: $this->option('with-tag') ?: null,
            startsWith: $this->option('starts-with') ?: null,
            bySlugs: $this->option('by-slugs') ?: null,
            excludingSlugs: $this->option('excluding-slugs') ?: null,
            inWorkflowStages: $this->option('in-workflow-stages') ?: null,
            excludingIds: $this->option('excluding-ids') ?: null,
        );
    }

    /**
     * Build QueryFilters for filtering by current content type (--from-content-type).
     */
    private function buildQueryFilters(): ?QueryFilters
    {
        $fromContentType = $this->option('from-content-type');

        if (! $fromContentType) {
            return null;
        }

        return (new QueryFilters())->add(
            new Filter('component', 'in', (string) $fromContentType)
        );
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void {}
}
