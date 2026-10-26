<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Компактная ссылка «Онтология» для бизнес-модулей (профиль компании, досье,
 * холодное письмо, документы организации, справочник организаций).
 *
 * Онтология nj607 — общий словарь сервисов: одна компания/организация хранится
 * одной записью, а страница сущности показывает, где ещё она встречается и с
 * какими объектами связана. Модуль отдаёт название, ссылка ведёт на сущность.
 *
 * Параметры:
 *   title (string, обяз.) — название компании/организации;
 *   kind  (string)        — company (по умолчанию) или org;
 *   label (string)        — текст ссылки («Онтология: связи» …).
 *
 * Если онтология недоступна (нет модуля/nj-таблиц) — блок просто не рисуется:
 * посторонний сервис не должен ломать страницу бизнес-модуля.
 */
$title = trim((string) ($title ?? ''));
$kind  = (string) ($kind ?? 'company');
$label = (string) ($label ?? 'Онтология: связи');

if ($title === '' OR ! class_exists('Service_Nj607_Ontology'))
{
    return;
}

$slug = Service_Nj607_Ontology::slug($title);

if ($slug === '')
{
    return;
}

// Ссылку рисуем только на существующую сущность: битых ссылок быть не должно.
$entity = Service_Nj607_Ontology::by_slug($slug, $kind);

if ( ! $entity)
{
    return;
}

$count = Service_Nj607_Ontology::links_count_for([(int) $entity['id']]);
$url   = Service_Nj607_Ontology::entity_url($title, (string) $entity['kind']);
?>
<a href="<?php echo HTML::chars($url); ?>"
   title="Общая онтология сервисов: где ещё встречается «<?php echo HTML::chars($title); ?>» и с какими объектами связано"
   class="n2j-btn-secondary n2j-btn-sm">
    <i class="fas fa-diagram-project text-[11px] text-blue-500"></i><?php echo HTML::chars($label); ?><?php if ($count > 0): ?> <span class="rounded bg-gray-100 px-1 font-mono text-xs text-gray-500 dark:bg-gray-800 dark:text-gray-300"><?php echo (int) $count; ?></span><?php endif; ?>
</a>
