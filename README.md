# Editor MCP for Craft CMS

OAuth-authenticated Model Context Protocol server for Craft CMS. Lets AI assistants perform content operations **as a named editor**, with Craft-native permission enforcement.

> Different from `stimmt/craft-mcp`: that plugin is for engineers with shell access. This one is for **non-engineer content editors operating against staging or production over the network** — real identity, real consent, real audit log.

## What's in v1

- **OAuth 2.1** end-to-end: Authorization Code + PKCE, Dynamic Client Registration, refresh token rotation with theft detection.
- **14 MCP tools** in five capability groups (`content:read`, `content:write`, `content:publish`, `content:delete`, `assets:write`).
- **Per-user impersonation**: tool calls execute as the authenticated Craft user, so per-section and per-element permissions are enforced natively.
- **Audit log** (PII-conscious — structural params only by default).
- **Kill switch**, **DCR rate limiting**, **admin approval** workflow for new clients, **CP UI** for tokens / clients / audit / settings.

## Install

```sh
cd /path/to/your/craft/project
composer require westonhancock/craft-editor-mcp
./craft plugin/install editor-mcp
```

The plugin ships **disabled** in production. Enable it via Settings → Editor MCP → Settings, toggle **Enabled**, save.

## Connecting Claude Desktop

1. In Claude Desktop's settings, add a new MCP server:
   - **URL**: `https://your-craft-site.example/mcp`
2. Claude registers itself via DCR, then launches the OAuth flow in your browser.
3. Log into Craft (2FA enforced via your existing 2FA plugin).
4. Approve the consent screen. Tokens are issued.
5. Claude is now connected as you.

## The 14 tools

| Tool | Scope | Notes |
|---|---|---|
| `list_sections` | `content:read` | discovery |
| `list_section_fields` | `content:read` | discovery |
| `list_asset_volumes` | `content:read` | discovery |
| `find_entries` | `content:read` | filter / search entries |
| `get_entry` | `content:read` | full entry content |
| `create_entry` | `content:write` | defaults to draft; non-draft needs `content:publish` |
| `update_entry_fields` | `content:write` | strictly content — passing `status` is a validation error |
| `set_entry_status` | `content:publish` | the only way to change status |
| `delete_entry` | `content:delete` | soft delete |
| `find_assets` | `content:read` | filter / search assets |
| `get_asset` | `content:read` | full asset metadata |
| `upload_asset` | `assets:write` | base64, 25MB cap, server-side MIME validation |
| `list_categories` | `content:read` | by group |
| `get_global` | `content:read` | by handle |
| `who_am_i` | none | identity + scopes |

## Security at a glance

- **HTTPS-only** outside dev. HTTP redirect URIs allowed only for loopback per RFC 8252.
- **PKCE S256 only.** Plaintext code challenges are rejected.
- **Access token TTL capped at 1 hour** (non-configurable upward).
- **Refresh token rotation.** A consumed refresh token reused → entire chain revoked.
- **`prompt=login` forced** for `content:publish` and `content:delete` even with active CP session.
- **Per-user impersonation.** No service-account fallback. Documented invariant.
- **Scope ∧ Craft permission** for every call. Defense in depth: scope check happens before tool body runs.
- **DCR rate-limited** to 10/IP/day by default. Optional admin approval gate.
- **Tokens revoked** when a user is suspended, locked, pending, or deleted.
- **Kill switch.** One toggle revokes all tokens and serves 503 from every endpoint.

## Configuration

See **Settings → Editor MCP** in the CP. All defaults are production-conservative; you only need to flip **Enabled** to start.

## What this plugin deliberately does **not** ship

- DB query / Tinker / GraphQL execute tools
- User CRUD
- Plugin / project config management
- Log reading
- A service-account mode (every connection has a human owner)
- stdio transport (HTTP only — one auth path)

If a workflow needs any of those, it doesn't belong here. Use `stimmt/craft-mcp` for local dev, or the Craft CP directly.

## Status

v0.1.0 — alpha. v1 surface complete; needs integration test pass + 2FA plugin compat verification before tagging stable.

## License

MIT
