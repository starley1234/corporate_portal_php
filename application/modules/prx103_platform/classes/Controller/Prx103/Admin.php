<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx100: «Администрирование» /admin — единая страница управления платформой.
 *
 * 06.10.2026, решение владельца: левая панель росла с каждым модулем и
 * перестала быть навигацией. Группа «Платформа» в панели — только Дашборд,
 * Новости сайта и Администрирование; всё, что было её пунктами, собрано
 * здесь: инструменты платформы, сервисы платформенной группы и разделы
 * управления остальных модулей.
 *
 * Доступ — право prx100.admin (config/acl.php).
 */
class Controller_Prx103_Admin extends Controller_Prx102_Base {

	public function before()
	{
		parent::before();

		if ( ! $this->can('prx100.admin'))
		{
			throw HTTP_Exception::factory(403, 'Только для администратора');
		}

		$this->set_title('Администрирование');
		$this->set_module_nav([
			['title' => 'Дашборд', 'href' => '/dashboard', 'icon' => 'fas fa-gauge-high'],
			['title' => 'Чистка данных', 'href' => '/prx100/cleanup', 'icon' => 'fas fa-broom'],
			['title' => 'Хвост ошибок', 'href' => '/prx100/errors', 'icon' => 'fas fa-heart-crack'],
			['title' => 'Логи', 'href' => '/prx100/logs', 'icon' => 'fas fa-terminal'],
			['title' => 'Проверка сервера', 'href' => '/prx100/selfcheck', 'icon' => 'fas fa-clipboard-check'],
			['title' => 'События', 'href' => '/prx208_events', 'icon' => 'fas fa-bolt'],
			['title' => 'Все сервисы', 'href' => '/services', 'icon' => 'fas fa-grip'],
		]);
	}

	public function action_index()
	{
		// Быстрые действия сводки (POST + CSRF; опасные кнопки в виде — в два
		// клика через data-prx-arm). После действия — редирект, чтобы F5 не
		// повторял его.
		if ($this->request->method() === Request::POST)
		{
			$flash = Security::check((string) $this->request->post('csrf'))
				? Service_Prx103_Health::run_action((string) $this->request->post('act'))
				: 'Сессия устарела — попробуйте ещё раз';

			Session::instance()->set('prx100_admin_flash', $flash);
			HTTP::redirect('/admin', 303);
		}

		$ctx = class_exists('Service_Prx110_Catalog')
			? Service_Prx110_Catalog::user_context()
			: ['admin' => TRUE, 'logged' => TRUE, 'guest' => FALSE, 'agent' => FALSE];

		// 1. Инструменты платформы — реестр prx110 (модуль подключён — пункт показан).
		$tools = class_exists('Service_Prx110_Catalog') ? Service_Prx110_Catalog::admin_tools() : [];

		// 2. Сервисы группы «Платформа»: включая убранные из панели ('menu' => FALSE)
		// и «в работе» (plan_modules): /admin — рабочий инструмент администратора,
		// а не публичная витрина, поэтому beta/draft здесь нужны.
		$platform = [];
		$tools_hrefs = [];

		foreach ($tools as $tool)
		{
			$tools_hrefs[rtrim((string) $tool['href'], '/')] = TRUE;
		}

		if (class_exists('Service_Prx110_Catalog'))
		{
			foreach (Service_Prx110_Catalog::plan_modules($ctx) as $m)
			{
				if ((string) $m['group'] !== 'Платформа')
				{
					continue;
				}

				// Дубли не нужны: если инструмент уже ведёт на этот же адрес,
				// карточка ничего не добавляет (Пиналка, Предпочтения и т.п.).
				if (isset($tools_hrefs[rtrim((string) $m['href'], '/')]))
				{
					continue;
				}

				$platform[] = $m;
			}
		}

		// 3. Разделы управления остальных сервисов ('admin' в манифестах).
		// Источник — Manifest::all(), а не visible_modules(): модуль без
		// публичной витрины (без href — например, prx659 «Вход через Яндекс»)
		// всё равно может иметь раздел управления, и на /admin ему место.
		$admin_sections = [];
		$seen_hrefs = $tools_hrefs; // адрес уже в «Инструментах» — чип не дублируем

		if (class_exists('Manifest'))
		{
			foreach (Manifest::all() as $m)
			{
				if (empty($m['admin']['href']))
				{
					continue;
				}

				$href = rtrim((string) $m['admin']['href'], '/');

				if ($href === '' OR isset($seen_hrefs[$href]))
				{
					continue;
				}

				$seen_hrefs[$href] = TRUE;

				$admin_sections[] = [
					'title'  => (string) Arr::get($m, 'admin.title', Arr::get($m, 'title', $m['module_name'])),
					'href'   => (string) $m['admin']['href'],
					'icon'   => (string) Arr::get($m, 'admin.icon', Arr::get($m, 'icon', 'fas fa-gear')),
					'group'  => (string) Arr::get($m, 'group', 'Платформа'),
					'sort'   => (int) Arr::get($m, 'sort', 500),
					'note'   => (string) Arr::get($m, 'admin.note', ''),
				];
			}

			usort($admin_sections, function ($a, $b) {
				return [$a['group'], $a['sort'], $a['title']] <=> [$b['group'], $b['sort'], $b['title']];
			});
		}

		$this->template->content = View::factory('prx103/admin', [
			'health'        => Service_Prx103_Health::snapshot(),
			'flash'         => (string) Session::instance()->get_once('prx100_admin_flash', ''),
			'csrf'          => Security::token(),
			'tools'         => $tools,
			'platform'      => $platform,
			'admin_sections' => $admin_sections,
		]);
	}

}
