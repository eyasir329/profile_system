## What and why

<!-- One or two sentences: the change and the reason for it. -->

## How to check it

<!-- Steps or commands. Screenshots for UI changes (light and dark). -->

## Checklist

- [ ] `make lint` and `make test` pass
- [ ] New or changed behaviour is covered in `docs/` (and `CHANGELOG.md` under *Unreleased*)
- [ ] Who may see or change what is unchanged, or `docs/PERMISSIONS.md` is updated
- [ ] A new migration is in `database/migrations/` (and `deploy/infinityfree/install.sql` is regenerated if the schema changed)
- [ ] After editing `resources/` or Tailwind classes: `make assets` was run and the built files are committed
- [ ] No secrets, real data or `.env` values in the diff
