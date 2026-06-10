# Release Notes for Editor MCP

## Unreleased

### Fixed (2026-06-10, post-completion-pass)

- **Dynamic client registration broke strict MCP clients (Claude Code)**: the DCR response included `"client_secret": null` for public clients. RFC 7591 §3.2.1 requires the field to be omitted when no secret is issued, and the MCP TypeScript SDK rejects a non-string value — Claude Code silently re-registered in a loop and never opened the authorization page. The field is now omitted for public clients, and `client_secret_expires_at: 0` accompanies it for confidential ones.
- **Added the RFC 9728 path-appended protected-resource metadata route** (`/.well-known/oauth-protected-resource/mcp`). MCP clients query this form first; it previously 404'd with an HTML page before clients fell back to the root well-known URL.

### Completion pass (2026-06-10)

Closed every gap left open by the previous validation pass. All previously
untested paths are now exercised end-to-end against the real Craft 5.9 /
Postgres install (36 scripted checks, all passing), and three settings that
existed in the UI but were never enforced are now wired up.

#### Security

- **Refresh-token chain revocation was a no-op**: `parentId` was never set when persisting rotated refresh tokens, so theft detection only revoked the *replayed* token — the attacker's freshly-rotated descendant tokens survived. League calls `revokeRefreshToken(old)` before `persistNewRefreshToken(new)` in the same request, so the repository now captures the consumed token's record id and links the new token to it. Verified: replaying a consumed token now kills the entire chain, including the rotated child.
- **`/oauth/revoke` now implements RFC 7009 correctly**: it previously looked tokens up by JTI / refresh-token id, but clients send the *raw* token. Access tokens are parsed as JWTs (signature verified against our public key, revoked by `jti`); refresh tokens are decrypted (Defuse, server encryption key) and revoked by `refresh_token_id` — which also kills the paired access token. Unknown tokens still return 200 per the spec.
- **Security event webhook actually fires now** (`Settings::$securityWebhookUrl` was a dead setting). New `SecurityEventService` posts Slack-compatible JSON on: `refresh_token_theft_detected`, `dcr_rate_limited`, `rate_limit_anomaly`, `user_tokens_revoked` (suspend/lock/pending/delete), and `kill_switch_activated`. Delivery is best-effort — failures log, never break the triggering request. Theft detection also writes an audit row now.
- **Per-user MCP rate limiting enforced** (`rateLimitPerUserPerMinute` was a dead setting). Fixed one-minute windows in the app cache; 429 over the limit; a user rejected 5+ times in an hour fires a `rate_limit_anomaly` security event once per hour.
- **IP allowlist enforced** (`ipAllowlist` was a dead setting). CIDR-matched against the MCP transport endpoint; browser-facing OAuth pages deliberately stay reachable so humans can still consent.

#### Reliability

- **Audit retention pruning never ran** — `AuditService::prune()` existed but had no caller. Now hooked into Craft's garbage-collection cycle (`Gc::EVENT_RUN`).

#### Verified end-to-end (previously untested)

- `delete_entry` — soft-deletes drafts and canonical entries; deleted entries no longer fetchable.
- `upload_asset` — base64 round-trip into a Local volume, `get_asset` URL back; MIME-spoof (shell script named `.png`) rejected by server-side detection.
- In-band elevation flow — expired elevated session + high-stakes scope renders the password page; wrong password re-prompts; open-redirect guard rejects foreign continuation URLs; correct password resumes straight into consent → code → token.
- RFC 7009 revoke for both raw token types, including paired-access-token death.
- Rate limit: under-limit requests pass, 429 inside the window, stays limited.
- IP allowlist 403, theft-detection webhook delivery.

#### Packaging

- `LICENSE.md` (MIT) added — composer.json already declared MIT but the file was missing (required for Plugin Store submission).

### Production-readiness pass (2026-06-07)

End-to-end validation pass against a real Craft 5.9 install on Postgres.
Found and fixed thirteen distinct bugs across the OAuth flow, MCP transport,
audit log, CP UI, and tooling. Plugin now works for the documented v1
surface: DCR → authorize → consent → token exchange → tool dispatch → audit,
including refresh-token rotation with theft detection and high-stakes
scope re-authentication.

### Security

- **HTTPS guard was unreachable**: `OAuthController::guard()` had a `!host === 'localhost'` precedence bug, so the whole `&&` chain was always false and HTTP requests bypassed the guard outside dev. Now correctly enforces HTTPS unless the host is `localhost`.
- **`/oauth/authorize` rejected anonymous callers with 403** instead of running the inline "redirect to login" logic. `authorize` is now in `$allowAnonymous`, matching the OAuth spec requirement that the endpoint be reachable before login.
- **Refresh-token rotation never tripped theft detection**: `revokeRefreshToken` (the method League calls during every successful rotation) was setting both `consumedAt` and `revokedAt`, so the replay-detection check (`consumed && !revoked`) never fired. Split the semantics — `revokeRefreshToken` marks consumed only; a new `forceRevoke` method sets `revokedAt` for explicit/admin revocation paths. Now matches RFC 6749 §10.4: replaying a consumed refresh token revokes the entire chain.
- **High-stakes elevation flow** (`content:publish`, `content:delete`) used to redirect to the CP login, which bounced the already-authenticated user to the dashboard — silently killing the OAuth flow. Replaced with an in-band password re-confirmation page (`/oauth/elevate`) and switched the freshness check to Craft's native `getHasElevatedSession()`. Open-redirect protected: continuation URL must start with our own `/oauth/authorize`.

