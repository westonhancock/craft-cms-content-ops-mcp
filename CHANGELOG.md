# Release Notes for Editor MCP

## 0.2.1 - 2026-10-20

### Changed

- The README and the CHANGELOG are now in ASD-STE100 (Simplified Technical English). The README procedures are now numbered steps.
- The README now says the correct time of the password check for `content:publish` and `content:delete`. Craft asks for the password one time, when you approve the connection.
- The README now lists the Clients page.

## 0.2.0 - 2026-10-20

### Added

- The OAuth keys can now come from environment variables. Use the new `privateKey` and `encryptionKey` settings in `config/editor-mcp.php`. Some hosts clear `storage/` at each deploy. On these hosts, connected assistants are no longer signed out after a deploy. If the settings are empty, the plugin uses `storage/editor-mcp/keys/`, so current installs do not change.
- `php craft editor-mcp/keys/generate` prints the two env lines. With `--from-storage`, the command exports the current keys, so no user is signed out when you change to env vars.

### Fixed

- The Control Panel sidebar no longer shows a second "Editor MCP" item without an icon. The plugin registered its nav item two times: one time through `hasCpSection`, and one time through `EVENT_REGISTER_CP_NAV_ITEMS`. Now it registers the item one time through `getCpNavItem()`, with the icon and the subnav.
- On the Tokens page, the text in the Scopes column no longer shows one character on each line. The cause was Craft's `.status` element, which is a small dot. Now each scope shows as a `<code>` label.

## 0.1.1 - 2026-06-25

### Fixed

- `get_entry` now shows the content of Matrix fields. Each block has its `type` and all of its field content, at all levels. Before, the tool gave only `{id, title}` for each block. When you get a nested block by its id, the tool no longer gives an error.

### Added

- Continuous integration (GitHub Actions). ECS, PHPStan, and a PHPUnit unit suite run at each push and pull request.

### Changed

- The license is now the [Craft License](LICENSE.md), because the plugin is a paid Plugin Store item. The previous license was MIT. The `composer.json` license is now `proprietary`.

## 0.1.0 - 2026-06-12

First release. Editor MCP is an MCP server for Craft CMS that uses OAuth. AI assistants can do content work as a named editor. Craft permissions apply to each action.

### Added

- **15 content tools.** Discovery: `list_sections`, `list_section_fields`, `list_asset_volumes`. Entries: `find_entries`, `get_entry`, `create_entry`, `update_entry_fields`, `set_entry_status`, `delete_entry`. Assets: `find_assets`, `get_asset`, `upload_asset`. Categories and globals: `list_categories`, `get_global`. Identity: `who_am_i`. Each tool does one editor task. The tools do not copy the Craft API.
- **OAuth 2.1 with PKCE and Dynamic Client Registration** over HTTP/SSE. This is the MCP auth flow, and it uses `league/oauth2-server`. The plugin supports RFC 8414 and RFC 9728 discovery, RFC 7591 client registration, and RFC 7009 revocation.
- **One Craft user for each session.** Each tool call runs as that user, so Craft applies its section and element permissions. An action must have the OAuth scope and the Craft permission.
- **Scopes in groups.** The scopes are `content:read`, `content:write`, `content:publish`, `content:delete`, and `assets:write`. The consent screen shows them in plain English.
- **Password check for high-risk scopes.** For `content:publish` and `content:delete`, the user must enter the password again through the `/oauth/elevate` flow.
- **Refresh token rotation.** If a used refresh token is sent again, the plugin revokes all tokens in that chain.
- **Control Panel pages.** Token list and revocation, an audit log, and configuration for each environment. The plugin is disabled after you install it.
- **Security controls:**
  - Rate limits for each user
  - An optional IP allowlist
  - An approval gate and a per-IP limit for client registration
  - MIME checks for asset uploads on the server
  - Webhooks for security events
  - Deletion of old audit entries
  - A kill switch

### Security

- Each tool checks permissions on each element. Reads use `canView()`, writes use `canSave()`, and deletes use `canDelete()`. These checks include peer and draft restrictions.
- `find_entries` accepts only known columns for `orderBy`. The plugin converts them to Yii sort specs and does not accept raw SQL.
- The plugin checks asset upload folder paths for traversal, path separators, and control characters.

### Verified

- Tested end to end on a Craft 5.9 install with Postgres and with MySQL. The tests had 36 scripted checks, and one more test proved that a user with limited permissions gets a deny.
- PHPStan level 5 and ECS (PSR-12) show no errors.
