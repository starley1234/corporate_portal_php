<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Welcome extends Controller_Base {

    protected $auth_required = FALSE; // Главная — публичная витрина, редиректа для авторизованных нет

    public function before()
    {
        parent::before();
    }

    public function action_index()
    {
        $title = 'ИИ-сервисы для бизнеса — анализ компаний, документы, процессы | N2J.ru';
        $description = 'ИИ-сервисы N2J для бизнеса: проверка контрагентов, профиль и досье компании, подготовка документов и аудит процессов. Онлайн-инструменты для продаж и команд.';
        $keywords = 'ИИ-сервисы для бизнеса, искусственный интеллект для бизнеса, проверка контрагента, анализ компании, AI документы, аудит бизнес-процессов, B2B продажи, автоматизация бизнеса, N2J.ru';

        // Карточки сервисов и меню собираются из манифестов через nj110_catalog.
        // MCP остаётся в /services, но не показывается отдельной карточкой на главной.
        $cards_html = '';

        if (class_exists('Service_Nj110_Catalog'))
        {
            $shown = 0;

            // Берём одну карточку сверх лимита, чтобы исключение MCP не оставляло
            // пустое место в короткой подборке главной.
            foreach (Service_Nj110_Catalog::home_cards(NULL, 7) as $module)
            {
                if ((string) ($module['slug'] ?? '') === 'nj160_mcp')
                {
                    continue;
                }

                if ($shown >= 6)
                {
                    break;
                }

                $cards_html .= View::factory($module['card_view'])->set('module', $module)->render();
                $shown++;
            }

            $this->set_menu(Service_Nj110_Catalog::menu_items());
        }
        else
        {
            $this->set_menu([]);
        }

        $content = View::factory('/welcome/index')
            ->set('cards_html', $cards_html)
            ->set('github_spotlight', $this->_home_github_spotlight())
            ->set('airadar_update', $this->_home_airadar_update())
            ->set('seo_description', $description);

        $this->template->content = $content;

        $this->set_title($title);
        $this->set_description($description);
        $this->set_keywords($keywords);
    }

    /**
     * Публичный GitHub-дайджест главной: только свежий общий обзор первого
     * администратора, без пользовательского фокуса/языкового фильтра.
     * Персональные подборки пользователей на витрину не попадают.
     */
    protected function _home_github_spotlight()
    {
        if ( ! class_exists('Service_Nj593_Trends'))
        {
            return NULL;
        }

        try
        {
            $admin_id = (int) Service_Nj593_Trends::first_admin_id();

            if ($admin_id < 1)
            {
                return NULL;
            }

            foreach (Service_Nj593_Trends::list_user($admin_id, 8) as $review)
            {
                if ((string) ($review['status'] ?? '') !== 'done'
                    OR trim((string) ($review['focus'] ?? '')) !== ''
                    OR trim((string) ($review['language'] ?? '')) !== '')
                {
                    continue;
                }

                $finished_at = strtotime((string) ($review['finished_at'] ?? ''));

                // Расписание обзоров работает несколько раз в сутки; старую
                // подборку лучше не выдавать за текущую.
                if ( ! $finished_at OR $finished_at < time() - (72 * 60 * 60)
                    OR $finished_at > time() + 60 * 60)
                {
                    continue;
                }

                $code = (string) ($review['code'] ?? '');

                if ( ! preg_match('/^[a-z0-9]{3,12}$/D', $code))
                {
                    continue;
                }

                $items = Service_Nj593_Trends::items((int) ($review['id'] ?? 0));

                if (empty($items))
                {
                    continue;
                }

                $item = (array) reset($items);
                $name = trim((string) ($item['full_name'] ?? ''));

                if ($name === '')
                {
                    continue;
                }

                $copy = trim((string) ($item['why'] ?? ''));

                if ($copy === '')
                {
                    $copy = trim((string) ($item['what'] ?? ''));
                }

                if ($copy === '')
                {
                    $copy = trim((string) ($item['description'] ?? ''));
                }

                if ($copy === '')
                {
                    $copy = trim((string) ($review['summary'] ?? ''));
                }

                return [
                    'code'       => $code,
                    'title'      => $this->_home_excerpt((string) ($item['title'] ?? ''), 84),
                    'name'       => $this->_home_excerpt($name, 100),
                    'summary'    => $this->_home_excerpt($copy, 190),
                    'stars'      => max(0, (int) ($item['stars'] ?? 0)),
                    'item_count' => count($items),
                    'finished_at'=> $finished_at,
                ];
            }
        }
        catch (Throwable $e)
        {
            // Главная и каталог остаются доступными, если дайджест ещё не создан
            // или временно недоступна его таблица; сбой при этом не теряется.
            Log_Plain::event(Log::WARNING, 'Главная: GitHub-виджет пропущен: :error',
                [':error' => get_class($e).': '.$e->getMessage()], ['exception' => $e]);
        }

        return NULL;
    }

    /** Для списка на главной — компактный plain-text фрагмент без HTML. */
    protected function _home_excerpt($value, $limit)
    {
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $value)));
        $limit = max(1, (int) $limit);
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);

        if ($length <= $limit)
        {
            return $text;
        }

        $text = function_exists('mb_substr')
            ? mb_substr($text, 0, $limit - 1, 'UTF-8')
            : substr($text, 0, $limit - 1);

        return rtrim($text).'…';
    }

    /** Последняя дата радара видна только пользователю с правом на NJ-661. */
    protected function _home_airadar_update()
    {
        if ( ! $this->can('nj661.airadar') OR ! class_exists('Service_Nj661_Airadar'))
        {
            return NULL;
        }

        try
        {
            $reports = Service_Nj661_Airadar::reports(1);
            $report = ! empty($reports) ? (array) reset($reports) : [];
            $created_at = strtotime((string) ($report['created_at'] ?? ''));

            // Не переносим заголовки, выводы и идеи личного радара на публичную витрину.
            return $created_at ? ['created_at' => $created_at] : NULL;
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::WARNING, 'Главная: статус ИИ-радара пропущен: :error',
                [':error' => get_class($e).': '.$e->getMessage()], ['exception' => $e]);

            return NULL;
        }
    }
}
