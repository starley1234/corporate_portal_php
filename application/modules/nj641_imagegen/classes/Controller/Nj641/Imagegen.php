<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница «Генератор изображений» (nj641_imagegen): /imagegen
 *
 * Режимы: по описанию/generate, редактирование/edit, мульти-референсы.
 * Генерация серверная (воркер + внешний image-API), результат — картинка.
 */
class Controller_Nj641_Imagegen extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Генератор изображений: картинки по описанию, редактирование и референсы');
        $this->set_description(
            'Серверная генерация изображений по описанию, редактирование по картинке и мульти-референсы. Задача ставится в очередь и выполняется воркером на внешнем image-API с проверкой видеопамяти; результат можно переиспользовать из других сервисов — презентаций, статей, постов.');
        $this->set_keywords('изображение картинка генерация ai референс редактирование фото презентация статья иллюстрация seed');

    }

    public function action_index()
    {
        $service = class_exists('Service_Nj641_Imagegen') ? 'Service_Nj641_Imagegen' : NULL;
        $logged  = Gate::logged_in();
        $uid     = (string) Visitor::uid();
        $recent  = ($service AND $logged) ? Service_Nj641_Imagegen::recent_runs($uid, 12) : [];

        // Незавершённые прогоны дожимаем при открытии страницы: картинка могла
        // лечь в папку сайта уже после последнего поллинга (воркер и сайт на
        // одной машине) — тогда её подхватит reconcile() → adopt_file().
        if ($recent)
        {
            foreach ($recent AS $i => $r)
            {
                if ((string) ($r['status'] ?? '') !== Service_Nj641_Imagegen::STATUS_QUEUED)
                {
                    continue;
                }

                try
                {
                    $fresh = Service_Nj641_Imagegen::result_of((int) $r['id']);
                }
                catch (Throwable $e)
                {
                    continue;
                }

                if (is_array($fresh))
                {
                    $fresh['has_image'] = (int) ( ! empty($fresh['image_b64'])
                        OR Service_Nj641_Imagegen::image_url($fresh) !== '');

                    $recent[$i] = $fresh;
                }
            }
        }

        $this->template->content = View::factory('641/index', [
            // Гость видит плашку входа и справку вместо формы (владелец,
            // 08.10.2026): генерация — платная работа сервера, ей нужен аккаунт.
            'logged'      => $logged,
            'sizes'       => $service ? Service_Nj641_Imagegen::sizes() : [],
            'default_size'=> $service ? (string) Service_Nj641_Imagegen::default_size() : '1024',
            'recent'      => $recent,
            'vram'        => $service ? Service_Nj641_Imagegen::vram_settings() : [],
            'rate_text'   => $service ? Service_Nj641_Imagegen::rate_limit_text() : '5 генераций в минуту',
            'max_input_mb'=> $service ? (string) Service_Nj641_Imagegen::cfg('max_input_mb', 10) : '10',
            'csrf'        => Security::token(),
            'can_review'  => $service ? Service_Nj641_Imagegen::can_moderate() : FALSE,
            'review_count'=> $service ? (int) (Service_Nj641_Imagegen::library_counts()['pending'] ?? 0) : 0,
        ]);
    }

    /**
     * GET /imagegen/gallery — «Изображения»: сгенерированные картинки для
     * модерации и публикации в библиотеку.
     *
     * Владелец (26.09): «нет страницы с сгенерированными изображениями для
     * модерации и публикации». Публикация = `approved`: картинку забирают
     * другие сервисы через Service_Nj641_Imagegen::library_pick().
     *
     * Доступ: право `nj641.admin` (по умолчанию роль admin) — модерация чужих
     * изображений не должна быть доступна каждому.
     */
    public function action_gallery()
    {
        if ( ! class_exists('Service_Nj641_Imagegen') OR ! Service_Nj641_Imagegen::can_moderate())
        {
            throw HTTP_Exception::factory(403, 'Только для администратора');
        }

        $q      = trim((string) $this->request->query('q'));
        $review = trim((string) $this->request->query('review'));
        $page   = max(1, (int) $this->request->query('page'));
        $per    = (int) $this->request->query('per');

        if ( ! in_array($per, [20, 50, 100], TRUE))
        {
            $per = 20;
        }

        if ( ! in_array($review, ['pending', 'approved', 'rejected'], TRUE))
        {
            $review = '';
        }

        $total = Service_Nj641_Imagegen::library_count($q, $review);
        $pages = max(1, (int) ceil($total / $per));

        if ($page > $pages)
        {
            $page = $pages;
        }

        $this->set_title('Изображения: модерация и публикация');
        $this->set_description(
            'Сгенерированные изображения: отметьте картинки для библиотеки — их переиспользуют статьи, презентации и другие сервисы.');

        $this->template->content = View::factory('641/gallery', [
            'items'   => Service_Nj641_Imagegen::library_list($q, $review, $per, ($page - 1) * $per),
            'counts'  => Service_Nj641_Imagegen::library_counts(),
            'f'       => [
                'q'      => $q,
                'review' => $review,
                'page'   => $page,
                'per'    => $per,
                'total'  => $total,
                'pages'  => $pages,
            ],
            'csrf'    => Security::token(),
            // упрощённый режим (нет колонок модерации) и диагностика пустой
            // страницы — чтобы «ничего нет» всегда имело объяснение
            'note'    => Service_Nj641_Imagegen::library_note(),
            'diag'    => Service_Nj641_Imagegen::library_diag(),
        ]);
    }
}
