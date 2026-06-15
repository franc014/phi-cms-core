# Filament-based CMS, Core Package

[![Latest Version on Packagist](https://img.shields.io/packagist/v/franc014/phi-cms-core.svg?style=flat-square)](https://packagist.org/packages/franc014/phi-cms-core)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/franc014/phi-cms-core/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/franc014/phi-cms-core/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/franc014/phi-cms-core/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/franc014/phi-cms-core/actions?query=workflow%3A"Fix+PHP+code+styling"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/franc014/phi-cms-core.svg?style=flat-square)](https://packagist.org/packages/franc014/phi-cms-core)

This is where your description should go. Limit it to a paragraph or two. Consider adding a small example.

## Installation

You can install the package via composer:

```bash
composer require franc014/phi-cms-core
```

> [!IMPORTANT]
> If you have not set up a custom theme and are using Filament Panels follow the instructions in the [Filament Docs](https://filamentphp.com/docs/styling/overview) first.

After setting up a custom theme add the plugin's views to your theme css file or your app's css file if using the standalone packages.

```css
@source '../../../../vendor/franc014/phi-cms-core/resources/**/*.blade.php';
```

### Install Command

Run the install command to publish all configuration, migrations, and dependencies in one step:

```bash
php artisan phi-cms-core:install
```

This command will:

1. **Publish Spatie Tags migrations** (`tags` and `taggables` tables)
2. **Publish Spatie Settings migration** (`settings` table)
3. **Publish the config file** (`config/phi-cms-core.php`)
4. **Publish phi-cms-core migrations** (sections, pages, page_section, menus, menu_items, posts, categories, category_post)
5. **Publish the company settings migration** (CompanySettings default values)
6. **Ask to run all migrations**

### Manual Installation

If you prefer to publish resources individually, run the following commands:

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

Optionally, you can publish the views:

```bash
php artisan vendor:publish --tag="phi-cms-core-views"
```

This is the contents of the published config file:

```php
return [
    'content_blocks' => [
        'default' => [
            Heading::class,
            Paragraph::class,
            CustomRichEditor::class,
            PostsChooser::class,
        ],
        'custom' => [
            // \App\CMS\Blocks\CustomBlock::class,
        ],
    ],
];
```

## Usage

```php
$phiCmsCore = new JFA\PhiCmsCore();
echo $phiCmsCore->echoPhrase('Hello, JFA!');
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
