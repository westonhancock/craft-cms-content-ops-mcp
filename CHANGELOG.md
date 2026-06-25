# Release Notes for Editor MCP

## 0.1.1 - 2026-06-25

### Fixed

- `get_entry` now expands Matrix fields inline — each block is returned with its `type` and full (recursively serialized) field content, instead of bare `{id, title}` stubs. Fetching a nested block id directly no longer errors.

### Added

- Continuous integration (GitHub Actions): ECS, PHPStan, and a PHPUnit unit suite run on every push and pull request.

### Changed

- Licensed under the [Craft License](LICENSE.md) to match the plugin's paid Plugin Store listing (previously MIT). `composer.json` license set to `proprietary`.

## 0.1.0 - 2026-06-12

Initial release. An OAuth-authenticated MCP server for Craft CMS that lets AI
assistants perform content operations as a named editor, with Craft-native
permission enforcement.

### Added

- **15-tool content-ops surface** — discovery (`list_sections`, `list_section_fields`, `list_asset_volumes`), entries (`find_entries`, `get_entry`, `create_entry`, `update_entry_fields`, `set_entry_status`, `delete_entry`), assets (`find_assets`, `get_asset`, `upload_asset`), categories/globals (`list_categories`, `get_global`), and `who_am_i`. Intent-shaped, not API-shaped.
- **OAuth 2.1 + PKCE + Dynamic Client Registration** over HTTP/SSE — the formal MCP auth flow, built on `league/oauth2-server`. RFC 8414/9728 discovery, RFC 7591 DCR, RFC 7009 revocation.
- **Per-user identity binding** — every session resolves to exactly one Craft user; tool calls execute as that user, so per-section and per-element permissions are enforced natively. Authorization is OAuth scope ∧ Craft permission.
- **Capability-grouped scopes** — `content:read`, `content:write`, `content:publish`, `content:delete`, `assets:write` — surfaced on a plain-English consent screen.
- **High-stakes elevation** — `content:publish` / `content:delete` require a fresh elevated session via an in-band `/oauth/elevate` flow.
- **Refresh-token rotation with theft detection** — replaying a consumed token revokes the entire chain.
- **Control panel UI** — token list/revoke, audit log browser, and per-environment configuration. Ships disabled in production.
- **Security controls** — per-user rate limiting, optional IP allowlist, DCR approval gate and per-IP rate limit, server-side asset MIME validation, security-event webhooks, audit retention pruning, and a kill switch.

### Security

- Element-level authorization on every tool: reads gate on `canView()`, writes on `canSave()`, deletes on `canDelete()` — peer/draft restrictions included.
- `find_entries` `orderBy` is allowlisted to known columns resolved as Yii sort specs (no raw SQL expressions).
- Asset upload folder paths are validated against traversal, separators, and control characters.

### Verified

- End-to-end against a real Craft 5.9 install (Postgres and MySQL) — 36 scripted checks plus a limited-permission deny-path proof.
- PHPStan level 5 and ECS (PSR-12) clean.
