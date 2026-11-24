<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
$status_badge = [
    'new' => ['wait', 'Новый'],
    'in_review' => ['run', 'На разборе'],
    'in_work' => ['run', 'В работе'],
    'on_hold' => ['wait', 'Приостановлен'],
    'closed' => ['ok', 'Закрыт'],
    'archived' => ['wait', 'В архиве'],
][$doc['status']] ?? ['wait', $doc['status']];

$priority_badge = ['urgent' => 'err', 'high' => 'run', 'normal' => 'info', 'low' => 'wait'][$doc['priority']] ?? 'info';
$risk_badge = ['high' => 'err', 'medium' => 'warn', 'low' => 'ok'][$doc['risk_level'] ?? ''] ?? 'wait';

$close_form = '<form method="post" action="/prx391_documents/'.($doc['status'] === 'closed' ? 'reopen' : 'close').'/'.(int) $doc['id'].'" class="inline-flex">'
    .'<input type="hidden" name="csrf" value="'.HTML::chars($csrf).'">'
    .'<button type="submit" class="'.($doc['status'] === 'closed' ? 'prx-btn-secondary' : 'prx-btn-secondary').'"><i class="fas '.($doc['status'] === 'closed' ? 'fa-rotate-left' : 'fa-circle-check').'"></i><span>'.($doc['status'] === 'closed' ? 'Вернуть в работу' : 'Закрыть').'</span></button>'
    .'</form>';

$actions = [];
if ($can_edit)
{
    $actions[] = ['html' => $close_form];
}
if ($can_draft)
{
    $actions[] = ['label' => 'Подготовить ответ', 'href' => '/replies/document/'.(int) $doc['id'], 'icon' => 'fa-reply', 'role' => 'cta'];
}
$actions[] = ['label' => 'К реестру', 'href' => '/prx391_documents', 'icon' => 'fa-arrow-left', 'role' => 'secondary'];

?>
<div class="prx-ds w-full px-3.5 pt-4 pb-20 sm:px-6 lg:px-8">
<?php
echo View::factory('partials/page_header', [
    'crumbs' => [['Кабинет', '/dashboard'], ['Реестр', '/prx391_documents'], [$doc['reg_no']]],
    'title' => $doc['subject'] ?: 'Без темы',
    'pill' => [$doc['reg_no'], 'fa-file-lines'],
    'actions' => $actions,
    'lead' => 'Тип: '.($doc['type_title'] ?: 'не определён').' · Корреспондент: '.($doc['correspondent_name'] ?: ($doc['sender_name'] ?: 'не определён')).' · Поступил '.date('d.m.Y H:i', strtotime($doc['received_at'])),
]);
echo View::factory('partials/docflow_flash', ['flash' => $flash]);
?>


    <section class="mt-4 flex flex-wrap items-center gap-2">
        <span class="prx-badge prx-badge-<?php echo $status_badge[0]; ?>"><span class="prx-dot"></span><?php echo HTML::chars($status_badge[1]); ?></span>
        <span class="prx-badge prx-badge-<?php echo $priority_badge; ?>"><?php echo HTML::chars($priorities[$doc['priority']] ?? $doc['priority']); ?></span>
        <?php if ($doc['risk_level']): ?><span class="prx-badge prx-badge-<?php echo $risk_badge; ?>"><i class="fas fa-shield-halved"></i>риск: <?php echo HTML::chars(['high' => 'высокий', 'medium' => 'средний', 'low' => 'низкий'][$doc['risk_level']] ?? $doc['risk_level']); ?></span><?php endif; ?>
        <?php if ($doc['sensitivity'] !== 'internal'): ?><span class="prx-badge prx-badge-wait"><i class="fas fa-lock"></i><?php echo HTML::chars($sensitivities[$doc['sensitivity']] ?? $doc['sensitivity']); ?></span><?php endif; ?>
        <?php if ($doc['deadline_at']): ?>
            <span class="prx-badge prx-badge-<?php echo $doc['deadline_state'] === 'overdue' ? 'err' : ($doc['deadline_state'] === 'soon' ? 'run' : 'info'); ?>"><i class="fas fa-clock"></i>срок <?php echo HTML::chars(date('d.m.Y H:i', strtotime($doc['deadline_at']))); ?></span>
        <?php endif; ?>
    </section>

    <div class="mt-5 grid items-start gap-4 xl:grid-cols-2">
        <section aria-label="Реквизиты документа" class="order-2 min-w-0 xl:order-1">
<?php include __DIR__.'/partials/card.php'; ?>
        </section>
        <aside class="order-1 min-w-0 space-y-4 xl:order-2">
            <div class="grid items-start gap-4 md:grid-cols-2">
                <div class="min-w-0 space-y-4">
<?php include __DIR__.'/partials/process.php'; ?>
<?php include __DIR__.'/partials/ai_sidebar.php'; ?>
                </div>
                <div class="min-w-0 space-y-4">
<?php include __DIR__.'/partials/sla_sidebar.php'; ?>
<?php if ($can_draft) include __DIR__.'/partials/drafts_sidebar.php'; ?>
                </div>
            </div>
<?php include __DIR__.'/partials/analysis.php'; ?>
            <details class="group rounded-xl border border-gray-200 dark:border-gray-700">
                <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-gray-700 dark:text-gray-200">Вложения и история документа</summary>
                <div class="space-y-4 p-3 pt-0">
<?php include __DIR__.'/partials/attachments.php'; ?>
<?php include __DIR__.'/partials/journal_sidebar.php'; ?>
<?php include __DIR__.'/partials/field_events.php'; ?>
                </div>
            </details>
        </aside>
    </div>
</div>

<?php echo View::factory('partials/live_status_js'); ?>
