<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-658: публичный справочник /geo — поиск, страница населённого пункта.
 *
 * Контроллер тонкий: вся логика в Service_Nj658_Geo.
 */
class Controller_Nj658_Geo extends Controller_Erp100_Base {

	public function before()
	{
		parent::before();

		$this->set_title('Города и посёлки России — справочник населённых пунктов');
		$this->set_description('Справочник населённых пунктов России: поиск по названию, регионы и районы, население и координаты. Открытый JSON-API подсказок для других сервисов.');
		$this->set_keywords('города россии справочник населенные пункты регион район село поселок координаты');
	}

	public function action_index()
	{
		$slug = (string) $this->request->param('slug', '');

		// /geo/<slug> — страница населённого пункта.
		if ($slug !== '')
		{
			$city = Service_Nj658_Geo::city($slug);

			if ($city === NULL OR ! $city['is_active'])
			{
				throw HTTP_Exception::factory(404, 'Населённый пункт не найден');
			}

			$config = (array) Arr::get(Service_Nj658_Geo::config(), 'public', []);
			$nearby = $city['region'] !== ''
				? Service_Nj658_Geo::by_region($city['region'], (int) Arr::get($config, 'region_items', 12) + 1)
				: [];

			// Себя из «крупнейших региона» убираем.
			$nearby = array_values(array_filter($nearby, function ($row) use ($city) {
				return (int) $row['id'] !== (int) $city['id'];
			}));
			$nearby = array_slice($nearby, 0, (int) Arr::get($config, 'region_items', 12));

			$this->template->content = View::factory('658/city', [
				'city'   => $city,
				'nearby' => $nearby,
			]);

			return;
		}

		// /geo — список: поиск по названию и/или фильтр по региону.
		$query = (string) $this->request->query('q');
		$region = (string) $this->request->query('region');
		$page = (int) $this->request->query('page');

		$listing = Service_Nj658_Geo::listing($query, $region, $page);
		$regions = Service_Nj658_Geo::regions();

		$this->template->content = View::factory('658/index', [
			'rows'    => $listing['rows'],
			'total'   => $listing['total'],
			'page'    => $listing['page'],
			'pages'   => $listing['pages'],
			'query'   => $query,
			'region'  => $region,
			'regions' => $regions,
		]);
	}

}
