<?php

declare(strict_types=1);

namespace Talea\Mcp\Handlers;

use Talea\Admin\ChangeLog;
use Talea\Core\Members;

/**
 * MCP tools of the member login (HF-33, Core\Members): the groups and which content is restricted to which groups. Member addresses and
 * invitations are not here on purpose – they are personal data and people are invited by the owner in Members. Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait MemberTools
{
    /** list_member_groups */
    private function toolListMemberGroups(string $name, array $a): mixed
    {
        $this->memberAdmin();
        $groups = array_map(fn (array $g): array => ['id' => (int) $g['group_id'], 'name' => $g['name'], 'members' => (int) $g['members'], 'restricted_content' => (int) $g['contents']],
            Members::groups($this->app->db()));

        return ['groups' => $groups, 'sign_up' => $this->app->settings()->get('member_signup'),
            'next' => 'Restrict content with set_content_groups. People are invited by the owner under Members in the administration – member addresses are not available here.'];
    }

    /** save_member_group */
    private function toolSaveMemberGroup(string $name, array $a): mixed
    {
        $this->memberAdmin();
        [$group, $error] = Members::saveGroup($this->app->db(), (string) ($a['name'] ?? ''), (int) ($a['id'] ?? 0));
        if ($group === null) {
            throw new \InvalidArgumentException($error);
        }
        ChangeLog::write($this->app, 'members', 'group_save', (string) $group['name']);

        return ['group' => ['id' => (int) $group['group_id'], 'name' => $group['name']]];
    }

    /** delete_member_group */
    private function toolDeleteMemberGroup(string $name, array $a): mixed
    {
        $this->memberAdmin();
        $group = $this->app->db()->one('SELECT * FROM {member_groups} WHERE group_id = ?', [(int) ($a['id'] ?? 0)]);
        if ($group === null) {
            throw new \InvalidArgumentException('The group does not exist. Use list_member_groups.');
        }
        $error = Members::deleteGroup($this->app->db(), (int) $group['group_id']);
        if ($error !== '') {
            throw new \DomainException($error);
        }
        ChangeLog::write($this->app, 'members', 'group_delete', (string) $group['name']);

        return ['deleted' => (int) $group['group_id']];
    }

    /** set_content_groups */
    private function toolSetContentGroups(string $name, array $a): mixed
    {
        $type = (string) ($a['type'] ?? '');
        if (!isset(Members::TYPES[$type])) {
            throw new \InvalidArgumentException('type must be page, item or news.');
        }
        if (!$this->app->auth()->hasModule(Members::TYPES[$type])) {
            throw new \DomainException('This user has no access to that section.');
        }
        $db = $this->app->db();
        $table = ['page' => ['pages', 'page_id'], 'item' => ['collection_items', 'item_id'], 'news' => ['news', 'news_id']][$type];
        $id = (int) ($a['id'] ?? 0);
        if ($db->value('SELECT 1 FROM {' . $table[0] . '} WHERE ' . $table[1] . ' = ? AND deleted_at IS NULL', [$id]) === null) {
            throw new \InvalidArgumentException('The ' . $type . ' does not exist.');
        }
        $groups = array_values(array_filter(array_map('intval', is_array($a['groups'] ?? null) ? $a['groups'] : [])));
        foreach ($groups as $groupId) {
            if ($db->value('SELECT 1 FROM {member_groups} WHERE group_id = ?', [$groupId]) === null) {
                throw new \InvalidArgumentException('A group does not exist. Use list_member_groups.');
            }
        }
        Members::setContentGroupIds($db, $type, $id, $groups);
        \Talea\Front\Cache::clear();
        ChangeLog::write($this->app, 'members', 'gating', $type . ' #' . $id . ': ' . ($groups === [] ? 'public' : count($groups) . ' group(s)'));

        return ['type' => $type, 'id' => $id, 'groups' => $groups,
            'next' => $groups === [] ? 'The content is public again.' : 'Only members of these groups can read it; it is never cached, indexed or searchable.'];
    }

    /** Groups belong to the owner: administrators only. */
    private function memberAdmin(): void
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('Only an administrator can manage member groups.');
        }
    }
}
