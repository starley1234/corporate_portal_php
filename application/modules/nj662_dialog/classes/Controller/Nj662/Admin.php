<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-662: сводка всех диалогов сайта (/dialog/all) — страница админа
 * (решение владельца 09.10.2026).
 *
 * Зачем отдельная страница, если есть каталог: каталог (/dialog/list) показывает
 * только ОПУБЛИКОВАННОЕ и только то, что владелец решил показать людям. Здесь
 * наоборот — рабочая сводка: все диалоги подряд, опубликованные и нет, с
 * владельцем, статусом и ходом. По ней видно, что висит в очереди, что
 * остановлено и что стоит снять с публикации (ссылка ведёт на страницу
 * диалога, где решение принимает владелец или админ).
 *
 * Право — nj662.manage (только админ, см. application/config/acl.php).
 * Чужой аккаунт получает 403, гость — 403 тоже: существование страницы
 * не выдаём (в отличие от диалога, где честнее 404).
 *
 * Действий на странице нет: только просмотр и переходы. Удаление и публикация
 * остаются на странице диалога, где видно весь разговор, — решение «всё
 * удалить» по строчке таблицы принимать нельзя.
 */
class Controller_Nj662_Admin extends Controller_Nj662_Base {

	/** GET /dialog/all — все диалоги сайта. */
	public function action_index()
	{
		if ( ! $this->_can_manage)
		{
			throw HTTP_Exception::factory(403, 'Сводка всех диалогов доступна администратору');
		}

		$q      = Service_Nj662_Dialog::clean_text(Arr::get($_GET, 'q', ''), 120);
		$status = trim((string) Arr::get($_GET, 'status', ''));
		$per    = (int) Arr::get($_GET, 'per', 30);
		$page   = max(1, (int) Arr::get($_GET, 'page', 1));

		if ( ! in_array($per, [20, 30, 50, 100], TRUE))
		{
			$per = 30;
		}

		// Статус — ровно из словаря сервиса: чужую строку в SQL не пускаем
		// (то же условие проверяет Service_Nj662_Dialog::_all_where).
		if ($status !== '' AND ! isset(Service_Nj662_Dialog::$status_list[$status]))
		{
			$status = '';
		}

		$total = Service_Nj662_Dialog::all_count($q, $status);
		$pages = max(1, (int) ceil(max(1, $total['all']) / $per));

		if ($page > $pages)
		{
			$page = $pages;
		}

		$this->set_title('Все диалоги двух ИИ — сводка администратора');
		$this->set_description('Сводка всех диалогов двух ИИ: опубликованные и нет, с владельцем, статусом и ходом разговора.');

		$this->template->content = View::factory('662/admin', [
			'rows'        => Service_Nj662_Dialog::all_dialogs($q, $status, $per, $page),
			'total'       => $total,
			'q'           => $q,
			'status'      => $status,
			'status_list' => Service_Nj662_Dialog::$status_list,
			'page'        => $page,
			'pages'       => $pages,
			'per'         => $per,
			'per_options' => [20, 30, 50, 100],
			'csrf'        => Security::token(),
			'flash'       => $this->_flash(),
		]);
	}
}
