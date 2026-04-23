# Storyblok CLI

A command-line tool for bulk management operations against a [Storyblok](https://www.storyblok.com/) space. Built with [Laravel Zero](https://laravel-zero.com/).

## Requirements

- PHP 8.2+
- Composer
- A Storyblok **Personal Access Token** (account-level — see [Token Types](#token-types))

## Installation

```bash
git clone <repo>
cd storyblok-cli
composer install
cp .env.example .env
```

Edit `.env` with your credentials:

```dotenv
# Generate at: https://app.storyblok.com/#/me/account?tab=token
STORYBLOK_PERSONAL_ACCESS_TOKEN=your-pat-here

STORYBLOK_SPACE_ID=123456

# Optional — defaults to EU. Valid: EU, US, AP, CA, CN
STORYBLOK_REGION=EU
```

## Available Commands

### `story:update:content-type`

Updates the content type (root component) for a filtered set of stories.

```
php storyblok-cli story:update:content-type {content-type} [options]
```

**Arguments**

| Argument | Description |
|---|---|
| `content-type` | The target component name to assign to matching stories |

**Filtering Options**

| Option | Shortcut | Description |
|---|---|---|
| `--from-content-type=` | `-F` | Only update stories currently using this content type |
| `--starts-with=` | `-s` | Filter by `full_slug` prefix (e.g. `blog/posts`) |
| `--by-uuids=` | `-u` | Comma-separated story UUIDs |
| `--by-ids=` | `-i` | Comma-separated story IDs (numeric) |
| `--by-slugs=` | | Comma-separated `full_slug` values; supports wildcards (`posts/*`) |
| `--with-tag=` | `-t` | Filter by tag slug(s), comma-separated (OR logic) |
| `--search=` | `-S` | Full-text search across name, slug, and content |
| `--in-workflow-stages=` | | Comma-separated workflow stage IDs |
| `--excluding-slugs=` | | Comma-separated `full_slug` values to exclude; supports wildcards |
| `--excluding-ids=` | | Comma-separated story IDs to exclude |
| `--sort-by=` | | Sort field and direction, e.g. `created_at:desc` or `slug:asc` |

**Behaviour Options**

| Option | Shortcut | Description |
|---|---|---|
| `--dry-run` | `-D` | Preview matching stories without making any changes |
| `--force` | `-f` | Skip the confirmation prompt |

**Credential Overrides** *(override `.env` values per-invocation)*

| Option | Description |
|---|---|
| `--token=` | Personal access token |
| `--space-id=` | Storyblok space ID |
| `--region=` | Region: `EU`, `US`, `AP`, `CA`, `CN` |

#### Examples

Preview all stories that would be changed to `page`:
```bash
php storyblok-cli story:update:content-type page --dry-run
# or
php storyblok-cli story:update:content-type page -D
```

Change all stories currently using `page-v1` to `page`:
```bash
php storyblok-cli story:update:content-type page --from-content-type=page-v1
# or
php storyblok-cli story:update:content-type page -F page-v1
```

Update specific stories by UUID, skipping confirmation:
```bash
php storyblok-cli story:update:content-type page \
  --by-uuids=uuid-1,uuid-2,uuid-3 \
  --force
```

Update stories under a slug prefix, previewing first:
```bash
php storyblok-cli story:update:content-type article \
  --starts-with=blog/ \
  --dry-run
```

## Token Types

Storyblok has three types of tokens — only **Personal Access Tokens** work with the Management API:

| Type | Format | Works here? |
|---|---|---|
| Content Delivery (preview/public) | Short alphanumeric string | ❌ |
| OAuth app token | Contains a numeric suffix like `-67792453065304-` | ❌ |
| **Personal Access Token** | Account-level, from the profile settings page | ✅ |

Generate yours at **My Profile → Personal access tokens**:
`https://app.storyblok.com/#/me/account?tab=token`

## Running Tests

```bash
./vendor/bin/pest
```

## Adding New Commands

This project uses [Laravel Zero](https://laravel-zero.com/). Commands live in `app/Commands/`.

To scaffold a new command:
```bash
php storyblok-cli make:command
```
