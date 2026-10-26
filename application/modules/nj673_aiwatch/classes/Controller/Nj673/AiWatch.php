<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-673 «AI Watch» — журнал изменений между аудитами ИИ-моделей.
 * Данные и оценки читает через публичный сервис nj672, таблицу не дублирует.
 */
class Controller_Nj673_AiWatch extends Controller_Erp100_Base
{
    public function before()
    {
        parent::before();
        $this->set_title('AI Watch — динамика аудитов безопасности ИИ-моделей');
        $this->set_description('Сравнение последовательных аудитов одного эндпоинта: изменение оценки, число новых провалов и строки OWASP с регрессией.');
        $this->set_module_nav([
            ['title' => 'AI Watch', 'href' => '/aiwatch', 'icon' => 'fas fa-chart-line'],
            ['title' => 'Сканер ИИ', 'href' => '/aiscan', 'icon' => 'fas fa-shield-halved'],
        ]);
    }

    public function action_index()
    {
        if ( ! Gate::logged_in())
        {
            $this->template->content = Gate::wall('aiwatch', [
                'icon'   => 'fas fa-chart-line',
                'title'  => 'AI Watch — мониторинг изменений в аудите',
                'text'   => 'История аудитов содержит адреса целевых эндпоинтов и выводы о безопасности. Войдите под администратором, чтобы продолжить.',
                'points' => [
                    'Сравниваются только завершённые полные аудиты одной цели и модели',
                    'Скоринг не пересчитывается: источник истины — отчёт сканера /aiscan',
                    'Секреты цели в AI Watch не копируются и не сохраняются',
                ],
            ]);
            return;
        }

        if ( ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'AI Watch доступен только администратору');
        }

        if ( ! class_exists('Service_Nj672_Scan'))
        {
            throw HTTP_Exception::factory(503, 'Для AI Watch требуется модуль /aiscan');
        }

        $runs = Service_Nj672_Scan::history(100);
        $items = [];

        foreach ($runs as $run)
        {
            $brief = Service_Nj672_Scan::brief((array) $run);
            $items[] = [
                'id'         => (int) $run['id'],
                'status'     => (string) $run['status'],
                'title'      => (string) $run['title'],
                'model'      => (string) $brief['model'],
                'target'     => (string) $brief['target'],
                'score'      => $brief['score'],
                'grade'      => (string) $brief['grade'],
                'risk'       => Service_Nj673_AiWatch::risk_label($brief['risk'] ?? ''),
                'fail'       => $brief['fail'],
                'created_at' => (string) ($run['created_at'] ?? ''),
            ];
        }

        $this->template->content = View::factory('673/index', [
            'comparison' => Service_Nj673_AiWatch::compare($runs),
            'runs'       => $items,
            'csrf'       => Security::token(),
        ]);
    }
}
