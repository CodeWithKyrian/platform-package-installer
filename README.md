# Composer Platform Package Installer

The Composer Platform Package Installer is a powerful plugin for platform-aware distribution URL resolution.

The core behavior is simple:

- Your package declares platform-specific artifact URLs or URL templates in `composer.json`
- At install time, the plugin picks the best URL for the current OS/architecture
- It resolves placeholders (built-in `{version}` plus optional custom variables)
- It tells Composer to download and install the package from that resolved URL

## Requirements

- PHP 8.1+
- Composer 2+

## Installation

Install the plugin using Composer:

```bash
composer require codewithkyrian/platform-package-installer
```

Then change your package type to `platform-package` in `composer.json`:

```json
{
  "name": "org/your-platform-package",
  "type": "platform-package",
  "require": {
    "codewithkyrian/platform-package-installer": "^2.0"
  }
}
```

## Core Configuration

All core behavior is configured in `composer.json` under `extra.artifacts`.

### Format A: Simple (no custom variables)

Use this when:

- you are using direct artifact URLs with no placeholders, or
- `{version}` is the only placeholder you need.

```json
{
  "extra": {
    "artifacts": {
      "darwin-arm64": "https://cdn.example.com/pkg/{version}/darwin-arm64.zip",
      "linux-x86_64": "https://cdn.example.com/pkg/{version}/linux-x86_64.tar.gz",
      "all": "https://cdn.example.com/pkg/{version}/universal.zip"
    }
  }
}
```

### Format B: Extended (custom variables + defaults)

Use this when templates include additional placeholders beyond `{version}`.

```json
{
  "extra": {
    "artifacts": {
      "urls": {
        "darwin-arm64": "https://cdn.example.com/pkg/{version}/variant-{runtime}/darwin-arm64.zip",
        "linux-x86_64": "https://cdn.example.com/pkg/{version}/variant-{runtime}/linux-x86_64.tar.gz"
      },
      "vars": {
        "runtime": "gpu"
      }
    }
  }
}
```

## Application-Level Variable Overrides

Consumers of your platform package can override variables from their root project:

```json
{
  "extra": {
    "platform-packages": {
      "org/your-platform-package": {
        "runtime": "cpu"
      }
    }
  }
}
```

Variable precedence:

1. Root app override (`extra.platform-packages.<package-name>`)
2. Package default (`extra.artifacts.vars`)
3. Built-in `{version}` (always provided by plugin)

## Placeholder Behavior

- `{version}` is built in and always available
- Any `{name}` placeholder can be used
- Unresolved placeholders cause artifact URL resolution to fail for that package
- If override variables resolve to a URL that does not exist, the plugin retries with package default variables
- On failure, Composer falls back to the package's original `dist` configuration

## Platform Matching

The plugin matches the current machine against keys in `artifacts`:

- OS-only keys: `linux`, `darwin`, `windows`, `raspberrypi`
- OS + architecture keys: `darwin-arm64`, `linux-x86_64`, `windows-32`, `windows-64`, etc.
- `all` as final fallback

More specific matches are preferred over generic ones.

## Optional Helper: Generate URLs Command

`platform:generate-urls` is a helper to generate artifact URL entries from a template.

```bash
composer platform:generate-urls --dist-type=github --repo-path=vendor/repo --platforms=linux-x86_64 --platforms=darwin-arm64
```

You can also provide platforms as a comma-separated list:

```bash
composer platform:generate-urls --dist-type=github --repo-path=vendor/repo --platforms=linux-x86_64,darwin-arm64,windows-x86_64
```

Or with a custom URL template:

```bash
composer platform:generate-urls --dist-type=https://cdn.example.com/vendor/repo/release-{version}-{platform}.{ext} --platforms=linux-x86_64,darwin-arm64
```

Command options:

- `--platforms` platform identifiers (repeat the flag or use comma-separated values)
- `--dist-type` `github`, `gitlab`, `huggingface`, or custom template
- `--repo-path` repository path for built-in templates
- `--extension` force archive extension

The command writes generated URLs into `extra.artifacts`.

## Testing

Run tests with:

```bash
composer test
```

The test suite includes:

- unit tests for matching, templating, and generation logic
- integration tests that run real `composer install` flows

## License

MIT. See [LICENSE](https://github.com/codewithkyrian/platform-package-installer/blob/main/LICENSE).
