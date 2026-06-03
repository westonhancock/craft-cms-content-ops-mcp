<?php
declare(strict_types=1);

namespace westonhancock\editormcp\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createClientsTable();
        $this->createAccessTokensTable();
        $this->createRefreshTokensTable();
        $this->createAuthCodesTable();
        $this->createAuditTable();
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%editormcp_audit_entries}}');
        $this->dropTableIfExists('{{%editormcp_auth_codes}}');
        $this->dropTableIfExists('{{%editormcp_refresh_tokens}}');
        $this->dropTableIfExists('{{%editormcp_access_tokens}}');
        $this->dropTableIfExists('{{%editormcp_clients}}');
        return true;
    }

    private function createClientsTable(): void
    {
        $this->createTable('{{%editormcp_clients}}', [
            'id' => $this->primaryKey(),
            // Public client identifier given to the client app
            'clientId' => $this->string(64)->notNull(),
            // Hashed secret (bcrypt). Null for public clients (PKCE-only)
            'secretHash' => $this->string(255)->null(),
            'name' => $this->string(255)->notNull(),
            // JSON array of redirect URIs
            'redirectUris' => $this->text()->notNull(),
            // JSON array of scope strings consented at registration
            'allowedScopes' => $this->text()->notNull(),
            // Pending approval state for DCR when dcrRequireApproval is on
            'approved' => $this->boolean()->defaultValue(false)->notNull(),
            // IP that registered (for abuse forensics)
            'registeredFromIp' => $this->string(45)->null(),
            'isPublic' => $this->boolean()->defaultValue(true)->notNull(),
            'revoked' => $this->boolean()->defaultValue(false)->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%editormcp_clients}}', ['clientId'], true);
        $this->createIndex(null, '{{%editormcp_clients}}', ['registeredFromIp', 'dateCreated']);
    }

    private function createAccessTokensTable(): void
    {
        $this->createTable('{{%editormcp_access_tokens}}', [
            'id' => $this->primaryKey(),
            // JWT jti — opaque to the client
            'tokenId' => $this->string(80)->notNull(),
            'clientId' => $this->integer()->notNull(),
            'userId' => $this->integer()->notNull(),
            'scopes' => $this->text()->notNull(),
            'expiresAt' => $this->dateTime()->notNull(),
            'revokedAt' => $this->dateTime()->null(),
            'lastUsedAt' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%editormcp_access_tokens}}', ['tokenId'], true);
        $this->createIndex(null, '{{%editormcp_access_tokens}}', ['userId']);
        $this->createIndex(null, '{{%editormcp_access_tokens}}', ['expiresAt']);
        $this->addForeignKey(null, '{{%editormcp_access_tokens}}', ['clientId'],
            '{{%editormcp_clients}}', ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, '{{%editormcp_access_tokens}}', ['userId'],
            '{{%users}}', ['id'], 'CASCADE', 'CASCADE');
    }

    private function createRefreshTokensTable(): void
    {
        $this->createTable('{{%editormcp_refresh_tokens}}', [
            'id' => $this->primaryKey(),
            'tokenId' => $this->string(80)->notNull(),
            // Hashed refresh token value
            'tokenHash' => $this->string(255)->notNull(),
            'accessTokenId' => $this->integer()->null(),
            'clientId' => $this->integer()->notNull(),
            'userId' => $this->integer()->notNull(),
            'scopes' => $this->text()->notNull(),
            'expiresAt' => $this->dateTime()->notNull(),
            'revokedAt' => $this->dateTime()->null(),
            // For rotation chain — null = original. If a rotated token is reused, walk back via parentId and revoke all.
            'parentId' => $this->integer()->null(),
            'consumedAt' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%editormcp_refresh_tokens}}', ['tokenId'], true);
        $this->createIndex(null, '{{%editormcp_refresh_tokens}}', ['tokenHash']);
        $this->createIndex(null, '{{%editormcp_refresh_tokens}}', ['userId']);
        $this->addForeignKey(null, '{{%editormcp_refresh_tokens}}', ['clientId'],
            '{{%editormcp_clients}}', ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, '{{%editormcp_refresh_tokens}}', ['userId'],
            '{{%users}}', ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, '{{%editormcp_refresh_tokens}}', ['accessTokenId'],
            '{{%editormcp_access_tokens}}', ['id'], 'SET NULL', 'CASCADE');
    }

    private function createAuthCodesTable(): void
    {
        $this->createTable('{{%editormcp_auth_codes}}', [
            'id' => $this->primaryKey(),
            'codeId' => $this->string(80)->notNull(),
            'clientId' => $this->integer()->notNull(),
            'userId' => $this->integer()->notNull(),
            'redirectUri' => $this->string(2048)->notNull(),
            'scopes' => $this->text()->notNull(),
            'codeChallenge' => $this->string(255)->notNull(),
            'codeChallengeMethod' => $this->string(16)->defaultValue('S256')->notNull(),
            // Was prompt=login forced (high-stakes scope)?
            'forcedFreshLogin' => $this->boolean()->defaultValue(false)->notNull(),
            'expiresAt' => $this->dateTime()->notNull(),
            'consumedAt' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%editormcp_auth_codes}}', ['codeId'], true);
        $this->createIndex(null, '{{%editormcp_auth_codes}}', ['expiresAt']);
        $this->addForeignKey(null, '{{%editormcp_auth_codes}}', ['clientId'],
            '{{%editormcp_clients}}', ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, '{{%editormcp_auth_codes}}', ['userId'],
            '{{%users}}', ['id'], 'CASCADE', 'CASCADE');
    }

    private function createAuditTable(): void
    {
        $this->createTable('{{%editormcp_audit_entries}}', [
            'id' => $this->primaryKey(),
            'requestId' => $this->string(64)->notNull(),
            'userId' => $this->integer()->null(),
            'clientId' => $this->integer()->null(),
            'tokenId' => $this->integer()->null(),
            'tool' => $this->string(80)->null(),
            'scopes' => $this->text()->null(),
            // JSON: structural params only by default (entry id, section handle, field handles)
            'paramsStructural' => $this->text()->null(),
            // JSON: verbose values, only populated when AuditService verbose mode on
            'paramsVerbose' => $this->mediumText()->null(),
            'status' => $this->string(32)->notNull(),  // success | denied | error | rate-limited
            'errorCode' => $this->string(64)->null(),
            'errorMessage' => $this->string(1024)->null(),
            'ipAddress' => $this->string(45)->null(),
            'userAgent' => $this->string(512)->null(),
            'durationMs' => $this->integer()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%editormcp_audit_entries}}', ['userId', 'dateCreated']);
        $this->createIndex(null, '{{%editormcp_audit_entries}}', ['tool', 'dateCreated']);
        $this->createIndex(null, '{{%editormcp_audit_entries}}', ['status', 'dateCreated']);
        $this->createIndex(null, '{{%editormcp_audit_entries}}', ['requestId']);
    }
}
