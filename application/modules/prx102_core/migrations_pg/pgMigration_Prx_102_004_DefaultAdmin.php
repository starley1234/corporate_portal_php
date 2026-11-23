<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Первичный администратор для чистой установки.
 *
 * Зачем миграция, а не `make seed`: seed-задача требует уже работающего
 * приложения, `AUTH_HASH_KEY` и введённого пароля. На новом стенде это лишний
 * шаг, из-за которого «make migrate» оставлял базу без входа в систему.
 *
 * Правила:
 *  * пользователь создаётся только на пустой базе — если в users ещё нет записей;
 *  * существующие пользователи и роли не меняются;
 *  * пароль хэшируется тем же способом, что Auth::hash() — hash_hmac с
 *    AUTH_HASH_KEY из окружения, поэтому вход работает сразу;
 *  * отключить автоматического администратора: PRX_DEFAULT_ADMIN=0.
 *
 * Пароль по умолчанию — admin. Это сознательный компромисс для локальной
 * установки: после первого входа его нужно сменить. Для production задайте
 * PRX_DEFAULT_ADMIN=0 и создайте администратора через `make seed`.
 */
class pgMigration_Prx_102_004_DefaultAdmin extends pgMigration
{
    const USERNAME = 'admin';
    const EMAIL    = 'admin@example.test';

    public function up()
    {
        if ($this->_disabled())
        {
            return;
        }

        $hash_key = (string) Kohana::$config->load('auth')->get('hash_key');

        if ($hash_key === '')
        {
            // Без ключа хэш не совпадёт с Auth::hash(): лучше не создавать
            // пользователя, чем создать неработающий вход.
            Kohana::$log->add(Log::WARNING, 'prx110: PRX_DEFAULT_ADMIN пропущен — пустой AUTH_HASH_KEY');
            return;
        }

        $db = Database::instance($this->_db_group);
        $password = (string) getenv('PRX_DEFAULT_ADMIN_PASSWORD');
        if ($password === '')
        {
            $password = 'admin';
        }

        $email = $this->_email();

        // Пароль не попадает в SQL: в запрос уходит только HMAC.
        $hash = hash_hmac('sha256', $password, $hash_key);

        $sql = "INSERT INTO users
                    (email, username, password, logins, email_confirmed, failed_login_attempts, created_at, updated_at)
                SELECT ".$db->quote($email).", ".$db->quote(self::USERNAME).", ".$db->quote($hash).",
                       0, 1, 0, now(), now()
                WHERE NOT EXISTS (SELECT 1 FROM users)
                ON CONFLICT DO NOTHING
                RETURNING id";

        $created = $this->run_query($sql, Database::SELECT)->current();

        if ( ! $created)
        {
            // База уже не пуста (или вставку остановил конфликт): миграция
            // идемпотентна и ничего существующего не перезаписывает.
            return;
        }

        $user_id = (int) $created['id'];

        $this->run_query("INSERT INTO user_profiles (user_id, first_name, full_name)
            SELECT ".$user_id.", 'Администратор', 'Администратор портала'
            WHERE NOT EXISTS (SELECT 1 FROM user_profiles WHERE user_id = ".$user_id.")");

        $this->run_query("INSERT INTO roles_users (user_id, role_id)
            SELECT ".$user_id.", r.id
            FROM roles r
            WHERE r.name IN ('login', 'admin')
              AND NOT EXISTS (
                  SELECT 1 FROM roles_users ru
                  WHERE ru.user_id = ".$user_id." AND ru.role_id = r.id
              )");
    }

    public function down()
    {
        // Удаляем только ту учётную запись, которую создаёт эта миграция.
        $db = Database::instance($this->_db_group);

        $this->run_query("DELETE FROM roles_users
            WHERE user_id IN (
                SELECT id FROM users
                WHERE username = ".$db->quote(self::USERNAME)."
                  AND email = ".$db->quote($this->_email())."
            )");

        $this->run_query("DELETE FROM user_profiles
            WHERE user_id IN (
                SELECT id FROM users
                WHERE username = ".$db->quote(self::USERNAME)."
                  AND email = ".$db->quote($this->_email())."
            )");

        $this->run_query("DELETE FROM users
            WHERE username = ".$db->quote(self::USERNAME)."
              AND email = ".$db->quote($this->_email()));
    }

    /**
     * Почта первичного администратора.
     */
    protected function _email()
    {
        $email = (string) getenv('PRX_DEFAULT_ADMIN_EMAIL');

        return $email === '' ? self::EMAIL : $email;
    }

    /**
     * Явное отключение: PRX_DEFAULT_ADMIN=0/false/no/off.
     */
    protected function _disabled()
    {
        $flag = strtolower(trim((string) getenv('PRX_DEFAULT_ADMIN')));

        return in_array($flag, ['0', 'false', 'no', 'off'], TRUE);
    }
}
