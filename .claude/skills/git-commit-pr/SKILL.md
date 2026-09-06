---
name: git-commit-pr
description: Commit staged changes and open a pull request for gamestore-core against the origin remote on GitHub. Use when asked to commit, push, open a PR, or create a pull request.
---

Repo remote: `origin` → `github-vladislav:vladislavtimaev97-super-dev/PHP-test-assessment.git`
(SSH host alias, not the literal `github.com`). Default/base branch: `main`.
**`gh` is not installed in this environment** (`gh --version` fails on both
the bash and PowerShell shells here) — use the `git` + web-UI fallback
below unless `gh` has since been installed.

Only commit/push/open a PR when the user has actually asked for it — never
proactively, and never force-push or amend a commit that's already been
pushed.

## Before committing

```bash
git status                 # never git add -A / git add . blindly — review what's staged
git diff                   # staged + unstaged changes
git log --oneline -10       # match this repo's commit message style
```

Stage specific files by name, not `git add -A`, so nothing unintended
(local `.env`, build artifacts) rides along.

## Commit

```bash
git add <specific files>
git commit -m "$(cat <<'EOF'
<one-line summary of the why, not a restatement of the diff>

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

Always a **new** commit — never `--amend` an existing one, even to "fix
up" the message, unless the user explicitly asks for an amend and the
commit hasn't been pushed yet.

## Push

```bash
git push -u origin <branch>
```

Never `--force` / `--force-with-lease` to `main` or any shared branch
without the user explicitly asking for it in that exact instance — a
prior approval doesn't carry forward to the next push.

## Open a pull request

**With `gh` (if installed):**

```bash
gh pr create --base main --title "<short, <70 chars>" --body "$(cat <<'EOF'
## Summary
- <bullet>

## Test plan
- [ ] docker compose --profile tools run --rm tools ./vendor/bin/phpunit
- [ ] docker compose --profile tools run --rm tools php bin/scenarios.php

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

**Without `gh` (current state of this environment):**

1. `git push -u origin <branch>` (above).
2. Hand the user the compare URL so they open it in a browser — for this
   remote's GitHub project that's
   `https://github.com/vladislavtimaev97-super-dev/PHP-test-assessment/compare/main...<branch>`
   — since the PR itself can't be created headlessly without `gh` or API
   credentials, don't attempt to fabricate one via raw `curl` to the
   GitHub API using the user's credentials without being asked to.

## Referencing test evidence in a PR body

Prefer citing what actually ran over generic checklists — this repo has
concrete, fast-to-run verifications: `make test`, `make scenarios`, `make
reconcile`. If you ran any of them this session, name the actual result
(pass/fail counts), not just the command.

## Gotchas

- The remote host alias (`github-vladislav`) implies a per-account SSH
  config on this machine — `git push` will fail with a host-resolution
  error if that SSH alias isn't configured in whatever environment this
  runs in; that's an SSH config issue, not a repo issue.
- `main` is both the local checkout and the PR base — double-check
  `git branch --show-current` isn't already `main` before trying to open
  a PR from it (a PR needs a feature branch).
