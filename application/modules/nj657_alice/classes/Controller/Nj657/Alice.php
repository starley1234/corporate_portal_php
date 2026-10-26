<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-657: публичная страница /alice — что за сервис и как подключить
 * свой диалог из консоли Яндекс.Диалогов.
 *
 * Страница ничего не знает о конкретных навыках (названия, промпты и
 * статистика — только в админке /alice/admin).
 */
class Controller_Nj657_Alice extends Controller_Erp100_Base {

	public function before()
	{
		parent::before();

		$this->set_title('Навыки Алисы — голосовой навык Яндекс.Диалогов с ответами ИИ');
		$this->set_description('Подключите свой навык Яндекс.Алисы к сайту n2j.ru: вебхук принимает запросы Диалогов, ответы генерирует ИИ с памятью разговора, роли настраиваются пресетами.');
		$this->set_keywords('алиса яндекс диалоги навык голосовой ассистент вебхук ии чат бот');
	}

	public function action_index()
	{
		$this->template->content = View::factory('657/index', [
			'webhook_url'    => Service_Nj657_Alice::webhook_url(),
			'presets'        => Service_Nj657_Alice::presets(),
			'can_admin'      => $this->can('nj657.admin'),
			'mqtt_enabled'   => Service_Nj657_Mqtt::enabled(),
			'active_skills'  => $this->_active_skills_count(),
		]);
	}

	/** Сколько навыков включено (для плашки «работает») — только число. */
	protected function _active_skills_count()
	{
		try
		{
			$row = Database::instance()
				->query(Database::SELECT, 'SELECT count(*) AS c FROM nj_657_skills WHERE is_active', FALSE)
				->current();

			return $row ? (int) $row['c'] : 0;
		}
		catch (Throwable $e)
		{
			return 0;
		}
	}

}
