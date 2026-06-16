# drupal-dev-tools

Automated development tooling for Drupal 8+ projects. Adds coding standards, static analysis, and git hooks that run on custom code only (never core or contrib).

## What's included

| Tool | Purpose | Runs on |
|---|---|---|
| PHPCS + Drupal Coder | Drupal coding standards | `pre-commit` |
| PHPCBF | Auto-fix coding standards | manual |
| PHPStan + phpstan-drupal | Static analysis | `pre-push` |
| GrumPHP | Git hook orchestration | automatic |

## Requirements

- PHP 7.4+
- Composer 2.x
- Drupal 8+

## Installation

```bash
composer require --dev lester-barahona/drupal-dev-tools
```

On install, the plugin automatically:

1. Generates `.drupal-dev-tools/grumphp.yml` with the correct vendor paths (gitignored, regenerated on every `composer install`)
2. Sets `extra.grumphp.config-default-path` in your `composer.json` to point to that generated file
3. Registers the required Composer plugins in `allow-plugins`
4. Installs git hooks via GrumPHP

No config files are copied to your project root. Everything works out of the box.

## Add Composer scripts

Add these to your project's `composer.json` for convenience:

```json
"scripts": {
    "cs": "phpcs",
    "cs-fix": "phpcbf",
    "analyse": "phpstan analyse"
}
```

Then use them as:

```bash
composer cs         # check coding standards
composer cs-fix     # auto-fix coding standards
composer analyse    # run static analysis
```

## Code checked

Only custom code is ever analysed:

```
web/modules/custom/**
web/themes/custom/**
web/profiles/custom/**
```

Core, contrib, vendor, and node_modules are always excluded.

## Customizing per project

If the defaults work for your project, you don't need to do anything.

When you need to override something, publish the stub for that specific tool:

```bash
composer drupal-dev-tools:publish phpcs          # publish phpcs.xml
composer drupal-dev-tools:publish phpstan         # publish phpstan.neon
composer drupal-dev-tools:publish grumphp         # publish grumphp.yml
composer drupal-dev-tools:publish .editorconfig   # publish .editorconfig
composer drupal-dev-tools:publish all             # publish all stubs
```

Each published file extends the base config from this package so you only need to add your overrides.

Use `--force` to overwrite a file that already exists:

```bash
composer drupal-dev-tools:publish phpcs --force
```

### Override PHPCS rules (`phpcs.xml`)

```bash
composer drupal-dev-tools:publish phpcs
```

Then edit `phpcs.xml` in your project root:

```xml
<ruleset name="Project">
  <rule ref="vendor/my-org/drupal-dev-tools/config/phpcs.xml"/>

  <!-- Disable a specific rule -->
  <rule ref="Drupal.Commenting.FunctionComment">
    <severity>0</severity>
  </rule>

  <!-- Exclude a specific path -->
  <exclude-pattern>web/modules/custom/legacy_module/*</exclude-pattern>
</ruleset>
```

### Override PHPStan config (`phpstan.neon`)

```bash
composer drupal-dev-tools:publish phpstan
```

Then edit `phpstan.neon` in your project root:

```neon
includes:
  - vendor/my-org/drupal-dev-tools/config/phpstan.neon

parameters:
  level: 5
  drupal:
    drupal_root: docroot  # if your webroot is not "web"
```

### Override GrumPHP hooks (`grumphp.yml`)

```bash
composer drupal-dev-tools:publish grumphp
```

Then edit `grumphp.yml` in your project root:

```yaml
imports:
  - { resource: .drupal-dev-tools/grumphp.yml }

grumphp:
  tasks:
    phpcs:
      metadata:
        run_on: [pre-push]  # move PHPCS to pre-push if pre-commit is too slow
```

## Hook behavior

| Hook | Task | Can be moved |
|---|---|---|
| `pre-commit` | PHPCS | Yes, via `grumphp.yml` override |
| `pre-push` | PHPStan | Yes, via `grumphp.yml` override |

## Default Drupal webroot

The config assumes `web/` as the Drupal root. If your project uses `docroot/` or another path, publish and edit `phpstan.neon` and `phpcs.xml` accordingly.

## How it works

On every `composer install` or `composer update`, the plugin generates `.drupal-dev-tools/grumphp.yml` with paths computed from the actual package location in vendor. This means the package can be renamed or moved to a different namespace without breaking anything — the paths are always correct.

The `.drupal-dev-tools/` directory is automatically added to `.gitignore` since it is regenerated on every install.
