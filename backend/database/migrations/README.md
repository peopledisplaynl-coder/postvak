# Migrations

One SQL file per change, numbered: `002_add_snippets.sql`. Each file ends with
`INSERT INTO schema_migrations (version) VALUES ('002_add_snippets');`
Run them in order in phpMyAdmin. `schema.sql` already contains `001_initial`.
