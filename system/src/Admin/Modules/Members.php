<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\ChangeLog;
use Talea\Admin\Module;
use Talea\Core\Members as MemberLogin;
use Talea\Core\PersonalData;
use Talea\Core\Response;

/**
 * Members (Member login extension, Core\Members): who may sign in, which groups they belong to, and the groups themselves. Pages, collection
 * items and news are restricted to groups in their own forms. Member addresses are personal data: only administrators see this screen, they
 * stay out of MCP, and Core\PersonalData finds, exports and erases them.
 */
final class Members extends Module
{
    public const string IDENT = 'members';
    public const string TABLE = 'members';
    public const string EXTENSION = 'members';
    public const string NAME = 'Members';
    public const string GROUP = 'Administration';
    public const string ICON = 'readers';
    public const bool ADMIN_ONLY = true;

    protected function actionList(): Response
    {
        $groups = MemberLogin::groups($this->db);
        $members = $this->db->all('SELECT * FROM {members} ORDER BY created_at DESC, member_id DESC LIMIT 1000');
        $memberGroups = [];
        foreach ($this->db->all('SELECT l.member_id, g.public_id FROM {member_group_links} l JOIN {member_groups} g ON g.group_id = l.group_id') as $row) {
            $memberGroups[(int) $row['member_id']][] = $row['public_id'];
        }

        return $this->view('list', 'Members', ['groups' => $groups, 'members' => $members, 'memberGroups' => $memberGroups, 'signup' => $this->app->settings()->get('member_signup'),
            'total' => (int) $this->db->value('SELECT COUNT(*) FROM {members}')]);
    }

    /** @return list<int> the groups ticked in the form */
    private function postedGroups(): array
    {
        return array_values(array_filter(array_map(fn (string $id): int => $this->db->internalId('member_groups', $id), $this->request->postList('groups'))));
    }

    protected function actionInvite(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        [$member, $error] = MemberLogin::invite($this->app, $this->request->post('email'), $this->request->post('name'), $this->postedGroups());
        if ($member === null) {
            return $this->back($error, type: 'error');
        }
        ChangeLog::write($this->app, 'members', 'invite', PersonalData::mask((string) $member['email']));

        return $this->back(t('An invitation has been sent to %s.', (string) $member['email']));
    }

    protected function actionSaveMember(): Response
    {
        $id = $this->idParam('member_id');
        $member = $this->db->one('SELECT * FROM {members} WHERE member_id = ?', [$id]);
        if (!$this->request->isPost() || $member === null) {
            return $this->back('The member does not exist.', type: 'error');
        }
        $this->db->update('members', ['name' => mb_substr(trim($this->request->post('name')), 0, 100)], ['member_id' => $id]);
        $this->db->delete('member_group_links', ['member_id' => $id]);
        foreach ($this->postedGroups() as $groupId) {
            $this->db->insertIgnore('member_group_links', ['member_id' => $id, 'group_id' => $groupId]);
        }
        ChangeLog::write($this->app, 'members', 'save', PersonalData::mask((string) $member['email']));

        return $this->back('Member saved. Access follows the groups from now on.');
    }

    protected function actionRemove(): Response
    {
        $member = $this->db->one('SELECT * FROM {members} WHERE member_id = ?', [$this->idParam('member_id')]);
        if ($this->request->isPost() && $member !== null) {
            $this->db->delete('members', ['member_id' => (int) $member['member_id']]); // sessions, sign-in links and memberships go with it: access ends at once
            ChangeLog::write($this->app, 'members', 'remove', PersonalData::mask((string) $member['email']));
        }

        return $this->back('The member has been removed and signed out everywhere.');
    }

    protected function actionGroupSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        [$group, $error] = MemberLogin::saveGroup($this->db, $this->request->post('name'), $this->idParam('group_id', 'member_groups'));
        if ($group === null) {
            return $this->back($error, type: 'error');
        }
        ChangeLog::write($this->app, 'members', 'group_save', (string) $group['name']);

        return $this->back('Group saved.');
    }

    protected function actionGroupDelete(): Response
    {
        $group = $this->db->one('SELECT * FROM {member_groups} WHERE group_id = ?', [$this->idParam('group_id', 'member_groups')]);
        if (!$this->request->isPost() || $group === null) {
            return $this->back();
        }
        $error = MemberLogin::deleteGroup($this->db, (int) $group['group_id']);
        if ($error !== '') {
            return $this->back($error, type: 'error');
        }
        ChangeLog::write($this->app, 'members', 'group_delete', (string) $group['name']);

        return $this->back('Group deleted. Its members stay.');
    }

    protected function actionSettings(): Response
    {
        if ($this->request->isPost()) {
            $mode = $this->request->post('member_signup') === 'open' ? 'open' : 'invited';
            $this->app->settings()->set('member_signup', $mode);
            ChangeLog::write($this->app, 'members', 'settings', 'sign-up: ' . $mode);
        }

        return $this->back('Settings saved.');
    }
}
