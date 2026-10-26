<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница «Лаборатория векторов» (модуль NJ-653): /veclab.
 *
 * Одна страница, три опыта над мультимодальной эмбеддинг-моделью:
 *   · проверка модели — какой формат запроса она понимает и связывает ли
 *     вообще текст с картинкой;
 *   · коллекция и поиск — картинки и тексты кладутся в pgvector, а ищутся
 *     словами (кросс-модальный поиск);
 *   · сравнение пары — два входа и косинус между ними.
 *
 * Считает векторы воркер (канал `nj653.embed`), страница их только
 * показывает: в HTTP-запросе сети к модели нет (AGENTS §2.6).
 */
class Controller_Nj653_Veclab extends Controller_Erp100_Base
{
    public function before()
    {
        parent::before();

        $this->set_title('Лаборатория векторов: поиск картинок словами и сравнение смыслов');
        $this->set_description(
            'Проверка мультимодальной эмбеддинг-модели вживую: положите картинки и тексты в общую коллекцию и ищите их обычными словами. Страница показывает косинусную близость, формат запроса к модели и размерность вектора.');
        $this->set_keywords(
            'мультимодальные эмбеддинги векторный поиск по картинкам косинусная близость pgvector qwen3-vl-embedding проверка модели');

        $this->set_module_nav([
            ['title' => 'Лаборатория', 'href' => '/veclab', 'icon' => 'fas fa-vector-square'],
            ['title' => 'Карта сходства', 'href' => '/veclab/map', 'icon' => 'fas fa-diagram-project'],
            ['title' => 'Модуль · админ', 'href' => '/veclab/admin', 'icon' => 'fas fa-shield-halved', 'roles' => ['admin']],
        ]);
    }

    public function action_index()
    {
        $logged  = Gate::logged_in();
        $visitor = class_exists('Visitor') ? (string) Visitor::uid() : '';
        $user_id = class_exists('Visitor') ? Visitor::user_id() : NULL;
        $probe   = Service_Nj653_Store::probe_last();

        $this->template->content = View::factory('653/index', [
            // Гость видит плашку входа вместо рабочей области (владелец,
            // 08.10.2026): личной коллекции и прогонов у него нет, поэтому
            // и не читаем их из базы — страница остаётся справкой о сервисе.
            'logged'   => $logged,
            'limits'   => (array) Kohana::$config->load('nj653_veclab.limits'),
            'scale'    => Service_Nj653_Veclab::scale(),
            'stats'    => Service_Nj653_Store::stats(),
            'items'    => $logged ? Service_Nj653_Veclab::hits(
                Service_Nj653_Store::items_mine($visitor, 60)) : [],
            'mine'     => $logged ? Service_Nj653_Store::items_count($visitor) : 0,
            'runs'     => $logged ? Service_Nj653_Store::runs_mine($visitor, 6, $user_id) : [],
            // Паспорт модели последней удачной проверки: он общий для всех,
            // поэтому страница показывает его сразу, до первого действия.
            'probe'    => $probe ? Service_Nj653_Veclab::shape($probe) : [],
            'probe_at' => (string) ($probe['finished_at'] ?? ''),
            'samples'  => (array) Kohana::$config->load('nj653_veclab.samples'),
        ]);
    }

    /** Отдельная страница поиска: не возвращает фокус к полю на телефоне. */
    public function action_result()
    {
        $id = (int) $this->request->param('id');

        // Результаты поиска — часть рабочей области, а она только для
        // зарегистрированных. Гостя уводим на вход и возвращаем сюда же.
        if ( ! Gate::logged_in())
        {
            $this->redirect('auth/login?return_url='.rawurlencode('veclab/result/'.$id));

            return;
        }

        $visitor = class_exists('Visitor') ? (string) Visitor::uid() : '';
        $user_id = class_exists('Visitor') ? Visitor::user_id() : NULL;
        $run     = $id > 0 ? Vision::run_get(Service_Nj653_Store::RUNS, $id) : NULL;

        if ( ! $run OR ! Vision::run_owned($run, $visitor, $user_id)
            OR (string) ($run['mode'] ?? '') !== 'search')
        {
            throw HTTP_Exception::factory(404, 'Результат поиска не найден');
        }

        $hint = '';

        if ( ! in_array((string) $run['status'], ['done', 'error', 'cancelled'], TRUE))
        {
            $watch = Vision::run_watch(Service_Nj653_Store::RUNS, $id, 'nj653.embed');
            $run   = $watch['run'];
            $hint  = (string) $watch['hint'];
        }

        if ( ! $run)
        {
            throw HTTP_Exception::factory(404, 'Результат поиска не найден');
        }

        $raw     = (array) $run['result'];
        $pending = (array) ($raw['query'] ?? (Service_Nj653_Store::pending_of($run)[0] ?? []));
        $result  = Service_Nj653_Veclab::shape($raw);
        $live    = Vision::run_live($run);

        $this->set_title('Результаты поиска по смыслу — Лаборатория векторов');
        $this->template->content = View::factory('653/result', [
            'run'     => $run,
            'query'   => $pending,
            'result'  => $result,
            'live'    => $live,
            'hint'    => $hint,
            'running' => in_array((string) $run['status'], ['queued', 'running'], TRUE),
        ]);
    }
}
