# craft-cms-content-ops-mcp

An OAuth-authenticated MCP server plugin for Craft CMS that exposes a constrained set of content-operations tools to AI assistants, with per-user identity and Craft-native permission enforcement.

## Positioning

This plugin deliberately does **not** compete with `stimmt/craft-mcp`. That plugin is for engineers with shell access running AI against their dev environment — env-flag gating is appropriate there. This plugin targets **non-engineer content editors operating against staging or production over the network**, where real identity and authorization matter. Different product, different security model, different tool surface.

## Architecture

**Transport:** HTTP + SSE only. Served from a Craft controller at `/actions/editor-mcp/server`. No stdio support — stdio bypasses the auth model and creates a "shell access = root" loophole. Forcing every connection through HTTP keeps one auth path.

**Protocol:** MCP spec with OAuth 2.1 + PKCE + Dynamic Client Registration, per the formal MCP auth flow. Built on `mcp/sdk` (PHP MCP SDK) and `league/oauth2-server` (de facto PHP OAuth foundation, what Laravel Passport is built on).

**Identity binding:** every MCP session resolves to exactly one Craft user. Tool calls execute *as that user* — Craft's element queries run with the user's identity attached, so per-section and per-element permissions are enforced natively without parallel authorization code. This is the load-bearing security property.

**Authorization composition:** OAuth scope ∧ Craft permission. The token says "this user consented to these scopes for this client." Craft's permission system says "this user can edit these sections." Both must allow for a call to succeed.

## Tool surface (14 tools)

Intent-shaped, not API-shaped. Editorial verbs, not element-query DSL.

### Discovery (3)
- `list_sections` — read-only, what sections exist
- `list_section_fields` — read-only, what fields are on a section's entry types
- `list_asset_volumes` — read-only

### Entries (5)
- `find_entries(section, filters)` — search/filter entries the user can see
- `get_entry(id)` — full content
- `create_entry(section, type, fields, status='draft')` — status optional, defaults to `draft`. If caller passes anything other than `draft`, requires `content:publish` scope or fails.
- `update_entry_fields(id, field_updates)` — strictly content. No status param. Trying to include status in `field_updates` is a validation error, not silently dropped.
- `set_entry_status(id, status)` — explicit publish/unpublish/disable, requires `content:publish`

### Assets (3)
- `find_assets(volume, filters)`
- `get_asset(id)` — metadata + URL
- `upload_asset(volume, file_data_base64, folder)` — requires `assets:write`. Base64 capped at 25MB raw (~33MB encoded). Larger uploads go through CP.

### Categories / Globals (2)
- `list_categories(group)`
- `get_global(handle)`

### Meta (1)
- `who_am_i` — returns connected user identity + scopes. Helps the AI know its constraints, helps debugging.

### Explicitly excluded
DB query, schema dump, Tinker, cache management, plugin management, project config, user CRUD, log reading, GraphQL execute. If a workflow needs any of those, it doesn't belong in this plugin.

## OAuth scope design

Capability-grouped, not per-tool. Editors should see ~5 toggles on a consent screen, not 14:

- `content:read` — read entries, assets, categories, users, globals
- `content:write` — create/update entries and their fields
- `assets:write` — upload, replace, delete assets
- `content:publish` — change entry status to live (separate from write — publishing is a higher-trust action)
- `content:delete` — delete entries

Per-tool scopes are technically finer-grained, but in practice nobody consents to "list_entries but not get_entry." Cluster around editorial verbs.

The consent screen displays the scope name plus a plain-English description per scope. Static — no dynamically computed per-user reachable-section preview.

## Control panel UI

Three sections under Settings → Editor MCP:

- **Tokens** — list active tokens, columns for client, user, scopes, last used, expires, revoke button
- **Audit Log** — per-call log, filterable by user / tool / time / status
- **Configuration** — enable/disable per environment, allowed redirect URI patterns, rate limits, log retention, kill switch

Plus a standalone **OAuth consent screen** rendered when a client begins the auth flow — shows client name, scope toggles with plain-English descriptions, allow/deny.

## Auth flow (no SSO, 2FA required)

The OAuth authorize flow routes through Craft's normal login process:

1. MCP client begins authorization
2. Craft authorize endpoint checks for active CP session; if none, redirects to login
3. Email/password + 2FA via whichever 2FA plugin Craft uses (verify compatibility — most popular plugins hook the standard login flow)
4. Consent screen renders with scopes the client requested
5. User clicks allow → redirect back with authorization code
6. Client exchanges code for access token + refresh token

