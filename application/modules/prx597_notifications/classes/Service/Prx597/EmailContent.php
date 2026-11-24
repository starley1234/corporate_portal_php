<?php defined('SYSPATH') or die('No direct script access.');

/** Внутреннее оформление писем. Только подготовленные ссылки/HTML; без БД, подписи и отправки. */
final class Service_Prx597_EmailContent
{
    public static function text($title, $body, $href)
    {
        $url = self::base_url().$href;

        return $title."\n"
            .($body !== '' ? "\n".$body."\n" : '')
            ."\nОткрыть: ".$url."\n\n"
            ."Вы получили это письмо, потому что подписаны на уведомления PRX.ru.\n"
            ."Настройки: ".self::base_url()."/prx597";
    }

    /**
     * Обёртка письма: шапка с логотипом + карточка + подвал.
     * Таблично-инлайновая вёрстка — почтовые клиенты (Gmail, Mail.ru) режут
     * flex/grid и <style>. Тёмная тема не нужна: письма рендерим светлыми.
     */
    public static function shell($title, $inner_html, $footer = NULL)
    {
        $h = htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8');
        if ($footer === NULL) $footer = self::default_footer();

        return '<!DOCTYPE html>'
            .'<html lang="ru"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1"></head>'
            .'<body style="margin:0;padding:0;background:#f3f4f6;">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 12px;">'
            .'<tr><td align="center">'
            .'<table role="presentation" width="600" cellpadding="0" cellspacing="0" '
            .'style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;'
            .'border:1px solid #e5e7eb;">'
            // шапка
            .'<tr><td style="background:#111827;padding:18px 28px;">'
            .'<table role="presentation" width="100%"><tr>'
            .'<td style="color:#ffffff;font:bold 18px Arial,Helvetica,sans-serif;">'
            .'<span style="color:#60a5fa;">PRX</span>.ru</td>'
            .'<td align="right" style="color:#9ca3af;font:12px Arial,Helvetica,sans-serif;">'
            .$h.'</td>'
            .'</tr></table></td></tr>'
            // тело
            .'<tr><td style="padding:24px 28px;font:15px/1.55 Arial,Helvetica,sans-serif;color:#111827;">'
            .(string) $inner_html
            .'</td></tr>'
            // подвал
            .'<tr><td style="padding:16px 28px;background:#f9fafb;border-top:1px solid #e5e7eb;'
            .'color:#6b7280;font:12px/1.5 Arial,Helvetica,sans-serif;">'
            .$footer
            .'</td></tr>'
            .'</table></td></tr></table></body></html>';
    }

    /** Экранированный короткий текст → HTML. */
    protected static function _esc($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /** База ссылок в письмах: APP_URL из .env, по умолчанию — прод. */
    public static function base_url()
    {
        $u = rtrim(trim((string) getenv('APP_URL')), '/');

        // ВАЖНО: при пустом APP_URL раньше стоял `self::base_url()` — самовызов
        // без изменения состояния = бесконечная рекурсия. Cron `Prx603:Kick` с
        // pending-уведомлениями вызывал `_base_url()` из digest/pdf-путей, PHP
        // растил VM-стек на куче, пока ядро не убивало процесс по OOM (~2.4 ГБ
        // total-vm на сервере с 1 ГБ RAM). Фолбэк — прод-адрес, как в .env.example.
        return ($u !== '') ? $u : 'http://localhost:8080';
    }

    /** Цвет маркера события: зелёный — успех, красный — ошибка, синий — прочее. */
    protected static function _kind_color($kind)
    {
        if (strpos((string) $kind, 'failed') !== FALSE OR strpos((string) $kind, 'error') !== FALSE)
        {
            return '#dc2626'; // красный
        }

        if (strpos((string) $kind, 'done') !== FALSE OR strpos((string) $kind, 'prx605') !== FALSE)
        {
            return '#059669'; // зелёный
        }

        return '#2563eb'; // синий
    }

    /**
     * HTML сводки уведомлений: карточки событий + кнопки «Открыть».
     * $rows — строки prx_597_notifications, $extra — сколько ещё не вошло.
     */
    public static function digest(array $rows, $extra = 0)
    {
        $cards = '';

        foreach ($rows as $r)
        {
            $title = (string) $r['title'];
            $body  = preg_replace('~\s+~u', ' ', (string) $r['body']);
            $url   = self::base_url().$r['_email_href'];
            $dot   = '<span style="display:inline-block;width:9px;height:9px;border-radius:50%;'
                .'background:'.self::_kind_color($r['kind'] ?? '').';margin-right:7px;"></span>';
            $when  = '';

            if ( ! empty($r['created_at']))
            {
                try
                {
                    $when = Date::formatted_time((string) $r['created_at'], 'd.m.Y H:i');
                }
                catch (Throwable $e)
                {
                    $when = '';
                }
            }

            $title_html = self::_esc($title);

            // заголовок-ссылка: событие ведёт на страницу результата
            if (trim((string) $r['href']) !== '')
            {
                $title_html = '<a href="'.self::_esc($url).'" '
                    .'style="color:#111827;text-decoration:none;">'.$title_html.'</a>';
            }

            $cards .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
                .'style="margin:0 0 12px 0;border:1px solid #e5e7eb;border-radius:12px;">'
                .'<tr><td style="padding:14px 16px;">'
                .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
                .'<td style="font:bold 15px/1.4 Arial,Helvetica,sans-serif;color:#111827;">'
                .$dot.$title_html.'</td>'
                .($when !== '' ? '<td align="right" style="color:#9ca3af;font:12px Arial,Helvetica,sans-serif;'
                    .'white-space:nowrap;">'.$when.'</td>' : '')
                .'</tr></table>'
                .($body !== '' ? '<div style="margin-top:6px;color:#4b5563;'
                    .'font:14px/1.55 Arial,Helvetica,sans-serif;">'.self::_esc($body).'</div>' : '')
                .(trim((string) $r['href']) !== ''
                    ? '<div style="margin-top:10px;"><a href="'.self::_esc($url).'" '
                        .'style="display:inline-block;background:#2563eb;color:#ffffff;'
                        .'font:bold 13px Arial,Helvetica,sans-serif;padding:8px 16px;'
                        .'border-radius:8px;text-decoration:none;">Открыть &rarr;</a></div>'
                    : '')
                .'</td></tr></table>';
        }

        if ($extra > 0)
        {
            $cards .= '<p style="margin:14px 0;color:#374151;font:14px Arial,Helvetica,sans-serif;">'
                .'И ещё уведомлений: <b>'.(int) $extra.'</b> — '
                .'<a href="'.self::base_url().'/prx597" style="color:#2563eb;">смотреть все</a></p>';
        }

        return self::shell(
            'Сводка уведомлений',
            '<p style="margin:0 0 16px 0;font:15px Arial,Helvetica,sans-serif;color:#374151;">'
            .'В центре уведомлений накопились события:</p>'.$cards);
    }

    private static function default_footer()
    {
        $footer = 'Вы получили это письмо, потому что подписаны на уведомления PRX.ru.<br>';
        $footer .= '<a href="'.self::base_url().'/prx597" style="color:#2563eb;text-decoration:underline;">'
            .'Все уведомления и настройки</a> · '
            .'<a href="'.self::base_url().'" style="color:#2563eb;text-decoration:underline;">на главную</a>';

        return $footer;
    }
}
