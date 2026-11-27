<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Шапка страницы модуля по эталону v6 (/pages/required_design_sample):
 * крошки → H1 + плашка + кнопки справа → одна-две фразы пояснения.
 *
 *   <?php echo View::factory('partials/page_header', [
 *       'crumbs'  => [['Сервисы', '/'], ['Генератор страниц']],  // последняя — текущая
 *       'title'   => 'Генератор страниц',
 *       'pill'    => ['Модуль PRX', 'fa-layer-group'],             // необязательно
 *       'actions' => [                                              // необязательно
 *           ['label' => 'Новая запись', 'href' => '/x/new', 'icon' => 'fa-plus', 'role' => 'cta'],
 *           ['label' => 'Пересчитать', 'icon' => 'fa-play', 'role' => 'process', 'attrs' => ['id' => 'recalc']],
 *           ['html' => '<form …>…</form>'],                         // своя разметка
 *       ],
 *       'lead'    => 'Что делает страница — одной-двумя фразами.', // необязательно
 *   ]); ?>
 *
 * role: cta — создать/сохранить, process — запустить/вычислить,
 * secondary — вторичное (по умолчанию), danger — удалить. Без href кнопка
 * становится <button type="button">. Тексты экранируются, html — как есть.
 * Рецепты и правила: docs/DESIGN-SYSTEM.md.
 */
$ph_crumbs = isset($crumbs) ? array_values((array) $crumbs) : [];
$ph_title = isset($title) ? (string) $title : '';
$ph_pill = isset($pill) ? array_values((array) $pill) : [];
$ph_actions = isset($actions) ? (array) $actions : [];
$ph_lead = isset($lead) ? (string) $lead : '';
$ph_roles = [
    'cta' => 'prx-btn-cta',
    'process' => 'prx-btn-process',
    'secondary' => 'prx-btn-secondary',
    'danger' => 'prx-btn-danger',
];
?>
<header class="prx-page-head">
<?php if ($ph_crumbs): ?>
    <nav class="prx-crumbs flex-wrap" aria-label="Хлебные крошки">
<?php foreach ($ph_crumbs as $ph_i => $ph_crumb): ?>
<?php $ph_crumb = array_values((array) $ph_crumb); ?>
<?php $ph_url = isset($ph_crumb[1]) ? (string) $ph_crumb[1] : ''; ?>
<?php if ($ph_i > 0): ?>
        <span class="prx-crumbs-sep">/</span>
<?php endif; ?>
<?php if ($ph_url !== ''): ?>
        <a href="<?php echo HTML::chars($ph_url); ?>"><?php echo HTML::chars((string) $ph_crumb[0]); ?></a>
<?php else: ?>
        <span class="prx-crumbs-current" aria-current="page"><?php echo HTML::chars((string) $ph_crumb[0]); ?></span>
<?php endif; ?>
<?php endforeach; ?>
    </nav>
<?php endif; ?>

    <div class="<?php echo $ph_crumbs ? 'mt-2.5 ' : ''; ?>flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="prx-h1"><?php echo HTML::chars($ph_title); ?></h1>
<?php if ($ph_pill): ?>
            <span class="prx-pill"><?php if ( ! empty($ph_pill[1])): ?><i class="fas <?php echo HTML::chars((string) $ph_pill[1]); ?> text-xs"></i><?php endif; ?><?php echo HTML::chars((string) $ph_pill[0]); ?></span>
<?php endif; ?>
<?php // Request::current() пуст вне HTTP-цикла (CLI, рендер из теста/задачи):
      // тогда звёздочки нет — '/' ниже её отключает, Auth не трогаем. ?>
<?php $ph_request = Request::current(); ?>
<?php $ph_pin_href = $ph_request ? '/'.ltrim((string) $ph_request->uri(), '/') : '/'; ?>
<?php if (($pin ?? TRUE) !== FALSE AND $ph_title !== '' AND $ph_pin_href !== '/' AND Auth::instance()->logged_in()): ?>
<?php // Избранное для любой страницы (prx110): состояние и клик ведёт navpalette.js
      // (syncCards/delegation), серверу достаточно адреса и названия. ?>
            <button type="button" data-nav-pin-card="<?php echo HTML::chars($ph_pin_href); ?>"
                    data-nav-pin-title="<?php echo HTML::chars($ph_title); ?>"
                    data-nav-pin-icon="<?php echo HTML::chars('fas '.( ! empty($ph_pill[1]) ? (string) $ph_pill[1] : 'fa-star')); ?>"
                    class="nav-pin-card inline-flex h-8 w-8 items-center justify-center rounded-lg text-sm text-gray-400 transition hover:text-amber-500 dark:text-gray-500"
                    title="В Избранное" aria-label="В Избранное: <?php echo HTML::chars($ph_title); ?>" aria-pressed="false"><i class="fas fa-star"></i></button>
<?php endif; ?>
        </div>
<?php if ($ph_actions): ?>

        <div class="flex flex-wrap items-center gap-2">
<?php foreach ($ph_actions as $ph_action): ?>
<?php $ph_action = (array) $ph_action; ?>
<?php if (isset($ph_action['html'])): ?>
            <?php echo $ph_action['html']; ?>

<?php else: ?>
<?php
    $ph_role = isset($ph_action['role'], $ph_roles[$ph_action['role']]) ? $ph_roles[$ph_action['role']] : 'prx-btn-secondary';
    $ph_href = isset($ph_action['href']) ? (string) $ph_action['href'] : '';
    $ph_attrs = isset($ph_action['attrs']) ? (array) $ph_action['attrs'] : [];
    $ph_attrs['class'] = trim($ph_role.' '.(isset($ph_attrs['class']) ? (string) $ph_attrs['class'] : ''));

    if ($ph_href !== '')
    {
        $ph_attrs['href'] = $ph_href;
    }
    else
    {
        $ph_attrs['type'] = 'button';
    }

    $ph_tag = ($ph_href !== '') ? 'a' : 'button';
?>
            <<?php echo $ph_tag; ?><?php echo HTML::attributes($ph_attrs); ?>>
<?php if ( ! empty($ph_action['icon'])): ?>
                <i class="fas <?php echo HTML::chars((string) $ph_action['icon']); ?>"></i>
<?php endif; ?>
                <span><?php echo HTML::chars(isset($ph_action['label']) ? (string) $ph_action['label'] : ''); ?></span>
            </<?php echo $ph_tag; ?>>
<?php endif; ?>
<?php endforeach; ?>
        </div>
<?php endif; ?>
    </div>
<?php if ($ph_lead !== ''): ?>
    <p class="prx-lead mt-2"><?php echo HTML::chars($ph_lead); ?></p>
<?php endif; ?>
</header>