For high-stakes scopes (`content:publish`, `content:delete`), force `prompt=login` even with an active CP session. Reduces "browser tab sitting open while someone walks away" risk.

## Key decisions and tradeoffs

| Decision | Win | Cost |
|---|---|---|
| HTTP-only transport | Single auth path, no stdio loophole | Doesn't serve engineer-local workflows (use stimmt's plugin instead) |
| OAuth, not PATs | Real identity, browser-based UX for non-engineers | More code surface, OAuth lifecycle complexity |
| Capability scopes (~5) | Editor-comprehensible consent | Less fine-grained than max-paranoid per-tool scopes |
| Static consent screen | Simpler to build, scope meaning learned once | Loses dynamic "you'll be granting access to X sections" preview |
| Intent-shaped tools | Predictable, auditable, AI-composable | Some advanced workflows unreachable — intentional |
| Per-user impersonation | Permissions Just Work via Craft's native system | Tools must support being run as arbitrary users |
| Status as separate tool (not update field) | Clean audit, `content:write` can't accidentally publish | One more tool to implement |
| Inherit Craft roles | No parallel permission system | Editor and AI access tied together |
| No GraphQL execute | Auditable, predictable, defensible in security review | Advanced edge cases require shipping a new tool |
| No system-admin tools | Safe to expose broadly | Admin workflows must use CP directly or stimmt's plugin |
| Refresh token rotation | Detects token theft via reuse detection | More complex client logic, occasional benign re-auth |
| DCR open by spec | Standard MCP client UX, no manual provisioning | Requires rate limiting + abuse monitoring |
| Disabled by default in prod | Explicit opt-in, no accidental exposure | Onboarding friction first time |

## Security model

### Critical (v1 must-haves)

1. **OAuth 2.1 compliance only** — Authorization Code + PKCE. No implicit grant. No resource-owner-password. No client-credentials grant in v1 (forces human-in-the-loop consent for every connection).
2. **Short access token TTL** — 1 hour max, non-configurable upward.
3. **Refresh token rotation** — every refresh issues a new token, invalidates the old. If an invalidated token is ever presented, all tokens for that user are revoked (theft detection per RFC 6749 §10.4).
4. **DCR rate-limited** — 10 client registrations per IP per day. Optionally require admin pre-approval for new clients in production.
5. **HTTPS-only** — refuse to serve HTTP except in `dev` environment. Refuse non-HTTPS redirect URIs always.
6. **CSRF protection on consent screen** — standard Craft CP CSRF tokens plus PKCE binding the auth code to the originating client.
7. **Per-user impersonation, not service account** — tool calls execute as the resolved user. Documented invariant, enforced by the tool dispatcher.
8. **Scope enforcement at the tool level** — each tool declares its required scope as metadata; the dispatcher checks scope before invoking the tool body. Defense in depth: if a tool's internal permission check is missing, the scope check still stops it.
9. **PII-conscious audit logs** — log tool name and structural params (entry ID, section handle, field handles), not field *values*. Default-deny on content; opt-in verbose mode for debugging.
10. **Security event webhooks** — token revocation, user disable, repeated rate-limit hits → webhook to Slack/PagerDuty for incident response.
11. **`prompt=login` for high-stakes scopes** — `content:publish` and `content:delete` require fresh authentication even with active CP session.
12. **Asset upload validation** — server-side MIME validation (never trust client-claimed type), MIME allowlist per volume (configurable), processed through Craft's normal asset pipeline.

### Important (should-have)

13. **Optional IP allowlist** — as an additional layer, off by default. Useful for "MCP only via Cloudflare Access" deployments.
14. **Token introspection endpoint** (RFC 7662) — lets external monitoring tools validate tokens.
15. **DPoP support** (RFC 9449) — sender-constrained tokens that can't be replayed from another host. Optional in v1, recommended for enterprise deployments.
16. **Configurable scope-to-permission mapping** — admins can tighten beyond defaults. "Even with `content:write`, this scope can't touch the Press Releases section."
17. **Audit log export** — daily NDJSON dump for cold storage / compliance.

### Operational

