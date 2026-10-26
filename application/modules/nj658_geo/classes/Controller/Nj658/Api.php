<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-658: JSON-API справочника — подсказки для форм других модулей.
 *
 *   GET /geo/api/suggest?q=моск&limit=10  → {query, items: [{slug, title, label, region, kind, population}]}
 *   GET /geo/api/regions                  → {items: [{title, cities}]}
 *
 * Публично и без токена: справочник не чувствителен, а модулям нужен
 * лаконичный способ подставить города в свою форму (например, «вакансии
 * по городам»). Образец API-контроллера — Nj605/Api.
 */
class Controller_Nj658_Api extends Controller {

	public $auto_render = FALSE;

	public function before()
	{
		parent::before();

		$this->response->headers('Content-Type', 'application/json; charset=UTF-8');
	}

	/** GET /geo/api/suggest?q=…&limit=10 */
	public function action_suggest()
	{
		$config = (array) Arr::get(Service_Nj658_Geo::config(), 'suggest', []);

		$query = (string) $this->request->query('q');
		$limit = (int) ($this->request->query('limit') !== NULL
			? $this->request->query('limit')
			: Arr::get($config, 'limit_default', 10));

		$items = array_map(function (array $row) {
			return [
				'slug'       => (string) $row['slug'],
				'title'      => (string) $row['title'],
				'label'      => Service_Nj658_Geo::label($row),
				'region'     => (string) $row['region'],
				'district'   => (string) $row['district'],
				'kind'       => (string) $row['kind'],
				'population' => (int) $row['population'],
			];
		}, Service_Nj658_Geo::search($query, $limit));

		$this->response->body(json_encode([
			'query' => $query,
			'items' => $items,
		], JSON_UNESCAPED_UNICODE));
	}

	/** GET /geo/api/regions */
	public function action_regions()
	{
		$this->response->body(json_encode([
			'items' => Service_Nj658_Geo::regions(),
		], JSON_UNESCAPED_UNICODE));
	}

}
