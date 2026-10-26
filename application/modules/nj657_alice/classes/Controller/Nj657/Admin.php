<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-657: админка «Навыки Алисы» — /alice/admin.
 *
 * Список навыков с живой статистикой, форма настроек навыка (промпт,
 * модель, память, кнопки, MQTT), журнал диалогов и тестовая консоль —
 * прогон фразы через весь пайплайн без Яндекса.
 *
 * Доступ — ключ nj657.admin (роль admin проходит всегда, п.0 ACL::check).
 */
class Controller_Nj657_Admin extends Controller_Erp100_Base {

	public function before()
	{
		parent::before();

		if ( ! $this->can('nj657.admin'))
		{
			throw HTTP_Exception::factory(403, 'Доступ только для администратора');
		}

		$this->set_title('Навыки Алисы · управление');
		$this->set_module_nav([
			['title' => 'Навыки', 'href' => '/alice/admin', 'icon' => 'fas fa-microphone-lines'],
			['title' => 'Журнал диалогов', 'href' => '/alice/admin/history', 'icon' => 'fas fa-comments'],
			['title' => 'Тестовая консоль', 'href' => '/alice/admin/test', 'icon' => 'fas fa-vial'],
			['title' => 'О сервисе', 'href' => '/alice', 'icon' => 'fas fa-circle-info'],
		]);
	}

	/** GET /alice/admin — список навыков и статистика. */
	public function action_index()
	{
		$stats = Service_Nj657_Alice::stats();

		$total_dialogs = 0;
		$day_dialogs = 0;

		foreach ($stats as $row)
		{
			$total_dialogs += (int) $row['dialogs'];
			$day_dialogs += (int) $row['day_dialogs'];
		}

		$this->template->content = View::factory('657/admin', [
			'stats'        => $stats,
			'total_dialogs' => $total_dialogs,
			'day_dialogs'  => $day_dialogs,
			'webhook_url'  => Service_Nj657_Alice::webhook_url(),
			'mqtt_enabled' => Service_Nj657_Mqtt::enabled(),
			'csrf'         => Security::token(),
			'notice'       => $this->request->query('notice'),
			'error'        => $this->request->query('error'),
		]);
	}

	/** GET /alice/admin/skill(/<code>) — форма создания/правки навыка. */
	public function action_skill()
	{
		$code = (string) $this->request->param('code', '');
		$skill = $code !== '' ? Service_Nj657_Alice::skill_by_code($code) : NULL;

		if ($code !== '' AND $skill === NULL)
		{
			throw HTTP_Exception::factory(404, 'Навык не найден');
		}

		$this->template->content = View::factory('657/skill', [
			'skill'        => $skill,
			'is_new'       => $skill === NULL,
			'form'         => $this->_form_values($skill),
			'presets'      => Service_Nj657_Alice::presets(),
			'default_model' => Service_Nj657_Alice::platform_default_model(),
			'webhook_base' => Service_Nj657_Alice::webhook_url(),
			'csrf'         => Security::token(),
			'errors'       => [],
			'saved'        => $this->request->query('saved') !== NULL,
		]);
	}

	/** POST /alice/admin/save — создание и правка навыка. */
	public function action_save()
	{
		$this->_csrf_post();

		$result = Service_Nj657_Alice::save_skill($_POST);

		if ($result['ok'])
		{
			HTTP::redirect('/alice/admin/skill/'.$result['code'].'?saved=1', 303);
		}

		// Возврат в форму с введёнными значениями и списком ошибок.
		$this->template->content = View::factory('657/skill', [
			'skill'        => NULL,
			'is_new'       => trim((string) Arr::get($_POST, 'code', '')) === '',
			'form'         => $this->_form_values_from_post($_POST),
			'presets'      => Service_Nj657_Alice::presets(),
			'default_model' => Service_Nj657_Alice::platform_default_model(),
			'webhook_base' => Service_Nj657_Alice::webhook_url(),
			'csrf'         => Security::token(),
			'errors'       => $result['errors'],
			'saved'        => FALSE,
		]);
	}

	/** POST /alice/admin/toggle — включить/выключить навык. */
	public function action_toggle()
	{
		$this->_csrf_post();

		$code = (string) $this->request->post('code');
		$active = $this->request->post('active') !== NULL;

		if (Service_Nj657_Alice::toggle_skill($code, $active))
		{
			HTTP::redirect('/alice/admin?notice='.rawurlencode($active ? 'Навык включён' : 'Навык выключен'), 303);
		}

		HTTP::redirect('/alice/admin?error='.rawurlencode('Навык не найден'), 303);
	}

	/** POST /alice/admin/delete — удаление навыка (журнал уходит каскадом). */
	public function action_delete()
	{
		$this->_csrf_post();

		$code = (string) $this->request->post('code');

		if (Service_Nj657_Alice::delete_skill($code))
		{
			HTTP::redirect('/alice/admin?notice='.rawurlencode('Навык удалён вместе с журналом диалогов'), 303);
		}

		HTTP::redirect('/alice/admin?error='.rawurlencode('Навык не найден'), 303);
	}

