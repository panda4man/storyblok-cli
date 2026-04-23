# Agent Guide: Creating New Commands

This document describes how to add new commands to this CLI. It is intended for AI agents and human contributors alike.

## Framework

This project uses [Laravel Zero](https://laravel-zero.com/docs/commands) — a lightweight console-app framework built on top of Laravel. Every command extends `LaravelZero\Framework\Commands\Command`.

---

## 1. Scaffold the Command

Use the built-in generator to create the boilerplate:

```bash
php storyblok-cli make:command <NewCommand>
```

The generated class will land in `app/Commands/`. Move it into the appropriate sub-namespace directory (see §2).

---

## 2. File Location & Namespace

Commands are organised by resource and action under `app/Commands/`:

```
app/Commands/
  Story/
    Update/
      ContentTypeCommand.php   # story:update:content-type
```

**Conventions:**
- Group by resource first (`Story`, `Space`, `Asset`, …), then by verb (`Update`, `Create`, `Delete`, …).
- Class name: `<Action><Resource>Command` — e.g. `UpdateContentTypeCommand`, or keep `ContentTypeCommand` scoped by its namespace.
- Namespace: `App\Commands\<Resource>\<Action>` — e.g. `App\Commands\Story\Update`.
- All PHP files must begin with `declare(strict_types=1);`.

---

## 3. Command Signature

Define the signature as a multiline string on `protected $signature`. Every command **must** include the three standard credential override options at the bottom:

```php
protected $signature = 'resource:verb:noun
    {required-arg : Description of the argument}
    {--O|optional-option= : Description of an option that takes a value}
    {--D|dry-run : Preview changes without applying them}
    {--f|force : Skip the confirmation prompt}
    {--space-id= : Storyblok space ID (overrides STORYBLOK_SPACE_ID)}
    {--token= : Personal access token (overrides STORYBLOK_PERSONAL_ACCESS_TOKEN)}
    {--region= : Storyblok region: EU, US, AP, CA, CN (overrides STORYBLOK_REGION, defaults to EU)}';
```

**Rules:**
- Command names use colon-separated hierarchy: `resource:verb:noun`.
- Arguments are bare `{name : description}`; options use `{--name=}` (value) or `{--name}` (flag).
- Single-character shortcuts go before the long name: `{--D|dry-run}`.
- All user-facing filter/behaviour options must have inline descriptions.

---

## 4. Credential Resolution

Resolve credentials at the top of `handle()` in this order: CLI option → `.env` value:

```php
$token   = $this->option('token')    ?: env('STORYBLOK_PERSONAL_ACCESS_TOKEN');
$spaceId = $this->option('space-id') ?: env('STORYBLOK_SPACE_ID');
$regionStr = strtoupper((string) ($this->option('region') ?: env('STORYBLOK_REGION', 'EU')));
```

Then validate before doing anything else:

```php
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
```

---

## 5. ApiFactory (Dependency Injection)

All Storyblok API clients are created through `App\Storyblok\ApiFactory`. **Never instantiate API clients directly in a command.** Inject the factory via `handle()` method injection so tests can swap in a mock:

```php
use App\Storyblok\ApiFactory;

public function handle(ApiFactory $apiFactory): int
{
    $client  = $apiFactory->makeClient((string) $token, Region::from($regionStr));
    $bulkApi = $apiFactory->makeBulkApi($client, (string) $spaceId);
    $storyApi = $apiFactory->makeStoryApi($client, (string) $spaceId);
    // ...
}
```

If the new command needs an API endpoint not yet covered by `ApiFactory`, add a `make*` method there rather than instantiating inline.

---

## 6. Output Conventions

| Method | When to use |
|---|---|
| `$this->info(…)` | Normal progress messages |
| `$this->warn(…)` | Non-fatal notices (e.g. no results found) |
| `$this->error(…)` | Errors that cause failure |
| `$this->table([…], […])` | Tabular data (dry-run previews, error summaries) |
| `$this->output->createProgressBar($n)` | Long-running loops over many items |

Always call `$this->newLine()` after `$bar->finish()`.

For destructive operations include a `--dry-run` flag that prints a preview table and returns `self::SUCCESS` without mutating anything, and a `--force` flag that skips the confirmation prompt:

```php
if (! $this->option('force') && ! $this->confirm('Proceed?')) {
    $this->info('Aborted.');
    return self::SUCCESS;
}
```

---

## 7. Return Codes

Always return an explicit integer constant from `handle()`:

| Constant | Value | When |
|---|---|---|
| `self::SUCCESS` | `0` | All work completed without error |
| `self::FAILURE` | `1` | Validation failed, or ≥1 item could not be processed |

If a command processes multiple items and some fail, report `✓ Updated: N  ✗ Failed: M` and return `self::FAILURE` when `$failed > 0`.

---

## 8. Tests

Tests live in `tests/Feature/` and use [Pest](https://pestphp.com/). Run them with:

```bash
./vendor/bin/pest
```

### Mocking ApiFactory

Use the `bindMockFactory()` pattern: bind a `Mockery` mock of `ApiFactory` into the application container, then set expectations on the individual API objects it returns.

```php
use App\Storyblok\ApiFactory;
use Storyblok\ManagementApi\Endpoints\StoryBulkApi;

function bindMockFactory(\Illuminate\Foundation\Application $app): array
{
    $mockBulkApi  = Mockery::mock(StoryBulkApi::class);
    $mockClient   = Mockery::mock(\Storyblok\ManagementApi\ManagementApiClient::class);

    $mockFactory  = Mockery::mock(ApiFactory::class);
    $mockFactory->shouldReceive('makeClient')->andReturn($mockClient);
    $mockFactory->shouldReceive('makeBulkApi')->andReturn($mockBulkApi);

    $app->instance(ApiFactory::class, $mockFactory);

    return ['factory' => $mockFactory, 'bulkApi' => $mockBulkApi];
}
```

### Required Test Coverage

Every command must cover at minimum:

1. **Missing token** → exits 1 with the correct error message.
2. **Missing space ID** → exits 1 with the correct error message.
3. **Invalid region** → exits 1 with the correct error message.
4. **No matching results** → warns and exits 0.
5. **Dry-run** → displays a preview table, makes no mutations, exits 0.
6. **Happy path** → processes all items, exits 0.
7. **Partial failure** → exits 1 when ≥1 item fails, reports the error table.
8. **Key filter options** → verify that the correct params/filters are forwarded to the API.

### Invoking the Command in Tests

Use `$this->artisan()` with an array of arguments/options:

```php
$this->artisan('resource:verb:noun', [
    'required-arg' => 'value',
    '--token'      => 'fake-token',
    '--space-id'   => '12345',
    '--force'      => true,
])
    ->expectsOutputToContain('Expected string')
    ->assertExitCode(0);
```

---

## 9. Test-Driven Development (Red → Green → Refactor)

All new commands **must** be built using TDD. Never write implementation code before a failing test exists for it.

### The Cycle

**🔴 Red — write a failing test first**

Before touching the command class, write a test that describes exactly the behaviour you are about to implement. Run the suite and confirm the new test fails for the right reason (not a syntax error or a missing class — a genuine assertion failure):

```bash
./vendor/bin/pest --filter "your new test description"
```

**🟢 Green — write the minimum code to pass**

Implement only enough in the command to make the failing test pass. Resist the urge to add anything not yet covered by a test. Run the suite again and confirm the test goes green and no existing tests regress:

```bash
./vendor/bin/pest
```

**🔵 Refactor — clean up without breaking anything**

With a green suite as your safety net, improve the implementation: extract private methods, remove duplication, improve naming. Re-run the full suite after each change to confirm nothing breaks. Do not add new behaviour during refactor — that starts a new red/green cycle.

### Workflow for a Full Command

Work through the required test cases (§8) one at a time, completing a full red/green/refactor cycle for each before moving to the next:

1. Write the credential-missing tests → implement credential validation → refactor.
2. Write the no-results test → implement early-exit → refactor.
3. Write the dry-run test → implement dry-run branch → refactor.
4. Write the happy-path test → implement the core update loop → refactor.
5. Write the partial-failure test → implement error handling → refactor.
6. Write each filter-option test → wire up params/filters → refactor.

### Rules

- **Never commit code that makes the suite fail.** Every commit must have a green suite.
- **Never skip the red phase.** If a test passes without any implementation change, the test is not testing anything meaningful — rewrite it.
- **One failing test at a time.** Do not write multiple new tests before making the first one green.
- **Test behaviour, not implementation.** Assert on output text, exit codes, and observable side-effects (e.g. `shouldReceive('update')->once()`). Do not assert on internal method calls or private state.

---

## 10. Update README.md

After adding a command, update the **Available Commands** section in `README.md`:

1. Add an `###` heading for the command name.
2. Include the usage line: `` `php storyblok-cli <signature>` ``.
3. Document all arguments in an **Arguments** table.
4. Document all filter options in a **Filtering Options** table (with shortcut column).
5. Document behaviour flags (`--dry-run`, `--force`) in a **Behaviour Options** table.
6. Document credential overrides (`--token`, `--space-id`, `--region`) in a **Credential Overrides** table.
7. Add at least two concrete **Examples** using `bash` code blocks.

See the existing `story:update:content-type` section as the canonical reference.