### Reliability

- **`yii\web\ServiceUnavailableHttpException` does not exist** — kill-switch and disabled-state guards across three controllers were throwing a class that doesn't exist, so the 503 paths fatal'd instead of returning a proper status code. Replaced with `HttpException(503, …)`.
- **`PermissionService::requiredScope()` swallowed scope-less tools**: used `TOOL_SCOPES[$tool] ?? throw`, but the map stores `null` for `who_am_i`, so every `who_am_i` call threw "Unknown tool". Switched to `array_key_exists` so missing-key and null-value are distinguished.
- **CP login redirect went to the front-end `/login`** (which doesn't route to anything and fell through to the home template). Now redirects to the CP login.
- **Settings save form 500s on every typed field**: `actionSave` assigned form-posted strings directly to `Settings`' typed `bool`/`int` properties. PHP 8 `TypeError`s. Added reflection-based property coercion (`bool`/`int`/`string`/`array`-aware, nullable-aware).
- **Unexpected exceptions from tool paths bypassed the audit log**: only `ToolException` was caught. Any other `\Throwable` returned `-32603` to the client with no audit row. Added a catch-all that writes an `error/internal` audit row before re-throwing — required adding a `$previous` param to `ToolException`'s constructor.

### Compatibility

- **Drafts unreachable to by-id lookups**: `get_entry`, `update_entry_fields`, `set_entry_status`, and `delete_entry` queried `Entry::find()->id($id)->status(null)`, which defaults to `drafts(false)` in Craft 5 — so a freshly-created entry (returned by `create_entry` as a draft) could not be fetched, updated, published, or deleted by its own id. Added `->drafts(null)` so by-id lookups match both drafts and canonical entries.
- **Mixed-case columns broke on Postgres**: raw SQL fragments in the tokens and audit CP queries (`'c.id = t.clientId'`) had unquoted identifiers, which Postgres folds to lowercase (`t.clientid`) — column doesn't exist. Switched to Yii's `[[bracketed]]` quoting helper.
- **`league/oauth2-server` constraint** widened from `^9.0` to `^8.5`. 9.x requires `psr/http-message ^2.0`, conflicting with `php-mcp/server`'s `react/http` PSR-7 v1 chain. 8.5.5 supports both PSR-7 versions and is otherwise interface-compatible.
- **Repository implementations** had narrower parameter types than League 8.5's untyped (`mixed`) interface signatures — LSP violation. Switched to PHPDoc-only types so contravariance is preserved.
- **Composer `allow-plugins` block** added for `yiisoft/yii2-composer`, `craftcms/plugin-installer`, `dealerdirect/phpcodesniffer-composer-installer`, `phpstan/extension-installer`. Without it `composer install` halts on a Yii2 composer-plugin prompt.

### Twig / CP

- **`templates/oauth/consent.twig` couldn't be found**: the plugin didn't register a template root, so site- and CP-mode lookups for the `editor-mcp` namespace both failed. Added handlers for both `View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS` and `View::EVENT_REGISTER_CP_TEMPLATE_ROOTS`.
- **Consent template extended `_layouts/cp` but rendered in site mode**: that layout only resolves in CP template mode. `actionAuthorize` now passes `View::TEMPLATE_MODE_CP`.
- **Consent template had `fullPageForm = true`**: the CP layout wraps the page body in an outer form, so the inner consent form (posting to `/oauth/consent`) was nested — silently dropped by browsers. Clicking Allow submitted the outer CP form back to the current URL. Removed.
- **CP nav icon used `@editor-mcp/icon-mask.svg`** but the alias isn't auto-registered in Craft 5 (the plugin's `basePath` points at `src/`, the icon lives at the repo root). Logged "Invalid path alias" on every CP page load. Replaced with `dirname(__DIR__) . '/icon-mask.svg'`.

### Tooling

- **PHPStan level 5** added (`phpstan.neon` + `craftcms/phpstan` ruleset). All 132 starting findings fixed.
- **ECS** added (`ecs.php` + `craftcms/ecs` Craft 4 ruleset, PSR-12). 52 style findings auto-fixed.
- Typed `@property` PHPDoc blocks added to all five `ActiveRecord` records.
- Dropped dead null-safety in `GetGlobalTool` and `ListSectionFieldsTool` (`getFieldLayout()` is non-nullable in Craft 5).
- Dropped dead `!Drafts::applyDraft()` check in `SetEntryStatusTool`.

### Added

- **`/oauth/elevate` endpoint** + `templates/oauth/elevate.twig`. Used in-band when a user requests a high-stakes scope without a fresh elevated session.
- **`RefreshTokenRepository::forceRevoke()`** for explicit revocation paths.

## 0.1.0 - 2026-06-03

### Added
- Initial implementation of Editor MCP plugin for Craft CMS.
