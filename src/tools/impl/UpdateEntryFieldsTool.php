<?php

declare(strict_types=1);

namespace westonhancock\editormcp\tools\impl;

use Craft;
use craft\base\Element;
use craft\behaviors\DraftBehavior;
use craft\elements\Entry;
use westonhancock\editormcp\Plugin;
use westonhancock\editormcp\tools\Tool;
use westonhancock\editormcp\tools\ToolException;

class UpdateEntryFieldsTool implements Tool
{
    /** Name given to drafts this tool creates, so later edits can find and reuse them. */
    public const DRAFT_NAME = 'Editor MCP draft';

    public function name(): string
    {
        return 'update_entry_fields';
    }

    public function description(): string
    {
        return 'Update the title, slug, or custom fields of an entry. Changes to a canonical entry go into a draft, so live content does not change: '
            . 'the tool reuses your newest Editor MCP draft of that entry, or creates one. If id is already a draft, the tool updates that draft. '
            . 'To publish the changes, call set_entry_status with the returned id. applyToLive: true saves straight to the canonical entry and requires the content:publish scope. '
            . 'To change status, use set_entry_status. Passing status, enabled, postDate, or expiryDate in fields is a validation error.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'slug' => ['type' => 'string'],
                'fields' => ['type' => 'object', 'description' => 'Field handle → new value'],
                'siteId' => ['type' => 'integer'],
                'applyToLive' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Save straight to the canonical entry, skipping the draft. Requires content:publish.',
                ],
            ],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $args): array
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw new ToolException(-32602, 'id is required');
        }
        if (!isset($args['fields']) && !isset($args['title']) && !isset($args['slug'])) {
            throw new ToolException(-32602, 'Pass at least one of fields, title, slug');
        }

        // drafts(null) so by-id lookups match both drafts and canonical entries.
        $query = Entry::find()->id($id)->status(null)->drafts(null);
        if (isset($args['siteId'])) {
            $query->siteId((int) $args['siteId']);
        }
        /** @var Entry|null $entry */
        $entry = $query->one();
        if (!$entry) {
            throw new ToolException(-32008, "Entry not found: $id");
        }

        $applyToLive = ($args['applyToLive'] ?? false) === true;
        if ($applyToLive) {
            $scopes = Plugin::$plugin->toolRegistry->currentTokenClaims['scopes'] ?? [];
            if (!in_array('content:publish', $scopes, true)) {
                throw new ToolException(-32004, 'content:publish required to apply changes to live content');
            }
        }

        // Element-level authorization: canSave() folds in section save permission
        // plus the owner/peer/draft rules a bare saveEntries:<section> check misses.
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !Craft::$app->getElements()->canSave($entry, $user)) {
            throw new ToolException(-32004, 'No permission to edit this entry');
        }

        $reusedDraft = false;
        if (!$applyToLive && !$entry->getIsDraft()) {
            $draft = $this->findReusableDraft($entry, $user->id);
            if ($draft) {
                if (!Craft::$app->getElements()->canSave($draft, $user)) {
                    throw new ToolException(-32004, 'No permission to edit the existing draft of this entry');
                }
                $reusedDraft = true;
            } else {
                if (!Craft::$app->getElements()->canCreateDrafts($entry, $user)) {
                    throw new ToolException(-32004, 'No permission to create drafts of this entry');
                }
                $draft = Craft::$app->getDrafts()->createDraft($entry, $user->id, self::DRAFT_NAME);
            }
            $entry = $draft;
        }

        if (isset($args['fields']) && is_array($args['fields'])) {
            $this->guardForbiddenKeys($args['fields']);
            $valid = [];
            foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $f) {
                $valid[$f->handle] = true;
            }
            foreach (array_keys($args['fields']) as $handle) {
                if (!isset($valid[$handle])) {
                    throw new ToolException(-32016, "Unknown field handle for this entry type: $handle");
                }
            }
            $entry->setFieldValues($args['fields']);
        }
        if (isset($args['title'])) {
            $entry->title = (string) $args['title'];
        }
        if (isset($args['slug'])) {
            $entry->slug = (string) $args['slug'];
        }

        if ($entry->getIsDraft()) {
            // Drafts validate like the CP does: essentials only, full rules on publish.
            $entry->setScenario(Element::SCENARIO_ESSENTIALS);
        }
        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new ToolException(-32016, 'Failed to update entry', ['errors' => $entry->getErrors()]);
        }

        return [
            'id' => $entry->id,
            'isDraft' => $entry->getIsDraft(),
            'draftId' => $entry->draftId,
            'canonicalId' => $entry->getIsUnpublishedDraft() ? null : $entry->getCanonicalId(),
            'reusedDraft' => $reusedDraft,
            'title' => $entry->title,
            'slug' => $entry->slug,
            'status' => $entry->getStatus(),
            'updatedFields' => array_keys($args['fields'] ?? []),
        ];
    }

    /**
     * The newest draft of $canonical that this tool created for $userId, in the
     * same site. Drafts made in the CP or by other users are left alone.
     */
    private function findReusableDraft(Entry $canonical, int $userId): ?Entry
    {
        $drafts = Entry::find()
            ->drafts()
            ->draftOf($canonical)
            ->draftCreator($userId)
            ->siteId($canonical->siteId)
            ->status(null)
            ->orderBy(['elements.dateUpdated' => SORT_DESC])
            ->all();
        foreach ($drafts as $draft) {
            $behavior = $draft->getBehavior('draft');
            if ($behavior instanceof DraftBehavior && $behavior->draftName === self::DRAFT_NAME) {
                return $draft;
            }
        }
        return null;
    }

    private function guardForbiddenKeys(array $fields): void
    {
        foreach (['status', 'enabled', 'postDate', 'expiryDate'] as $forbidden) {
            if (array_key_exists($forbidden, $fields)) {
                throw new ToolException(
                    -32016,
                    "$forbidden cannot be set via update_entry_fields. Use set_entry_status (requires content:publish).",
                );
            }
        }
    }
}