	/** GET /alice/admin/history — журнал диалогов с фильтрами. */
	public function action_history()
	{
		$skill_id = (int) $this->request->query('skill');
		$intent = (string) $this->request->query('intent');
		$source = (string) $this->request->query('source');
		$page = (int) $this->request->query('page');

		$history = Service_Nj657_Alice::history($skill_id, $intent, $source, $page);

		$this->template->content = View::factory('657/history', [
			'rows'    => $history['rows'],
			'total'   => $history['total'],
			'page'    => $history['page'],
			'pages'   => $history['pages'],
			'filters' => [
				'skill'  => $skill_id,
				'intent' => $intent,
				'source' => $source,
			],
			'skills'  => Service_Nj657_Alice::skills(),
			'intents' => Service_Nj657_Alice::INTENTS,
			'intent_labels' => [
				'welcome'  => 'приветствие',
				'help'     => 'помощь',
				'exit'     => 'выход',
				'llm'      => 'ответ ИИ',
				'fallback' => 'сбой ИИ',
				'dangerous' => 'опасный контент',
			],
		]);
	}

	/**
	 * GET|POST /alice/admin/test — тестовая консоль: прогон реплики через
	 * весь пайплайн (маршрутизация, память, ИИ, сборка ответа) без Яндекса.
	 */
	public function action_test()
	{
		$skills = Service_Nj657_Alice::skills();
		$result = NULL;
		$skill = NULL;
		$text = '';
		$is_new = TRUE;

		if ($this->request->method() === Request::POST)
		{
			$this->_csrf_post();

			$skill = Service_Nj657_Alice::skill_by_code((string) $this->request->post('skill'));
			$text = trim((string) $this->request->post('text'));
			$is_new = $this->request->post('is_new') !== NULL;

			if ($skill !== NULL AND $text !== '')
			{
				$request = Service_Nj657_Alice::build_test_request($skill, $text, $is_new);
				$result = Service_Nj657_Alice::handle($request, '', $skill, 'test');
			}
		}

		$this->template->content = View::factory('657/test', [
			'skills'  => $skills,
			'skill'   => $skill,
			'text'    => $text,
			'is_new'  => $is_new,
			'result'  => $result,
			'csrf'    => Security::token(),
		]);
	}

	// ------------------------------------------------------------------

	/** CSRF для POST-действий админки. */
	protected function _csrf_post()
	{
		if ($this->request->method() !== Request::POST)
		{
			throw HTTP_Exception::factory(405, 'Только POST');
		}

		if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
		{
			throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
		}
	}

	/** Значения формы из строки навыка (или значения по умолчанию). */
	protected function _form_values($skill)
	{
		if ($skill !== NULL)
		{
			$out = ['code' => (string) Arr::get($skill, 'code', '')];

			foreach ($this->_form_fields() as $field)
			{
				$out[$field] = (string) Arr::get($skill, $field, '');
			}

			$out['is_active'] = Service_Nj657_Alice::truthy(Arr::get($skill, 'is_active', TRUE));
			$out['is_default'] = Service_Nj657_Alice::truthy(Arr::get($skill, 'is_default', FALSE));
			$out['end_session'] = Service_Nj657_Alice::truthy(Arr::get($skill, 'end_session', FALSE));
			$out['temperature'] = rtrim(rtrim(number_format((float) Arr::get($skill, 'temperature', 0.2), 3, '.', ''), '0'), '.');

			return $out;
		}

		$defaults = (array) Arr::get(Service_Nj657_Alice::config(), 'defaults', []);

		return [
			'code'             => '',
			'title'            => '',
			'yandex_skill_id'  => '',
			'webhook_token'    => Service_Nj657_Alice::generate_token(),
			'activation_names' => '',
			'system_text'      => '',
			'welcome_text'     => '',
			'welcome_tts'      => '',
			'help_text'        => '',
			'farewell_text'    => '',
			'fallback_text'    => '',
			'model'            => (string) Arr::get($defaults, 'model', ''),
			'temperature'      => rtrim(rtrim(number_format((float) Arr::get($defaults, 'temperature', 0.2), 3, '.', ''), '0'), '.'),
			'max_tokens'       => (int) Arr::get($defaults, 'max_tokens', 400),
			'answer_max_chars' => (int) Arr::get($defaults, 'answer_max_chars', 1024),
			'history_depth'    => (int) Arr::get($defaults, 'history_depth', 6),
			'history_window_min' => (int) Arr::get($defaults, 'history_window_min', 120),
			'buttons_text'     => '',
			'mqtt_topic'       => '',
			'is_active'        => TRUE,
			'is_default'       => FALSE,
			'end_session'      => (bool) Arr::get($defaults, 'end_session', FALSE),
		];
	}

	/** Значения формы из неудачного POST (сохранение с ошибками). */
	protected function _form_values_from_post(array $post)
	{
		$out = ['code' => trim((string) Arr::get($post, 'code', ''))];

		foreach ($this->_form_fields() as $field)
		{
			$out[$field] = (string) Arr::get($post, $field, '');
		}

		$out['is_active'] = Arr::get($post, 'is_active') !== NULL;
		$out['is_default'] = Arr::get($post, 'is_default') !== NULL;
		$out['end_session'] = Arr::get($post, 'end_session') !== NULL;

		return $out;
	}

	/** Список полей формы навыка. */
	protected function _form_fields()
	{
		return [
			'title', 'yandex_skill_id', 'webhook_token', 'activation_names',
			'system_text', 'welcome_text', 'welcome_tts', 'help_text',
			'farewell_text', 'fallback_text', 'model', 'max_tokens',
			'answer_max_chars', 'history_depth', 'history_window_min',
			'buttons_text', 'mqtt_topic',
		];
	}

}
