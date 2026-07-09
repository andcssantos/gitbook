# TASK-000 — Security Baseline for Cursor

## Goal

Before implementing any gameplay module, update the project with a security-first development baseline.

## Files to Read

- `game-docs/00-game-vision.md`
- `game-docs/01-database-model.md`
- `game-docs/18-cursor-development-rules.md`
- `game-docs/19-security-server-authority.md`

## Task

Analyze the current PHP/HTML/JS framework and identify where security boundaries should be placed.

Do not implement gameplay yet.

Create a short report at:

`game-docs/tasks/TASK-000-security-baseline-report.md`

The report must answer:

1. Where is the single entry point of the application?
2. How are routes handled?
3. How are controllers structured?
4. How is authentication checked?
5. Is there CSRF protection?
6. How are JSON API requests validated?
7. Where should application services live?
8. Where should repositories live?
9. How should database transactions be created?
10. How should idempotency keys be stored and checked?
11. Where should audit logs be written?
12. What parts of the current framework are safe to keep?
13. What parts need to be improved before gameplay modules?
14. What is the recommended folder structure for the backend?
15. What is the recommended folder structure for frontend JS modules?

## Important Rules

Do not modify code.

Do not create migrations.

Do not implement gameplay.

Do not create UI.

This is an analysis-only task.

## Expected Result

A practical security baseline report that tells us whether the current PHP framework is ready for the first gameplay module or needs structural improvements first.
