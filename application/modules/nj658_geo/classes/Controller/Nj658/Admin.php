<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-658: админка справочника /geo/admin — поиск, правка, добавление,
 * удаление и CSV-экспорт. Доступ — право nj658.admin.
 */
class Controller_Nj658_Admin extends Controller_Erp100_Base {

	public function before()
	{
		parent::before();

		if ( ! $this->can('nj658.admin'))
		{
			throw HTTP_Exception::factory(403, 'Доступ только для администратора');
		}

		$this->set_title('Города и посёлки · справочник');
		$this->set_module_nav([
			['title' => 'Справочник', 'href' => '/geo', 'icon' => 'fas fa-city'],
			['title' => 'Управление', 'href' => '/geo/admin', 'icon' => 'fas fa-pen-to-square'],
		]);
	}

	/** GET /geo/admin — статистика, поиск, список. */
	public function action_index()
	{
		$query = (string) $this->request->query('q');
		$page = (int) $this->request->query('page');

		$listing = Service_Nj658_Geo::admin_list($query, $page);

		$this->template->content = View::factory('658/admin', [
			'stats'    => Service_Nj658_Geo::stats(),
			'rows'     => $listing['rows'],
			'total'    => $listing['total'],
			'page'     => $listing['page'],
			'pages'    => $listing['pages'],
			'query'    => $query,
			'csrf'     => Security::token(),
			'notice'   => $this->request->query('notice'),
			'error'    => $this->request->query('error'),
		]);
	}

	/** GET /geo/admin/city(/<id>) — форма добавления/правки. */
	public function action_city()
	{
		$id = (int) $this->request->param('id', 0);
		$city = $id > 0 ? Service_Nj658_Geo::city_by_id($id) : NULL;

		if ($id > 0 AND $city === NULL)
		{
			throw HTTP_Exception::factory(404, 'Запись не найдена');
		}

		$this->template->content = View::factory('658/edit', [
			'city'    => $city,
			'is_new'  => $city === NULL,
			'form'    => $this->_form_values($city),
			'kinds'   => Service_Nj658_Geo::kinds(),
			'regions' => Service_Nj658_Geo::regions(),
			'csrf'    => Security::token(),
			'errors'  => [],
		]);
	}

	/** POST /geo/admin/save. */
	public function action_save()
	{
		$this->_csrf_post();

		$result = Service_Nj658_Geo::save_city($_POST);

		if ($result['ok'])
		{
			HTTP::redirect('/geo/admin/city/'.$result['id'].'?notice='.rawurlencode('Сохранено'), 303);
		}

		$this->template->content = View::factory('658/edit', [
			'city'    => NULL,
			'is_new'  => (int) Arr::get($_POST, 'id', 0) === 0,
			'form'    => $this->_form_values_from_post($_POST),
			'kinds'   => Service_Nj658_Geo::kinds(),
			'regions' => Service_Nj658_Geo::regions(),
			'csrf'    => Security::token(),
			'errors'  => $result['errors'],
		]);
	}

	/** POST /geo/admin/delete. */
	public function action_delete()
	{
		$this->_csrf_post();

		$id = (int) $this->request->post('id');

		if (Service_Nj658_Geo::delete_city($id))
		{
			HTTP::redirect('/geo/admin?notice='.rawurlencode('Запись удалена'), 303);
		}

		HTTP::redirect('/geo/admin?error='.rawurlencode('Запись не найдена'), 303);
	}

	/** GET /geo/admin/export — CSV справочника. */
	public function action_export()
	{
		$csv = Service_Nj658_Geo::csv(Service_Nj658_Geo::all_for_export());

		$this->response->headers('Content-Type', 'text/csv; charset=UTF-8');
		$this->response->headers('Content-Disposition', 'attachment; filename="nj658_cities.csv"');
		$this->response->body("\xEF\xBB\xBF".$csv); // BOM — Excel понимает кириллицу
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

	/** Значения формы из записи (или пустые). */
	protected function _form_values($city)
	{
		if ($city !== NULL)
		{
			return [
				'id' => (string) Arr::get($city, 'id', ''),
				'slug' => (string) Arr::get($city, 'slug', ''),
				'title' => (string) Arr::get($city, 'title', ''),
				'title_full' => (string) Arr::get($city, 'title_full', ''),
				'name_latin' => (string) Arr::get($city, 'name_latin', ''),
				'region' => (string) Arr::get($city, 'region', ''),
				'district' => (string) Arr::get($city, 'district', ''),
				'kind' => (string) Arr::get($city, 'kind', ''),
				'population' => (string) Arr::get($city, 'population', '0'),
				'lat' => (string) Arr::get($city, 'lat', '0'),
				'lon' => (string) Arr::get($city, 'lon', '0'),
				'is_active' => (bool) Arr::get($city, 'is_active', TRUE),
				'is_dup' => (bool) Arr::get($city, 'is_dup', FALSE),
				'source' => (string) Arr::get($city, 'source', ''),
			];
		}

		return [
			'id' => '', 'slug' => '', 'title' => '', 'title_full' => '', 'name_latin' => '',
			'region' => '', 'district' => '', 'kind' => '', 'population' => '0',
			'lat' => '0', 'lon' => '0', 'is_active' => TRUE, 'is_dup' => FALSE, 'source' => '',
		];
	}

	/** Значения формы из неудачного POST. */
	protected function _form_values_from_post(array $post)
	{
		return [
			'id' => (string) Arr::get($post, 'id', ''),
			'slug' => (string) Arr::get($post, 'slug', ''),
			'title' => (string) Arr::get($post, 'title', ''),
			'title_full' => (string) Arr::get($post, 'title_full', ''),
			'name_latin' => (string) Arr::get($post, 'name_latin', ''),
			'region' => (string) Arr::get($post, 'region', ''),
			'district' => (string) Arr::get($post, 'district', ''),
			'kind' => (string) Arr::get($post, 'kind', ''),
			'population' => (string) Arr::get($post, 'population', '0'),
			'lat' => (string) Arr::get($post, 'lat', '0'),
			'lon' => (string) Arr::get($post, 'lon', '0'),
			'is_active' => Arr::get($post, 'is_active') !== NULL,
			'is_dup' => Arr::get($post, 'is_dup') !== NULL,
			'source' => '',
		];
	}

}
