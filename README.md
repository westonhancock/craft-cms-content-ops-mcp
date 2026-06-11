# Editor MCP for Craft CMS

OAuth-authenticated Model Context Protocol server for Craft CMS. Lets AI assistants perform content operations **as a named editor**, with Craft-native permission enforcement.

> Different from `stimmt/craft-mcp`: that plugin is for engineers with shell access. This one is for **non-engineer content editors operating against staging or production over the network** — real identity, real consent, real audit log.

## What's in v1

- **OAuth 2.1** end-to-end: Authorization Code + PKCE-S256, Dynamic Client Registration, refresh-token rotation with theft detection.
- **15 MCP tools** in five capability groups (`content:read`, `content:write`, `content:publish`, `content:delete`, `assets:write`) plus one scope-less identity tool.
- **Per-user impersonation**: tool calls execute as the authenticated Craft user, so per-section and per-element permissions are enforced natively.
- **Audit log** (PII-conscious — structural params only by default) records every call, successful or failed.
- **Kill switch**, **DCR rate limiting**, **admin approval** workflow for new clients, **CP UI** for tokens / clients / audit / settings.
- Requires **Craft 5.0+** and **PHP 8.2+**.

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
3. Log into Craft (Craft 5's built-in 2FA is enforced if you have it enabled for the user).
4. Approve the consent screen. Tokens are issued.
5. Claude is now connected as you.

For scopes marked high-trust (`content:publish`, `content:delete`), an in-band password re-confirmation page is shown if your CP session isn't freshly elevated — matches OAuth 2.1 `prompt=login` semantics.

## The 15 tools

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
- **Refresh token rotation.** A consumed refresh token reused → entire chain (parents + children + paired access tokens) revoked per RFC 6749 §10.4.
- **Elevated session forced** for `content:publish` and `content:delete`. Uses Craft's native `getHasElevatedSession()` (5-minute default), with an in-band password re-confirmation page if expired.
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

## Verified against

The OAuth + MCP flow has been exercised end-to-end against Craft 5.9 on Postgres 15, including:

- DCR client registration with PKCE-S256
- authorize → consent → code → token, across all five scopes
- refresh-token rotation
- refresh-token theft detection: consumed-token replay revokes the entire chain, including freshly-rotated descendants, and fires the security webhook
- the in-band elevation flow: expired elevated session + high-stakes scope → password re-confirmation page → consent (wrong-password re-prompt and open-redirect guard included)
- tool dispatch under impersonation for the full tool surface, including `delete_entry` and `upload_asset` (with MIME-spoof rejection)
- RFC 7009 `/oauth/revoke` with raw tokens of both types — revoking a refresh token also kills its paired access token; unknown tokens still return 200
- per-user rate limiting (429 inside the window) and the CIDR IP allowlist (403)
- audit log capture for every call, including failures and unexpected exceptions
- kill switch (503 from every endpoint when on)
- settings save round-trip (typed property coercion)
- CP UI: tokens table, audit log (both Postgres-safe)

Not yet exercised against a real install:

- 2FA interleave during the OAuth login redirect (the flow routes through Craft's standard login, which 2FA hooks into transparently, but this hasn't been observed live)

## Security event webhook

Set **Settings → Security event webhook** to a Slack/PagerDuty-compatible URL to get notified on:

| Event | Trigger |
|---|---|
| `refresh_token_theft_detected` | a consumed refresh token was replayed (chain revoked) |
| `dcr_rate_limited` | an IP exceeded the client-registration cap |
| `rate_limit_anomaly` | a user hit the MCP rate limit 5+ times in an hour |
| `user_tokens_revoked` | lifecycle revocation (suspended / locked / pending / deleted) |
| `kill_switch_activated` | an admin flipped the kill switch on |

The payload is generic JSON with a top-level `text` field, so a Slack incoming webhook renders it with zero config. Delivery is best-effort and never breaks the request that triggered it.

## Local development

The plugin is symlinked into a Craft site via a composer path repository for fast iteration. From the Craft site root:

```json
{
  "repositories": [
    {"type": "path", "url": "../craft-cms-content-ops-mcp", "options": {"symlink": true}}
  ],
  "require": {
    "westonhancock/craft-editor-mcp": "@dev"
  }
}
```

Then `composer update westonhancock/craft-editor-mcp` + `./craft plugin/install editor-mcp`. Edits to the source tree are live on the next request.

### Static analysis & code style

```sh
composer install
vendor/bin/phpstan analyse --memory-limit=1G   # level 5, clean
vendor/bin/ecs check                            # PSR-12 / Craft 4 ruleset
vendor/bin/ecs check --fix                      # apply auto-fixes
```

## Status

v0.2.1 — beta. v1 surface complete; every documented feature is implemented, enforced, and validated end-to-end against a real Craft 5 install (36 scripted checks). See CHANGELOG for the production-readiness and completion passes that informed the current state.

## License

MIT
