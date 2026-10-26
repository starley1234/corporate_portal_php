<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Публичные страницы «Новостей сайта»: /news — лента (с автодочитыванием
 * релизов из git-лога), /news/rss.xml — RSS для подписчиков.
 */
class Controller_Nj604_News extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Новости сайта — N2J.ru');
        $this->set_description('Что нового на N2J.ru: обновления платформы, новые ИИ-инструменты и улучшения сервисов — автоматически из истории разработки.');
    }

    public function action_index()
    {
        try
        {
            Service_Nj604_News::sync_from_git();
            Service_Nj604_News::curated_seed();
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj604: sync: :e', [':e' => $e->getMessage()]);
        }

        $this->template->content = View::factory('604/index', [
            'items' => Service_Nj604_News::latest_public(40),
        ]);
    }

    /** GET /news/rss.xml — RSS-лента. */
    public function action_rss()
    {
        $this->auto_render = FALSE;

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<rss version="2.0"><channel>'."\n"
            .'<title>Новости N2J.ru</title>'."\n"
            .'<link>https://n2j.ru/news</link>'."\n"
            .'<description>Обновления и новые инструменты платформы N2J.ru</description>'."\n"
            .'<language>ru</language>'."\n";

        foreach (Service_Nj604_News::latest_public(20) as $n)
        {
            $ts   = strtotime((string) ($n['published_at'] ?: $n['created_at']));
            $date = $ts ? date(DATE_RSS, $ts) : date(DATE_RSS);
            $link = 'https://n2j.ru/news'.((string) $n['href'] !== '' ? '' : '');

            $xml .= '<item>'."\n"
                .'<title>'.HTML::chars($n['title']).'</title>'."\n"
                .'<link>'.HTML::chars($link).'</link>'."\n"
                .'<guid>'.HTML::chars($link.'#n'.(int) $n['id']).'</guid>'."\n"
                .'<pubDate>'.$date.'</pubDate>'."\n"
                .'<description>'.HTML::chars(mb_substr((string) $n['body_md'], 0, 900)).'</description>'."\n"
                .'</item>'."\n";
        }

        $xml .= '</channel></rss>';

        $this->response->status(200);
        $this->response->headers('Content-Type', 'application/rss+xml; charset=utf-8');
        $this->response->body($xml);
    }
}
