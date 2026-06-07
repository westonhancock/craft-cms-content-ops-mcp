# Release Notes for Editor MCP

## Unreleased

### Fixed
- HTTPS-required guard in `OAuthController::guard()` was unreachable due to a `!host === 'localhost'` operator-precedence bug. The whole guard's `&&` chain was always false, so HTTP requests bypassed the check outside dev. Now correctly enforces HTTPS unless the host is `localhost`.
- Kill-switch and disabled-state guards across `OAuthController`, `ConsentController`, and `McpController` threw `yii\web\ServiceUnavailableHttpException`, which does not exist in Yii2. Replaced with `throw new HttpException(503, …)` so a 503 is actually returned instead of a class-not-found fatal.
- Dropped dead null-safety in `GetGlobalTool` and `ListSectionFieldsTool` where `getFieldLayout()` is non-nullable.
- Dropped dead `!Drafts::applyDraft()` check in `SetEntryStatusTool`.

### Changed
- Pinned `league/oauth2-server` to `^8.5` (was `^9.0`). 9.x requires `psr/http-message ^2.0`, which conflicts with `php-mcp/server`'s `react/http` chain on `^1.0`. 8.5.5 supports both PSR-7 v1 and v2.
- Repository implementations now match `league/oauth2-server` 8.5's untyped (mixed) interface parameters via PHPDoc instead of native typing.
- Added typed `@property` PHPDoc blocks to all `ActiveRecord` records for static analysis.
- Added `composer.json` `allow-plugins` block for `yiisoft/yii2-composer`, `craftcms/plugin-installer`, `dealerdirect/phpcodesniffer-composer-installer`, `phpstan/extension-installer`.
- Added `phpstan.neon` (level 5) and `ecs.php` (Craft 4 ruleset) so static analysis and code style run cleanly out of the box.

## 0.1.0 - 2026-06-03

### Added
- Initial implementation of Editor MCP plugin for Craft CMS.
