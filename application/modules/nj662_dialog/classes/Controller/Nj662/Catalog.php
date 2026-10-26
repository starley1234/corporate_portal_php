<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-662: публичный каталог опубликованных диалогов (/dialog/list).
 *
 * Тот же приём, что у каталогов /trends/catalog, /airadar/catalog и
 * /forge/artifacts: рабочий стол диалогов — за аккаунтом, а САМ РАЗГОВОР,
 * который владелец решил опубликовать, читают все. Читаемый список, свежие
 * сверху, поиск (тема, вводная и реплики) и пагинация общим партиалом.
 *
 * Дизайн — по эталону /pages/required_design_sample: карточки rounded-xl,
 * поле поиска и кнопки rounded-lg min-h-[44px], полос хода нет.
 */
class Controller_Nj662_Catalog extends Controller_Nj662_Base {

	/** GET /dialog/list — каталог опубликованных диалогов. */
	public function action_index()
	{
		$q        = Service_Nj662_Dialog::clean_text(Arr::get($_GET, 'q', ''), 120);
		$per      = (int) Arr::get($_GET, 'per', (int) Service_Nj662_Dialog::limits('page_size', 12));
		$page     = max(1, (int) Arr::get($_GET, 'page', 1));

		if ( ! in_array($per, [10, 20, 50], TRUE))
		{
			$per = (int) Service_Nj662_Dialog::limits('page_size', 12);
		}

		$total = Service_Nj662_Dialog::count_public($q);
		$pages = max(1, (int) ceil($total / $per));

		if ($page > $pages)
		{
			$page = $pages;
		}

		$this->set_title('Опубликованные диалоги двух ИИ — каталог разговоров');
		$this->set_description('Каталог опубликованных разговоров двух ИИ-агентов: тема, участники и полная стенограмма с найденными источниками. Поиск по темам и репликам.');

		$this->template->content = View::factory('662/catalog', [
			'rows'        => Service_Nj662_Dialog::public_dialogs($q, $per, $page),
			'q'           => $q,
			'page'        => $page,
			'pages'       => $pages,
			'total'       => $total,
			'per'         => $per,
			'per_options' => [10, 20, 50],
			'own'         => $this->_user_id,
			'logged'      => Gate::logged_in(),
			'csrf'        => Security::token(),
			'flash'       => $this->_flash(),
		]);
	}
}
