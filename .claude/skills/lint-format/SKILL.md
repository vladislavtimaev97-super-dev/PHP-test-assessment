---
name: lint-format
description: Check PHP syntax and style consistency in gamestore-core. Use when asked to lint the code, format it, check for syntax errors, or verify style before a commit.
---

There is **no linter or formatter configured** in this repo — no
PHP_CodeSniffer, php-cs-fixer, Psalm, or PHPStan in
[composer.json](../../../composer.json)'s `require-dev`, no `.editorconfig`,
no CI. Don't assume one exists; the only thing actually enforceable today
is PHP's own syntax checker.

## Syntax check (what actually exists)

Run inside the `tools` container (there's no host PHP install to lint
with):

```bash
docker compose --profile tools run --rm tools sh -c \
  "find src bin public public-supplier tests -name '*.php' -print0 | xargs -0 -n1 php -l"
```

Each file reports `No syntax errors detected` or a parse error with a file
and line number.

To check just the files touched by a change:

```bash
git diff --name-only --diff-filter=ACM -- '*.php' | \
  xargs -I{} docker compose --profile tools run --rm tools php -l {}
```

(Note: `{}` here is a host path — since the container mounts nothing by
default, prefer the `find`-inside-container form above for anything beyond
a quick single-file check, or use `docker compose --profile tools run --rm
-v "$(pwd):/app" tools php -l {}` if you need to lint one host path
directly.)

## Style — observed conventions, not enforced

The existing codebase is consistently PSR-4 autoloaded (`App\` →
`src/`), uses `declare(strict_types=1)` in every file, 4-space indentation,
and short one-line doc comments only where a non-obvious constraint needs
explaining (see `CLAUDE.md`'s own comment guidance). Match this by eye —
there is no automated check to run.

## If a formatter/linter is actually wanted

Adding one (e.g. `friendsofphp/php-cs-fixer` or `squizlabs/php_codesniffer`)
means a new `require-dev` entry in `composer.json` plus a config file
(`.php-cs-fixer.php` / `phpcs.xml`) and a `composer install` /
`docker compose build tools` afterwards — that's a real dependency change,
not a lint pass, so confirm with the user before doing it rather than
adding tooling silently.

## Gotchas

- `php -l` only catches syntax errors, not style or logic issues — don't
  report a clean `php -l` run as "linted", it isn't.
- Running `php -l` from the host will fail (no PHP installed there per
  [Dockerfile](../../../Dockerfile)) — it must go through the `tools`
  container.
