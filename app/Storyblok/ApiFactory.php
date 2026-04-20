<?php

declare(strict_types=1);

namespace App\Storyblok;

use Storyblok\ManagementApi\Data\Enum\Region;
use Storyblok\ManagementApi\Endpoints\StoryApi;
use Storyblok\ManagementApi\Endpoints\StoryBulkApi;
use Storyblok\ManagementApi\ManagementApiClient;

/**
 * Factory for creating Storyblok Management API client instances.
 *
 * Extracted so it can be swapped in tests via the service container.
 */
class ApiFactory
{
    public function makeClient(string $token, Region $region): ManagementApiClient
    {
        return new ManagementApiClient(
            personalAccessToken: $token,
            region: $region,
            shouldRetry: true,
        );
    }

    public function makeBulkApi(ManagementApiClient $client, string $spaceId): StoryBulkApi
    {
        return new StoryBulkApi($client, $spaceId);
    }

    public function makeStoryApi(ManagementApiClient $client, string $spaceId): StoryApi
    {
        return new StoryApi($client, $spaceId);
    }
}
