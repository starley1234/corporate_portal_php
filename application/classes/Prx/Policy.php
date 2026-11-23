<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Базовая политика доступа модуля.
 *
 * Разделение ответственности:
 *   - Prx_Actor::can()  — RBAC: «есть ли у пользователя ключ ACL»;
 *   - Policy            — объектные правила: «можно ли ему ЭТУ запись».
 *
 * Контроллер не пишет условия доступа сам — он спрашивает политику:
 *
 *   class Policy_Prx300_Employee extends Prx_Policy {
 *       public function view(Prx_Actor $a, array $row): bool {
 *           return $a->can('prx300.view')
 *               && ($a->isAdmin() || $a->organizationId() === (int) $row['organization_id']);
 *       }
 *   }
 *
 *   (new Policy_Prx300_Employee)->requireView($this->actor(), $row);
 *
 * Правила политики — единственное место, где сравниваются организация,
 * подразделение, площадка и владелец. Если сервис проверяет доступ сам,
 * дублировать эту логику нельзя — вызывайте политику.
 */
abstract class Prx_Policy
{
    /** Ключ ACL для чтения; NULL — чтение без отдельного права. */
    protected const VIEW_ACL = NULL;

    /** Ключ ACL для изменения; NULL — изменение без отдельного права. */
    protected const EDIT_ACL = NULL;

    /** Может ли actor прочитать запись. */
    public function view(Prx_Actor $actor, array $row): bool
    {
        return $this->allowed($actor, static::VIEW_ACL, $row);
    }

    /** Может ли actor изменить запись. */
    public function edit(Prx_Actor $actor, array $row): bool
    {
        return $this->allowed($actor, static::EDIT_ACL, $row);
    }

    /** Может ли actor удалить запись (по умолчанию — как изменение). */
    public function delete(Prx_Actor $actor, array $row): bool
    {
        return $this->edit($actor, $row);
    }

    /**
     * 403, если нет права на модуль; 404, если запись чужая (существование
     * не раскрываем). `$hide = FALSE` — всегда 403.
     */
    public function requireView(Prx_Actor $actor, array $row, bool $hide = TRUE): void
    {
        $this->deny($this->view($actor, $row), $hide, $actor, static::VIEW_ACL);
    }

    /** @see requireView() */
    public function requireEdit(Prx_Actor $actor, array $row, bool $hide = TRUE): void
    {
        $this->deny($this->edit($actor, $row), $hide, $actor, static::EDIT_ACL);
    }

    /** @see requireView() */
    public function requireDelete(Prx_Actor $actor, array $row, bool $hide = TRUE): void
    {
        $this->deny($this->delete($actor, $row), $hide, $actor, static::EDIT_ACL);
    }

    /** Общее правило: ACL + объектный предикат (переопределяется в модуле). */
    protected function allowed(Prx_Actor $actor, ?string $acl, array $row): bool
    {
        if ($actor->isGuest()) {
            return FALSE;
        }

        if ($acl !== NULL AND ! $actor->can($acl)) {
            return FALSE;
        }

        return $this->scope($actor, $row);
    }

    /** Объектная часть: по умолчанию админу можно всё, остальным — нет. */
    protected function scope(Prx_Actor $actor, array $row): bool
    {
        return $actor->isAdmin();
    }

    private function deny(bool $allowed, bool $hide, Prx_Actor $actor, ?string $acl): void
    {
        if ($allowed) {
            return;
        }

        // Нет права на весь модуль — честный 403, а не «не найдено».
        if ($acl !== NULL AND ! $actor->can($acl)) {
            throw HTTP_Exception::factory(403, 'Доступ запрещён');
        }

        throw HTTP_Exception::factory($hide ? 404 : 403, $hide ? 'Запись не найдена' : 'Доступ запрещён');
    }
}
