# Filament-based CMS, Core Package

[![Latest Version on Packagist](https://img.shields.io/packagist/v/franc014/phi-cms-core.svg?style=flat-square)](https://packagist.org/packages/franc014/phi-cms-core)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/franc014/phi-cms-core/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/franc014/phi-cms-core/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/franc014/phi-cms-core/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/franc014/phi-cms-core/actions?query=workflow%3A"Fix+PHP+code+styling"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/franc014/phi-cms-core.svg?style=flat-square)](https://packagist.org/packages/franc014/phi-cms-core)

A Filament v5 plugin that provides a complete CMS layer with pages, sections, posts, categories, menus, and company settings — built on Spatie packages.

## Definition-backed Sections (Services slice)

Core now owns `Sections\SectionDefinition`, `SectionRegistry`, `SectionContent`, the `<x-phi-cms-core::section>` Blade wrapper and `phi-cms-core.preview` native form workspace. This path requires neither Presenter nor Visual providers. Existing legacy consumers still require their legacy packages and coordinated adopted-record rejection guards.

Define a stable key, Blade view, native `fields()` and canonical `normalize(array): array`; register the class once under `phi-cms.sections`. Blade receives plain keyed `$content`. Publishing is a separate, versioned, reviewed operation consuming a persisted draft. Hosts own routes, templates, policies and deliberate grants (`preview`, `viewDraft`, `update`, `publish`); editor activation grants no authority.

`phi-cms.editing` defaults false. Public rendering works with it disabled. Install the additive snapshot/staged-relationship migrations. Fresh redesign fixtures are sufficient: the owner-approved [contract decision](https://github.com/franc014/phi-cms-test/issues/40#issuecomment-6511743815) retires legacy migration/adoption/parity, mandatory backups/exports, recovery rehearsals and downgrade support. `phi-cms:services`, `phi-cms:gallery` and `phi-cms:pages` migration/recovery commands and PageContent's adoption/inventory API have been removed. Existing database content, files, additive schema and dependencies are not automatically deleted.

The demo's [`docs/services-workflow.md`](https://github.com/franc014/phi-cms-test/blob/ticket-35/docs/services-workflow.md) describes the current integration and safety boundaries. Page metadata/composition drafts, reviewed publication and native preview editing never publish Section drafts implicitly. Templates remain developer-controlled. The host retains its read-only About composition behavior. Changed publication fingerprints require fresh review. Raw SQL/filesystem mutations remain trusted operator responsibilities, not bypass-proof operations.

## Private Services Page creation

The native Page-list **Create draft** action creates a private version-one Page before composition. Hosts register an ordered Services template, its Blade fragment, public/preview routes, and independent create/preview/compose/use/replicate/edit/publish grants. Core supplies transactional Page creation, **Create and add Services**, and **Copy published Services**. Copy requires a reviewed published snapshot; it never copies private drafts, media, relationships or permissions. Native submissions require server-issued actor/session/action-bound tokens; reopening an action starts intentional additional creation. Direct server application calls may omit creation tokens, but must not be exposed as unvalidated request handlers.

Active does not mean published: new/copied Sections explicitly show **Unpublished Section** until independently published. Removing an assignment retains the library Section. Abandoned private drafts persist. Required published Services gates Page publication, not incomplete draft saves.

Normal Page deletion is authorized, version-checked and atomic, requires no export storage, and retains independent Sections/files. Explicit Section deletion still refuses actual draft/public references and protected owned media; snapshots or historical recovery files alone are not a deletion blocker. Private immutable media, conservative current-usage checks and tombstoned retryable physical cleanup remain mandatory. Transaction rollback on failed writes remains unchanged; it is not legacy downgrade support. See `docs/company-page-workflow.md` in the demo for workflow and verification guidance. Arbitrary native field normalization, Gallery/media copy and Chocotour arbitrary Page routing remain outside this slice.

## Requirements

- PHP 8.3+
- Laravel 13+
- Filament v5+
- A database supported by Laravel (MySQL, PostgreSQL, SQLite, SQL Server)

## Installation

### 1. Require the package

```bash
composer require franc014/phi-cms-core
```

This will pull in all required dependencies automatically, including:
- `filament/filament` (v5)
- `filament/spatie-laravel-media-library-plugin`
- `filament/spatie-laravel-tags-plugin`
- `filament/spatie-laravel-settings-plugin`
- `spatie/laravel-medialibrary`
- `spatie/laravel-tags`
- `spatie/laravel-settings`
- `spatie/eloquent-sortable`

### 2. Run the install command

```bash
php artisan phi-cms-core:install
```

This will:

1. **Publish Spatie Tags migrations** (`tags` and `taggables` tables)
2. **Publish Spatie Settings migration** (`settings` table)
3. **Publish the config file** (`config/phi-cms-core.php`)
4. **Publish phi-cms-core migrations** (sections, pages, page_section, menus, menu_items, posts, categories, category_post)
5. **Publish the company settings migration** (CompanySettings default values)
6. **Ask to run all migrations**

### 3. Run migrations (if you didn't accept the prompt)

```bash
php artisan migrate
```

### 4. Create a Filament admin panel

If you don't already have a Filament panel, create one:

```bash
php artisan make:filament-panel admin
```

### 5. Register the plugin

Open `app/Providers/Filament/AdminPanelProvider.php` and add the plugin:

```php
use JFA\PhiCmsCore\PhiCmsCorePlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            // ...
            ->plugins([
                PhiCmsCorePlugin::make(),
            ]);
    }
}
```

### 6. Create a test admin user

```bash
php artisan make:filament-user
```

Follow the prompts to set a name, email, and password. Then visit `/admin` and log in.

## Custom Theme (Recommended)

If you have not set up a custom theme for Filament, follow the [Filament Styling Docs](https://filamentphp.com/docs/styling/overview) first.

Once your theme is set up, add the plugin's views to your theme CSS file (or your app's CSS file if using standalone packages):

```css
@source '../../../../vendor/franc014/phi-cms-core/resources/**/*.blade.php';
```

Then rebuild your assets:

```bash
npm run build
```

## Manual Installation

If you prefer to publish resources individually instead of using the install command:

```bash
# Publish Spatie Tags migrations
php artisan vendor:publish --provider="Spatie\Tags\TagsServiceProvider" --tag="tags-migrations"

# Publish Spatie Settings migration
php artisan vendor:publish --provider="Spatie\LaravelSettings\LaravelSettingsServiceProvider" --tag="migrations"

# Publish phi-cms-core config
php artisan vendor:publish --tag="phi-cms-core-config"

# Publish phi-cms-core migrations
php artisan vendor:publish --tag="phi-cms-core-migrations"

# Publish company settings migration
php artisan vendor:publish --tag="phi-cms-core-settings-migrations"

# Run all migrations
php artisan migrate
```

Optionally, publish the views:

```bash
php artisan vendor:publish --tag="phi-cms-core-views"
```

## What You Get

After installation, the following is available in the admin panel:

### Resources

| Resource | Description |
|---|---|
| **Pages** | CMS pages with sections, publishing, slugs, and metatags |
| **Sections** | Reusable content sections with components |
| **Posts** | Blog posts with tags, categories, publishing, and content blocks |
| **Categories** | Post categorization |
| **Menus** | Navigation menus with sortable items |

### Pages

| Page | Description |
|---|---|
| **ManageCompany** | Company settings (name, email, phone, address, social media, working days) |

### Content Blocks

Content blocks are registered via `ContentBlockRegistry` and configured in `config/phi-cms-core.php`:

- **Heading** — h1 through h6 headings
- **Paragraph** — Rich text paragraphs
- **CustomRichEditor** — Rich content with custom blocks (CTA, etc.)
- **PostsChooser** — Post selection widget

You can extend the default blocks by adding your own to the `custom` array in the config:

```php
// config/phi-cms-core.php
return [
    'content_blocks' => [
        'default' => [
            Heading::class,
            Paragraph::class,
            CustomRichEditor::class,
            PostsChooser::class,
        ],
        'custom' => [
            \App\CMS\Blocks\HeroBlock::class,
        ],
    ],
];
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Francisco Andrade](https://github.com/franc014)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
