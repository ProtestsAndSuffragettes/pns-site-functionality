# P&S Site Functionality

Site-specific WordPress functionality for Protests & Suffragettes, independent of the theme.

Adds **Draft Editor**: edit your own and other users' post drafts, without publishing, scheduling, deleting, or editing published posts. Assign the role from a user's profile.

## Requirements

- WordPress 7.0+
- PHP 8.4–8.5

## Development

Requires Composer 2, Node.js 24.21.0, and pnpm 11.13.1.

```sh
composer install
pnpm install --frozen-lockfile
composer check
pnpm check
```

## Packaging

```sh
pnpm release:archive:check
pnpm release:archive
```

Archives are written to `dist/`. Release Please manages versioning and GitHub releases.

## License

MIT. See [LICENSE](LICENSE).
