<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Отдельный контракт сопоставлений prl300.
 *
 * Auth остаётся владельцем users/ролей, HR-источник — владельцем сотрудника.
 * Этот сервис хранит только связи и внешние ключи, не добавляет полей в
 * users/user_profiles и не определяет права доступа сам по себе.
 */
class Service_Prl300_Identity extends Service_Prl300_Store
{
    public static function employee_id_for_user($user_id)
    {
        $row = self::_row("SELECT employee_id FROM prl_300_user_links
                           WHERE user_id = ".(int) $user_id." AND status = 'active' LIMIT 1");

        return $row ? (int) $row['employee_id'] : NULL;
    }

    public static function user_id_for_employee($employee_id)
    {
        $row = self::_row("SELECT user_id FROM prl_300_user_links
                           WHERE employee_id = ".(int) $employee_id." AND status = 'active' LIMIT 1");

        return $row ? (int) $row['user_id'] : NULL;
    }

    public static function employee_id_for_external($system_code, $external_id)
    {
        $system = self::_code($system_code, 'Код системы');
        $external_id = self::_text($external_id, 'Внешний идентификатор', 191, TRUE);
        $row = self::_row('SELECT employee_id FROM prl_300_external_identities
                           WHERE system_code = '.self::_q($system)
                            .' AND external_id = '.self::_q($external_id)." AND status = 'active' LIMIT 1");

        return $row ? (int) $row['employee_id'] : NULL;
    }

    public static function external_identity($employee_id, $system_code)
    {
        $system = self::_code($system_code, 'Код системы');
        $row = self::_row('SELECT * FROM prl_300_external_identities
                           WHERE employee_id = '.(int) $employee_id
                            .' AND system_code = '.self::_q($system)." AND status = 'active'
                           ORDER BY is_primary DESC, id DESC LIMIT 1");

        return $row ? self::_format_identity($row) : NULL;
    }

    public static function identities_for_employee(array $actor, $employee_id)
    {
        self::_require_admin_read($actor);
        self::_employee_or_fail((int) $employee_id, $actor);
        $rows = self::_all('SELECT * FROM prl_300_external_identities
                            WHERE employee_id = '.(int) $employee_id.'
                            ORDER BY system_code, is_primary DESC, id DESC');

        return array_map(function ($row) { return self::_format_identity($row); }, $rows);
    }

    public static function save_identity(array $actor, $employee_id, array $data, $identity_id = NULL)
    {
        self::_require_can($actor, 'can_identity', 'Нужна роль hr_admin для внешних идентификаторов');
        $employee = self::_employee_or_fail((int) $employee_id, $actor);
        $system = self::_code($data['system_code'] ?? '', 'Код системы');
        $external_id = self::_text($data['external_id'] ?? '', 'Внешний идентификатор', 191, TRUE);
        $external_login = self::_text($data['external_login'] ?? '', 'Внешний логин', 191);
        $status = (string) ($data['status'] ?? 'active');
        if ( ! in_array($status, ['active', 'disabled'], TRUE))
        {
            throw new InvalidArgumentException('Статус идентификатора: active или disabled');
        }
        $primary = ! empty($data['is_primary']) && $status === 'active';
        $valid_from = self::_date($data['valid_from'] ?? '', 'Действует с');
        $valid_to = self::_date($data['valid_to'] ?? '', 'Действует по');
        if ($valid_from !== '' AND $valid_to !== '' AND $valid_to < $valid_from)
        {
            throw new InvalidArgumentException('Дата окончания идентификатора раньше даты начала');
        }
        $source_updated = self::_datetime($data['source_updated_at'] ?? '', 'Обновлено источником');

        $duplicate = self::_row('SELECT employee_id FROM prl_300_external_identities
                                 WHERE system_code = '.self::_q($system)
                                  .' AND external_id = '.self::_q($external_id)
                                  .($identity_id ? ' AND id <> '.(int) $identity_id : '').' LIMIT 1');
        if ($duplicate)
        {
            throw new DomainException((int) $duplicate['employee_id'] === (int) $employee_id
                ? 'Такой внешний идентификатор у сотрудника уже есть'
                : 'Этот внешний идентификатор уже принадлежит другому сотруднику');
        }
        if ($status === 'active' AND $external_login !== '')
        {
            $login_duplicate = self::_row('SELECT id FROM prl_300_external_identities
                                           WHERE system_code = '.self::_q($system)
                                            .' AND lower(external_login) = '.self::_q(mb_strtolower($external_login))
                                            ." AND status = 'active'"
                                            .($identity_id ? ' AND id <> '.(int) $identity_id : '').' LIMIT 1');
            if ($login_duplicate)
            {
                throw new DomainException('Этот внешний логин уже используется в выбранной системе');
            }
        }

        $db = self::_db();
        $db->begin();
        try
        {
            if ($primary)
            {
                self::_execute('UPDATE prl_300_external_identities SET is_primary = FALSE
                                WHERE employee_id = '.(int) $employee_id
                                 .' AND system_code = '.self::_q($system)
                                 .($identity_id ? ' AND id <> '.(int) $identity_id : ''));
            }

            if ($identity_id)
            {
                $owned = self::_row('SELECT id FROM prl_300_external_identities
                                     WHERE id = '.(int) $identity_id.' AND employee_id = '.(int) $employee_id);
                if ( ! $owned)
                {
                    throw new DomainException('Внешний идентификатор не найден у этого сотрудника');
                }
                self::_execute('UPDATE prl_300_external_identities SET system_code = '.self::_q($system)
                    .', external_id = '.self::_q($external_id)
                    .', external_login = '.self::_q($external_login)
                    .', is_primary = '.self::_qbool($primary)
                    .', status = '.self::_q($status)
                    .', valid_from = '.self::_qn($valid_from)
                    .', valid_to = '.self::_qn($valid_to)
                    .', source_updated_at = '.self::_qn($source_updated)
                    .' WHERE id = '.(int) $identity_id);
                $id = (int) $identity_id;
            }
            else
            {
                $row = self::_row('INSERT INTO prl_300_external_identities
                    (employee_id, system_code, external_id, external_login, is_primary, status, valid_from, valid_to, source_updated_at)
                    VALUES ('.(int) $employee_id.', '.self::_q($system).', '.self::_q($external_id).', '
                        .self::_q($external_login).', '.self::_qbool($primary).', '.self::_q($status).', '
                        .self::_qn($valid_from).', '.self::_qn($valid_to).', '.self::_qn($source_updated).')
                    RETURNING id');
                $id = (int) $row['id'];
            }

            self::_audit($actor, (int) $employee['organization_id'], 'external_identity', $id,
                $identity_id ? 'external_identity.updated' : 'external_identity.created',
                ['employee_id' => (int) $employee_id, 'system_code' => $system, 'status' => $status]);
            $db->commit();

            return $id;
        }
        catch (Throwable $e)
        {
            $db->rollback();
            throw $e;
        }
    }

    public static function disable_identity(array $actor, $identity_id)
    {
        self::_require_can($actor, 'can_identity', 'Нужна роль hr_admin для внешних идентификаторов');
        $identity = self::_row('SELECT * FROM prl_300_external_identities WHERE id = '.(int) $identity_id);
        if ( ! $identity)
        {
            throw new DomainException('Внешний идентификатор не найден');
        }
        $employee = self::_employee_or_fail((int) $identity['employee_id'], $actor);
        self::_execute("UPDATE prl_300_external_identities
                        SET status = 'disabled', is_primary = FALSE
                        WHERE id = ".(int) $identity_id);
        self::_audit($actor, (int) $employee['organization_id'], 'external_identity', (int) $identity_id,
            'external_identity.disabled', ['employee_id' => (int) $identity['employee_id'], 'system_code' => (string) $identity['system_code']]);
    }

    public static function active_link(array $actor, $employee_id)
    {
        self::_require_admin_read($actor);
        self::_employee_or_fail((int) $employee_id, $actor);
        $row = self::_row("SELECT * FROM prl_300_user_links
                           WHERE employee_id = ".(int) $employee_id." AND status = 'active' LIMIT 1");

        return $row ? self::_format_link($row) : NULL;
    }

    public static function link_history(array $actor, $employee_id)
    {
        self::_require_admin_read($actor);
        self::_employee_or_fail((int) $employee_id, $actor);
        $rows = self::_all('SELECT * FROM prl_300_user_links
                            WHERE employee_id = '.(int) $employee_id.'
                            ORDER BY linked_at DESC, id DESC');

        return array_map(function ($row) { return self::_format_link($row); }, $rows);
    }

    /** Разрешает ID, username или email через публичную модель Auth. */
    public static function resolve_user($identifier)
    {
        $identifier = trim((string) $identifier);
        if ($identifier === '')
        {
            return NULL;
        }

        $user = ctype_digit($identifier)
            ? ORM::factory('User', (int) $identifier)
            : ORM::factory('User', ['username' => $identifier]);
        if ( ! $user->loaded() AND ! ctype_digit($identifier))
        {
            $user = ORM::factory('User', ['email' => $identifier]);
        }

        return $user->loaded() ? self::_user_info($user) : NULL;
    }

    public static function link_user(array $actor, $employee_id, $user_identifier, $via = 'manual', $note = '')
    {
        self::_require_can($actor, 'can_identity', 'Нужна роль hr_admin для сопоставления аккаунтов');
        $employee = self::_employee_or_fail((int) $employee_id, $actor);
        $user = self::resolve_user($user_identifier);
        if ($user === NULL)
        {
            throw new DomainException('Пользователь Auth не найден по ID, username или email');
        }
        $via = self::_text($via ?: 'manual', 'Способ сопоставления', 32, TRUE);
        if ( ! preg_match('/^[a-z0-9._-]+$/D', $via))
        {
            throw new InvalidArgumentException('Способ сопоставления: только a-z, 0-9 и разделители ._-');
        }
        $note = self::_text($note, 'Комментарий сопоставления', 255);

        $existing_employee = self::user_id_for_employee((int) $employee_id);
        $existing_user = self::employee_id_for_user((int) $user['id']);
        if ($existing_employee === (int) $user['id'] AND $existing_user === (int) $employee_id)
        {
            $row = self::_row("SELECT id FROM prl_300_user_links
                               WHERE employee_id = ".(int) $employee_id." AND status = 'active'");
            return (int) $row['id'];
        }
        if ($existing_employee !== NULL)
        {
            throw new DomainException('У сотрудника уже есть активная связь с другим пользователем');
        }
        if ($existing_user !== NULL)
        {
            throw new DomainException('Пользователь уже сопоставлен с другим сотрудником');
        }

        $actor_id = self::_actor_id($actor);
        $row = self::_row('INSERT INTO prl_300_user_links
            (employee_id, user_id, linked_via, linked_by_user_id, note)
            VALUES ('.(int) $employee_id.', '.(int) $user['id'].', '.self::_q($via).', '
                .($actor_id > 0 ? $actor_id : 'NULL').', '.self::_q($note).')
            RETURNING id');
        $id = (int) $row['id'];
        self::_audit($actor, (int) $employee['organization_id'], 'user_link', $id, 'user_link.created', [
            'employee_id' => (int) $employee_id,
            'user_id' => (int) $user['id'],
            'linked_via' => $via,
        ]);
        self::_event('prl300.employee.linked', [
            'employee_id' => (int) $employee_id,
            'organization_id' => (int) $employee['organization_id'],
            'user_id' => (int) $user['id'],
        ], $actor);

        return $id;
    }

    public static function unlink_user(array $actor, $employee_id, $reason = '')
    {
        self::_require_can($actor, 'can_identity', 'Нужна роль hr_admin для сопоставления аккаунтов');
        $employee = self::_employee_or_fail((int) $employee_id, $actor);
        $row = self::_row("SELECT * FROM prl_300_user_links
                           WHERE employee_id = ".(int) $employee_id." AND status = 'active' LIMIT 1");
        if ( ! $row)
        {
            return FALSE;
        }

        $reason = self::_text($reason, 'Причина отзыва связи', 255);
        self::_execute("UPDATE prl_300_user_links
                        SET status = 'revoked', revoked_at = now(), revoke_reason = ".self::_q($reason)
                        .' WHERE id = '.(int) $row['id']);
        self::_audit($actor, (int) $employee['organization_id'], 'user_link', (int) $row['id'], 'user_link.revoked', [
            'employee_id' => (int) $employee_id,
            'user_id' => (int) $row['user_id'],
        ]);
        self::_event('prl300.employee.unlinked', [
            'employee_id' => (int) $employee_id,
            'organization_id' => (int) $employee['organization_id'],
            'user_id' => (int) $row['user_id'],
        ], $actor);

        return TRUE;
    }

    protected static function _require_admin_read(array $actor)
    {
        if ( ! self::_can($actor, 'can_admin_view') AND ! self::_can($actor, 'can_manage')
            AND ! self::_can($actor, 'can_import') AND ! self::_can($actor, 'can_identity'))
        {
            throw new DomainException('Нужен HR-доступ к кадровым данным');
        }
    }

    protected static function _format_identity(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'employee_id' => (int) $row['employee_id'],
            'system_code' => (string) $row['system_code'],
            'external_id' => (string) $row['external_id'],
            'external_login' => (string) $row['external_login'],
            'is_primary' => $row['is_primary'] === TRUE OR $row['is_primary'] === 1
                OR in_array(strtolower((string) $row['is_primary']), ['1', 't', 'true', 'yes'], TRUE),
            'status' => (string) $row['status'],
            'valid_from' => (string) ($row['valid_from'] ?? ''),
            'valid_to' => (string) ($row['valid_to'] ?? ''),
            'source_updated_at' => (string) ($row['source_updated_at'] ?? ''),
        ];
    }

    protected static function _format_link(array $row)
    {
        $user = ORM::factory('User', (int) $row['user_id']);

        return [
            'id' => (int) $row['id'],
            'employee_id' => (int) $row['employee_id'],
            'user_id' => (int) $row['user_id'],
            'user' => $user->loaded() ? self::_user_info($user) : [
                'id' => (int) $row['user_id'], 'username' => '#'.(int) $row['user_id'], 'email' => '',
            ],
            'status' => (string) $row['status'],
            'linked_via' => (string) $row['linked_via'],
            'linked_by_user_id' => isset($row['linked_by_user_id']) ? (int) $row['linked_by_user_id'] ?: NULL : NULL,
            'linked_at' => (string) $row['linked_at'],
            'revoked_at' => (string) ($row['revoked_at'] ?? ''),
            'revoke_reason' => (string) ($row['revoke_reason'] ?? ''),
            'note' => (string) ($row['note'] ?? ''),
        ];
    }

    protected static function _user_info($user)
    {
        return [
            'id' => (int) $user->id,
            'username' => (string) $user->username,
            'email' => (string) $user->email,
        ];
    }
}
