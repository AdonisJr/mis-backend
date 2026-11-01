# mis-backend — Quick Reference

> Full context, architecture and rules: [AGENTS.md](AGENTS.md). Read it first.

## Run

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

## Common commands

```bash
php artisan test          # tests (PHPUnit, SQLite :memory:)
./vendor/bin/pint         # format (./vendor/bin/pint --test to check only)
php artisan migrate       # run migrations
php artisan route:list    # list routes
composer run dev          # server + queue + pail + vite
composer run setup        # first-time setup
```

## Ports

| Service | Port |
|---|---|
| API (`php artisan serve`) | 8000 |

## Agents

- `be-developer` implements a plan: `claude --agent be-developer -p "Implement .claude/common/plans/active/<slug>/plan-be.md"`
- `be-auditor` audits staged changes before commit

Shared plans/rules/wiki come from the `mis` workspace via the `.claude/common` and `.claude/diagrams` symlinks. If they're broken, run `node tools/setup-links.js` in the workspace.
