<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/** @var string $webhook_url @var array $presets @var bool $can_admin @var bool $mqtt_enabled @var int $active_skills */
$steps = [
	['Создайте навык в консоли', 'Зайдите на dialogs.yandex.ru и создайте диалог-навык (можно приватный — только для вас). Тип подключения — «Webhook URL».'],
	['Укажите адрес вебхука', 'В настройках навыка впишите адрес ниже. Если настроите несколько навыков, каждому можно выдать свой токен — адрес станет чуть длиннее и не перепутается.'],
	['Задайте роль и характер', 'В панели управления выберите готовый пресет (врач, юрист, детский аниматор…) или напишите свой системный промпт. Там же настраиваются приветствие, память диалога и кнопки.'],
	['Скажите: «Алиса, запусти …»', 'Имя активации вы задаёте в консоли Яндекса. Дальше просто говорите — отвечает ИИ и помнит последние реплики разговора.'],
];
$faq = [
	['Какой ИИ отвечает?', 'Платформа использует собственный шлюз к языковым моделям (модуль llm): модель и «температуру» можно выбрать отдельно для каждого навыка в панели управления.'],
	['Помнит ли навык разговор?', 'Да: последние реплики (сколько именно — настраивается) в пределах окна времени улетают в контекст модели. Реплики старше окна и превышающие глубину памяти забываются.'],
	['Кто может настроить навык?', 'Панель управления доступна администратору сайта. Публичная страница ничего не раскрывает: названия навыков, промпты и статистика — только внутри панели.'],
	['Что слышно, а что видно?', 'Ответ озвучивается Алисой (для речи чистятся markdown и ссылки), а на экране колонки или приложения показывается текст и кнопки быстрых ответов.'],
	['Можно ли вывести ответ на домашний дисплей?', 'Да: ответ навыка можно публиковать в MQTT-топик — например, на дисплей ESP32. Брокер и топик задаются переменными окружения и настройкой навыка.'],
];
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
	<div class="flex flex-wrap items-start justify-between gap-4">
		<div>
			<h1 class="n2j-h1 flex items-center gap-3"><i class="fas fa-microphone-lines text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Навыки Алисы</h1>
			<p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">Голосовые навыки для Яндекс.Алисы с ответами искусственного интеллекта: вебхук сайта принимает запросы Диалогов, модель отвечает с памятью разговора, роли и характер настраиваются в панели управления.</p>
		</div>
		<span class="n2j-badge n2j-badge-info"><i class="fas fa-circle-check" aria-hidden="true"></i><?php echo $active_skills > 0 ? 'Работает: навыков — '.$active_skills : 'Ждёт настройки' ?></span>
	</div>

	<div class="mt-6 grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
		<main class="min-w-0 space-y-5">
			<section class="n2j-card" aria-labelledby="alice-how-title">
				<h2 id="alice-how-title" class="n2j-h3">Как подключить свой навык</h2>
				<ol class="mt-4 space-y-4">
					<?php foreach ($steps as $i => $step): ?>
						<li class="flex gap-3">
							<span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-blue-50 text-sm font-bold text-blue-700 dark:bg-blue-950 dark:text-blue-300"><?php echo $i + 1; ?></span>
							<div><p class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($step[0]); ?></p><p class="mt-1 text-sm leading-relaxed text-gray-500 dark:text-gray-400"><?php echo HTML::chars($step[1]); ?></p></div>
						</li>
					<?php endforeach; ?>
				</ol>
				<div class="mt-5 rounded-lg border border-blue-200 bg-blue-50/60 p-4 dark:border-blue-900 dark:bg-blue-950/20">
					<p class="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300">Адрес вебхука для консоли Диалогов</p>
					<code class="mt-2 block break-all rounded-lg bg-white px-3 py-2.5 text-sm font-semibold text-gray-900 dark:bg-gray-900 dark:text-blue-300"><?php echo HTML::chars($webhook_url); ?></code>
					<p class="mt-2 text-xs leading-relaxed text-blue-800 dark:text-blue-400">POST, JSON протокола Алисы. GET браузером отвечает подсказкой — так можно проверить, что адрес живой.</p>
				</div>
			</section>

			<section class="n2j-card" aria-labelledby="alice-roles-title">
				<h2 id="alice-roles-title" class="n2j-h3">Готовые роли</h2>
				<p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Пресеты системного промпта — в панели управления навыком одна кнопка задаёт характер собеседника.</p>
				<div class="mt-4 flex flex-wrap gap-2">
					<?php foreach ($presets as $preset): ?>
						<span class="n2j-badge n2j-badge-wait"><i class="fas fa-user-gear mr-1.5 text-blue-500" aria-hidden="true"></i><?php echo HTML::chars($preset['title']); ?></span>
					<?php endforeach; ?>
				</div>
				<p class="n2j-hint mt-4">Свой пресет пишется текстом: можно попросить модель отвечать коротко, строго по-русски или в характере конкретного персонажа.</p>
			</section>

			<section class="n2j-card" aria-labelledby="alice-faq-title">
				<h2 id="alice-faq-title" class="n2j-h3">Частые вопросы</h2>
				<div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
					<?php foreach ($faq as $item): ?>
						<details class="py-2">
							<summary class="cursor-pointer py-1 text-sm font-medium text-gray-700 dark:text-gray-200"><?php echo HTML::chars($item[0]); ?></summary>
							<p class="n2j-hint mt-2"><?php echo HTML::chars($item[1]); ?></p>
						</details>
					<?php endforeach; ?>
				</div>
			</section>
		</main>

		<aside class="space-y-4">
			<?php if ($can_admin): ?>
				<section class="rounded-xl border border-blue-200 bg-blue-50/60 p-5 dark:border-blue-900/60 dark:bg-blue-950/30">
					<h2 class="text-sm font-semibold text-blue-900 dark:text-blue-200">Панель управления</h2>
					<p class="mt-1 text-xs leading-relaxed text-blue-800 dark:text-blue-300">Навыки, промпты, память, журнал диалогов и тестовая консоль — прогоните фразу через весь пайплайн без колонки.</p>
					<a href="/alice/admin" class="n2j-btn-process mt-3">Настроить навыки</a>
				</section>
			<?php endif; ?>
			<section class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-shield-halved mr-2 text-blue-500" aria-hidden="true"></i>Приватность</h2>
				<ul class="mt-3 space-y-2 text-xs leading-relaxed text-gray-600 dark:text-gray-300">
					<li>Идентификатор пользователя Яндекса хранится коротким хешем — по нему нельзя восстановить аккаунт.</li>
					<li>Журнал разговоров нужен для памяти диалога; старше 180 дней — удаляется штатной чисткой платформы.</li>
					<li>Опасный контент (маркировка Яндекса) получает вежливый отказ без обращения к модели.</li>
					<li>Повторный запрос Яндекса не дёргает модель повторно — отдаётся уже сохранённый ответ.</li>
				</ul>
			</section>
			<section class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-house-signal mr-2 text-blue-500" aria-hidden="true"></i>Домашние дисплеи</h2>
				<p class="mt-3 text-xs leading-relaxed text-gray-600 dark:text-gray-300">Ответ навыка можно публиковать в MQTT — например, на дисплей ESP32 в прихожей. <?php echo $mqtt_enabled ? 'Публикация включена и настраивается топиком в карточке навыка.' : 'Публикация выключена на уровне окружения (NJ657_MQTT_ENABLED).'; ?></p>
			</section>
			<p class="n2j-hint px-1"><i class="fas fa-lock mr-1" aria-hidden="true"></i>Сервис работает на собственном вебхуке сайта: запросы Яндекса не уходят третьим сторонам, модель вызывается через общий шлюз платформы.</p>
		</aside>
	</div>
</div>
