# Fix login crash - DB migration

- DB schema version bumped 4 -> 5.
- Upgrade no longer creates category/sales indexes before legacy columns exist.
- Legacy `products` columns used by native POS are added in-place.
- Native sales revision/return columns are added in-place.
- Login catches local DB/API errors and displays them instead of terminating the process.
- Removed click-to-login listener from the whole login form; only Login button / password IME submit triggers authentication.
