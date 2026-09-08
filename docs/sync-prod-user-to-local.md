# Sync prod user data to local

Export one user's database rows from production, import locally, then delete the prod payload.

## Checklist

**Local — clean schema**

```bash
herd php artisan migrate:fresh
```

**Prod — generate export** (Laravel Cloud console)

```bash
php artisan user:export-data your@email.com
```

Copy the download URL from the command output.

**Browser — download**

1. Log in to prod as that user
2. Open the download URL
3. Save the `.sql` file (e.g. `spendable-user-1-2026-09-07.sql`)

**Local — Import using Sequel Ace (GUI):**

1. Open Sequel Ace and connect to your local MySQL database with your credentials.
2. In the top of the window, select the `reconcilations_app` database.
3. Click `File` → `Import...` from the menu bar.
4. Browse to and select your downloaded `.sql` file (e.g. `spendable-user-1-2026-09-07.sql`).
5. Confirm any prompts/alerts that warn importing might overwrite data.
6. Wait for the import to complete—Sequel Ace will notify you when finished.

**Prod — remove payload**

```bash
php artisan user:delete-export your@email.com
```

## Notes

- Run `migrate:fresh` before import. The export contains `INSERT` statements only, not table definitions.
- You must be logged in as the exported user to download (the URL is not linked anywhere in the UI).
- The download URL expires after 24 hours.
- A new export replaces any previous export for the same user.
- Log in locally with your prod email and password.
- Uploaded import files on prod disk are not included—only database rows.