18. **Kill switch** — one CP toggle revokes all tokens and disables OAuth endpoints. Incident response.
19. **Disabled by default in production** — explicit opt-in flag plus at least one admin-minted token required before anything works.
20. **Versioned tool catalog** — tools declare a version, clients negotiate. v1 clients don't break on v2 tool signature changes.
21. **Rate-limit anomaly alerts** — single user hitting rate limits >5x/hour fires a security event.
22. **CSP + frame-ancestors on consent screen** — prevent embedded-iframe consent attacks.

### Lifecycle hooks (token revocation)

- `craft\elements\User` after-save where `suspended` or `pending` flips on → revoke all tokens for that user
- `craft\elements\User` before-delete → revoke all tokens
- Group/permission changes don't need to revoke (per-call check catches them at runtime), but log a security event so audit shows "user X's permissions changed while token Y was active"

### Known residual risks

- **Prompt injection from content.** A malicious entry body could contain instructions the AI follows. The plugin can't prevent that — it's an AI-layer problem. But per-user impersonation contains the blast radius: a prompt-injected AI can only do what the connected user could do.
- **OAuth client impersonation.** A local app could register as "Claude Desktop" via DCR. Display names are non-authoritative; the consent screen shows the real `client_id` and `redirect_uri`. Educate, don't gate.
- **Scope creep over time.** Any future scope that exposes system admin breaks the "safe for non-engineers" promise. Hard discipline: such scopes get rejected at proposal time, no exceptions.

## Plugin layout

```
craft-cms-content-ops-mcp/
├── composer.json
├── src/
│   ├── Mcp.php                          # Plugin entry, registers events + components
│   ├── controllers/
│   │   ├── McpController.php            # POST /actions/editor-mcp/server (HTTP+SSE)
│   │   ├── OAuthController.php          # /authorize, /token, /register, /.well-known
│   │   └── ConsentController.php        # Renders consent screen, handles allow/deny
│   ├── services/
│   │   ├── TokenService.php             # league/oauth2-server wiring, rotation, revocation
│   │   ├── PermissionService.php        # Maps OAuth scopes → Craft permissions
│   │   ├── ImpersonationService.php     # Runs a callable as a Craft user
│   │   └── AuditService.php             # Per-call logging + retention
│   ├── tools/
│   │   ├── BaseTool.php                 # Resolves user, checks scope, runs as user, audits
│   │   ├── DiscoveryTools.php           # list_sections, list_section_fields, list_asset_volumes
│   │   ├── EntryTools.php               # find/get/create/update/set_status
│   │   ├── AssetTools.php               # find/get/upload
│   │   ├── ContentTools.php             # list_categories, get_global
│   │   └── MetaTools.php                # who_am_i
│   ├── models/
│   │   ├── OAuthClient.php
│   │   ├── AccessToken.php
│   │   ├── RefreshToken.php
│   │   └── AuditEntry.php
│   ├── migrations/
│   │   └── Install.php
│   └── events/
│       └── UserLifecycleHandler.php     # Revoke tokens on suspend/delete
└── templates/
    └── _cp/
        ├── consent.twig                  # OAuth authorize page
        ├── tokens/                       # Token list/revoke UI
        ├── audit/                        # Audit log browser
        └── settings.twig                 # Plugin configuration
```

Dependencies: `mcp/sdk`, `league/oauth2-server`, Craft 5.

## v0 (concept validation)

A minimal spine to prove the architecture works end-to-end before building out the full surface:

- OAuth flow end-to-end (authorize → token → refresh) with one tested client (Claude Desktop)
- Three tools: `find_entries`, `get_entry`, `update_entry_fields`
- Token list + revoke in CP
- Basic audit log
- Kill switch

This proves: a content editor can connect Claude Desktop, consent, ask the AI to update an entry, and see it in the audit log. Every other tool and feature is iteration on a known-good foundation.

## Resolved decisions (recap)

1. **No SSO, 2FA via existing Craft 2FA plugin** — flow routes through Craft login normally; verify 2FA plugin compatibility during build.
2. **Status handling** — `create_entry` accepts optional status (default `draft`, non-draft requires `content:publish`); `update_entry_fields` never accepts status; `set_entry_status` is the only way to publish/unpublish.
3. **No GraphQL execute tool** — dropped to keep the surface intent-shaped and auditable.
4. **Asset uploads via base64** — 25MB cap, larger files go through CP.
5. **Inherit Craft roles** — no separate "AI editor" role; scope ∧ Craft permission for every call.
6. **Static consent screen** — scope name + plain-English description, no dynamic per-user reachable-section preview.

Design locked. Ready to build.
