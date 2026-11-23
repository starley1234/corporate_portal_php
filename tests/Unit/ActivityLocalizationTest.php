<?php
use PHPUnit\Framework\TestCase;

/** Русские статусы активности и безопасное отображение незнакомых статусов. */
class ActivityLocalizationTest extends TestCase
{
    public function test_statuses_are_localized_in_activity_view(): void
    {
        $jobs = [];
        foreach (['done', 'failed', 'processing', 'pending', 'cancelled', '<script>'] as $status) {
            $jobs[] = ['id' => 1, 'status' => $status, 'channel' => 'echo', 'visitor_id' => 'anon',
                       'created_at' => '2026-01-01 10:00:00', 'finished_at' => NULL];
        }
        $html = View::factory('prx595/activity', [
            'jobs' => $jobs, 'kpi' => [], 'channels' => [], 'new_users' => [],
        ])->render();
        foreach (['Готово', 'Ошибка', 'Выполняется', 'В очереди', 'Отменена', '&lt;script&gt;'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_empty_tasks_does_not_offer_removed_services(): void
    {
        $html = View::factory('prx120/tasks', ['tasks' => [], 'budget' => [], 'logged_in' => FALSE])->render();
        $this->assertStringContainsString('Пока задач нет', $html);
        foreach (['href="/review"', 'href="/jobs"', 'href="/docs"'] as $link) {
            $this->assertStringNotContainsString($link, $html);
        }
    }
}
