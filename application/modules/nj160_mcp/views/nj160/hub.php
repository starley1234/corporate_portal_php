<?php
defined('SYSPATH') or die('No direct script access.');
/**
 * nj160_mcp: хаб — подключение ИИ-агентов к сервисам N2J через MCP.
 * Пользователь управляет своими ключами; управление видит все токены.
 */
$card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700 dark:bg-gray-800';
$lbl  = 'text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500';
$new_token = (string) Session::instance()->get_once('nj160_new_token');
?>
<div class="n2j-ds w-full space-y-6 px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <div class="<?php echo $card; ?>">
        <h1 class="n2j-h1"><i class="fas fa-plug mr-2 text-blue-500"></i>MCP-хаб N2J</h1>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Подключите N2J к своему ИИ-агенту (Claude, Cursor, любой MCP-клиент): поиск организаций, профили компаний, письма, досье, закупки — как инструменты агента.</p>
        <p class="n2j-hint mt-2">Инструментов в реестре: <?php echo count(Service_Nj160_Registry::mcp_list()); ?> · эндпоинт: <code class="rounded bg-gray-100 px-1.5 py-0.5 font-mono dark:bg-gray-900"><?php echo HTML::chars($base_url); ?>mcp/api/rpc</code></p>

        <div class="mt-3 rounded-xl border border-blue-200 bg-blue-50/70 p-3 dark:border-blue-900/60 dark:bg-blue-950/40">
            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                <i class="fas fa-layer-group mr-1 text-blue-600 dark:text-blue-400"></i>
                Это MCP только нашего сервиса. Всемирный каталог — отдельно.
            </p>
            <p class="mt-1 text-xs leading-5 text-gray-700 dark:text-gray-300">
                Здесь — инструменты N2J: организации, профили, письма, закупки. Он не ищет чужие серверы.
                Нужны тысячи сторонних MCP (погода, базы данных, документация, поиск)? Откройте
                <a class="font-semibold text-blue-700 hover:underline dark:text-blue-300" href="/mcphub">глобальный каталог /mcphub</a>
                — у него свой шлюз <code class="font-mono text-xs">/mcphub/mcp</code>, и он умеет вызывать чужие серверы.
                Оба адреса работают одновременно.
            </p>
        </div>
    </div>

    <!-- Мои ключи -->
    <div class="<?php echo $card; ?>">
        <h2 class="n2j-h3">Мои ключи доступа</h2>
        <?php if ($me === NULL): ?>
        <p class="n2j-hint mt-2"><a href="/auth/login?return_url=mcp" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">Войдите</a>, чтобы выпустить персональный ключ.</p>
        <?php else: ?>
        <?php if ($new_token !== ''): ?>
        <div class="mt-3 rounded-xl border border-emerald-300 bg-emerald-50 p-3 dark:border-emerald-700 dark:bg-emerald-950/40">
            <p class="text-xs font-bold uppercase text-emerald-700 dark:text-emerald-300">Ключ создан — покажется один раз, сохраните сейчас</p>
            <p class="mt-1 break-all rounded-lg bg-white p-2 font-mono text-xs dark:bg-gray-900"><?php echo HTML::chars($new_token); ?></p>
        </div>
        <?php endif; ?>
        <form method="post" action="/mcp" class="mt-3 flex flex-wrap items-end gap-2">
            <input type="hidden" name="csrf" value="<?php echo Security::token(); ?>">
            <input type="hidden" name="act" value="create">
            <input type="text" name="label" maxlength="120" placeholder="Название ключа: «Claude на ноуте»"
                   class="n2j-input w-64">
            <button type="submit" class="n2j-btn-cta">Выпустить ключ</button>
        </form>
        <?php if ( ! empty($tokens)): ?>
        <ul class="mt-3 divide-y divide-gray-50 dark:divide-gray-700/40">
            <?php foreach ($tokens as $t): ?>
            <li class="flex flex-wrap items-center gap-2 py-2 text-sm">
                <i class="fas fa-key <?php echo $t['revoked_at'] !== NULL ? 'text-gray-300' : 'text-amber-400'; ?>"></i>
                <span class="font-semibold <?php echo $t['revoked_at'] !== NULL ? 'text-gray-400 line-through' : 'text-gray-800 dark:text-gray-200'; ?>"><?php echo HTML::chars($t['label']); ?></span>
                <span class="text-xs text-gray-400">вызовов: <?php echo (int) $t['calls']; ?></span>
                <?php if ($t['revoked_at'] === NULL): ?>
                <form method="post" action="/mcp">
                    <input type="hidden" name="csrf" value="<?php echo Security::token(); ?>">
                    <input type="hidden" name="act" value="revoke">
                    <input type="hidden" name="id" value="<?php echo (int) $t['id']; ?>">
                    <button data-n2j-confirm="Агент с этим ключом сразу потеряет доступ." data-n2j-title="Отозвать ключ?" data-n2j-ok="Отозвать" type="submit" class="n2j-btn-danger n2j-btn-sm">отозвать</button>
                </form>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Подключение -->
    <div class="<?php echo $card; ?>">
        <h2 class="n2j-h3">Как подключить (2 минуты)</h2>
        <ol class="mt-2 list-decimal space-y-2 pl-5 text-xs text-gray-600 dark:text-gray-300">
            <li>Выпустите ключ выше.</li>
            <li>Добавьте сервер в конфиг агента:</li>
        </ol>
        <pre class="mt-2 overflow-x-auto whitespace-nowrap rounded-lg bg-gray-900 p-3 text-xs leading-relaxed text-gray-100">{
  "mcpServers": {
    "n2j": {
      "url": "<?php echo HTML::chars($base_url); ?>mcp/api/rpc",
      "headers": { "Authorization": "Bearer n2jmcp_ВАШ_КЛЮЧ" }
    }
  }
}</pre>
        <p class="n2j-hint mt-2">JSON-RPC 2.0: initialize → tools/list → tools/call. Результаты ошибок помечены isError — агент понимает причину и корректирует вызов.</p>
    </div>

    <?php if ( ! empty($tools)): ?>
    <div class="<?php echo $card; ?>">
        <h2 class="n2j-h3">Что умеет агент (витрина инструментов)</h2>
        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($tools as $tool): ?>
            <div class="rounded-lg border border-gray-100 bg-gray-50/60 p-3 dark:border-gray-700/60 dark:bg-gray-900/40">
                <p class="font-mono text-xs font-bold text-blue-700 dark:text-blue-300"><?php echo HTML::chars($tool['name']); ?></p>
                <p class="mt-1 text-xs leading-snug text-gray-600 dark:text-gray-400"><?php echo HTML::chars((string) ($tool['description'] ?? '')); ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ( ! empty($hub_conf)): ?>
    <div class="n2j-card mt-6">
        <div class="flex flex-wrap items-center gap-2">
            <h2 class="n2j-h3"><i class="fas fa-sliders mr-1.5 text-blue-500"></i>Каталог для агентов (что увидит сторонний ИИ)</h2>
            <form method="post" action="/mcp" class="ml-auto" data-nj160-reset>
                <input type="hidden" name="csrf" value="<?php echo HTML::chars(Security::token()); ?>">
                <input type="hidden" name="act" value="hub_reset">
                <button type="submit" class="n2j-btn-secondary n2j-btn-sm">Сбросить ограничения</button>
            </form>
        </div>
        <p class="n2j-hint">Отмеченные категории видны агентам с обычными ключами. Инструмент управленияы (nj160_hub_*, nj600_*, расписания пиналки) видны только ключам администраторов вне зависимости от галочек.</p>
        <form method="post" action="/mcp" class="mt-3">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars(Security::token()); ?>">
            <input type="hidden" name="act" value="hub_allow">
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                <?php foreach (($hub_conf['categories'] ?? []) as $c): ?>
                <label class="flex items-start gap-2 rounded-lg border border-gray-100 px-3 py-2 text-sm dark:border-gray-700">
                    <input type="checkbox" name="cats[]" value="<?php echo HTML::chars($c['category']); ?>"
                        <?php echo ($hub_conf['allowed_categories'] === NULL OR in_array($c['category'], (array) $hub_conf['allowed_categories'], TRUE)) ? 'checked' : ''; ?>
                        class="mt-0.5 h-4 w-4 rounded accent-blue-600">
                    <span class="min-w-0">
                        <span class="block truncate font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars($c['category']); ?></span>
                        <span class="block text-xs text-gray-400"><?php echo count($c['tools']); ?> инстр.<?php echo $c['admin_only'] > 0 ? ' · '.$c['admin_only'].' только управление' : ''; ?></span>
                    </span>
                </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="n2j-btn-cta mt-3">Сохранить каталог</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if ( ! empty($all_tokens)): ?>
    <div class="<?php echo $card; ?>">
        <h2 class="n2j-h3"><i class="fas fa-user-shield mr-1.5 text-gray-400"></i>Все ключи (управление)</h2>
        <div class="mt-2 overflow-x-auto">
            <table class="n2j-table">
                <thead class="text-gray-400"><tr><th class="py-1.5 pr-3">Ключ</th><th class="py-1.5 pr-3">Владелец</th><th class="py-1.5 pr-3">Вызовов</th><th class="py-1.5 pr-3">Создан</th><th class="py-1.5">Статус</th></tr></thead>
                <tbody class="divide-y divide-gray-50 dark:divide-gray-700/40">
                <?php foreach ($all_tokens as $t): ?>
                    <tr>
                        <td class="py-1.5 pr-3 font-semibold"><?php echo HTML::chars($t['label']); ?></td>
                        <td class="py-1.5 pr-3 text-gray-500"><?php echo HTML::chars((string) $t['email']); ?></td>
                        <td class="py-1.5 pr-3"><?php echo (int) $t['calls']; ?></td>
                        <td class="py-1.5 pr-3 text-gray-400"><?php echo HTML::chars(Date::formatted_time((string) $t['created_at'], 'd.m.Y')); ?></td>
                        <td class="py-1.5"><?php echo $t['revoked_at'] !== NULL ? 'отозван' : 'активен'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
