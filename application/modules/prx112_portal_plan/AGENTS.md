# prx112_portal_plan — план и архив портала

- `/portal-plan` и `/prx112_portal_plan`: домены и запланированные сервисы из
  `config/portal.php` и `config/enterprise_plan.php` модуля; реализованные —
  через каталог `prx110`. Модуль не управляет подключением или ACL сервисов.
- `POST /portal-plan/approve`: глобальный CSRF и право `prx112.approve` (admin).
  `Service_Prx110_Publication` владеет решением о публикации и записью в БД.
  Состояние разработки остаётся в манифесте, а публикация не расширяет доступ.
