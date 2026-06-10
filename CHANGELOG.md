# Release Notes for Editor MCP

## Unreleased

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
