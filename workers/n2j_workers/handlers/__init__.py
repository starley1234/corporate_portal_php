"""Реестр обработчиков каналов очереди.

Новый канал:
  1. создайте модуль рядом (my_channel.py) с async-функцией handle(ctx, payload);
  2. добавьте строку в HANDLERS ниже;
  3. перезапустите воркер.

Имя канала — <домен>.<действие> ('semantic.index', 'agent.run', ...).
Требования режима (pg/http) описаны в docstring каждого обработчика.
"""

from __future__ import annotations

from typing import Awaitable, Callable

from . import agent_run, echo, embeddings, job_search, llm_task, nj570_build, nj580_docs, nj590_design, nj591_profile, nj592_email, nj593_trends, nj598_dossier, nj600_compact, nj602_article, nj605_radar, nj606_leads, nj607_industry, nj609_watchdog, nj610_inbox, nj612_touch, nj613_digest, erp222_forge, nj639_qc, nj640_cameras, nj641_imagegen, nj642_vacancies, nj643_cad, nj645_review, review_reply, semantic_index, nj646_label, nj650_receipt, nj651_meal, nj652_plant, nj653_veclab, nj654_similar, nj661_airadar, nj662_dialog, nj666_multivoice, nj667_graph3d, nj668_draw2d, nj669_solid3d, nj669_assist, nj670_processhub, nj671_embedbench, nj672_aiscan, nj676_mcphub, nj676_registry, nj676_translate, nj676_analysis, nj676_audit

# channel -> async (ctx, payload) -> dict | None
HANDLERS: dict[str, Callable[..., Awaitable[dict | None]]] = {
    nj669_assist.IDEAS_CHANNEL: nj669_assist.handle_ideas,
    nj669_assist.CAPTION_CHANNEL: nj669_assist.handle_caption,
    nj669_solid3d.CHANNEL: nj669_solid3d.handle,
    nj669_solid3d.VERIFY_CHANNEL: nj669_solid3d.handle_verify,
    nj669_solid3d.EXPORT_CHANNEL: nj669_solid3d.handle_export,
    echo.CHANNEL: echo.handle,
    embeddings.CHANNEL: embeddings.handle,
    llm_task.CHANNEL: llm_task.handle,
    semantic_index.CHANNEL: semantic_index.handle,
    agent_run.CHANNEL: agent_run.handle,
    review_reply.CHANNEL: review_reply.handle,
    job_search.CHANNEL: job_search.handle,
    # 3D-конструктор (Sketch 2 Solid)
    "nj570.generate": nj570_build.handle,
    "nj570.clarify": nj570_build.handle_clarify,
    "nj570.render": nj570_build.handle_render,
    # Документы организации (Word/PDF/XLSX по ГОСТ)
    "nj580.generate": nj580_docs.handle,
    # Дизайн страницы (варианты оформления на выбор)
    "nj590.design": nj590_design.handle,
    # Профиль компании (webread → малая LLM → строгий JSON)
    "nj591.profile": nj591_profile.handle,
    # Холодное письмо (профиль/новости → персонализированный первый абзац)
    "nj592.email": nj592_email.handle,
    "nj593.trends": nj593_trends.handle,
    "nj598.dossier": nj598_dossier.handle,
    # Сжатие профилей предпочтений (nj600, кнопка в админке)
    "nj600.compact": nj600_compact.handle,
    # Автостатья контент-конвейера (nj602, по расписанию пиналки)
    "nj602.article": nj602_article.handle,
    # Радар заказов (nj605_radar): находки спроса по наблюдению
    "nj605.radar": nj605_radar.handle,
    # Поток заказчиков (nj606_leads): компании под оффер
    "nj606.leads": nj606_leads.handle,
    # Карта отрасли (nj607_industry): авто-отчёт по рынку
    "nj607.industry": nj607_industry.handle,
    # Сторож контрагентов (nj609_watchdog): сигналы риска
    "nj609.watchdog": nj609_watchdog.handle,
    # Серии касаний (nj612_touch): черновик письма очередного шага
    "nj612.touch": nj612_touch.handle,
    # Дайджест руководителю (nj613_digest): недельное резюме по цифрам
    "nj613.digest": nj613_digest.handle,
    # Графовый 3D-конструктор (nj667_graph3d): описание → топология → WebGL
    "nj667.generate": nj667_graph3d.handle,
    "nj667.inpaint": nj667_graph3d.handle_inpaint,
    "nj667.summary": nj667_graph3d.handle_summary,
    "nj667.embed": nj667_graph3d.handle_embed,
    "nj667.moderate": nj667_graph3d.handle_moderate,
    "nj667.ideas": nj667_graph3d.handle_ideas,
    "nj667.caption": nj667_graph3d.handle_caption,
    # .scad сборки → STL в OpenSCAD (без модели; нет движка — честный no_engine)
    "nj667.build": nj667_graph3d.handle_build,
    # Плоские иллюстрации (nj668_draw2d): описание/фото → векторная сцена
    "nj668.generate": nj668_draw2d.handle,
    "nj668.ideas": nj668_draw2d.handle_ideas,
    # Контроль качества изделий (nj639_qc): кадр/эталоны → VLM → JSON-схема → вердикт
    "nj639.analyze": nj639_qc.handle,
    # Камеры цеха (nj640_cameras): кадр → VLM → JSON-схема → вердикт (дисциплина/СИЗ/загрузка)
    "nj640.analyze": nj640_cameras.handle,
    # Вакансии компаний (nj642_vacancies): страницы «Карьера/Вакансии» → вакансии
    "nj642.vacancies": nj642_vacancies.handle,
    # Генератор изображений (nj641_imagegen): описание/правка/референсы → картинка
    "nj641.generate": nj641_imagegen.handle,
    "nj641.clear": nj641_imagegen.handle_clear,
    # Входящие под контролем (nj610_inbox): IMAP → черновики
    "nj610.inbox": nj610_inbox.handle,
    # CAD-детали (nj643_cad): описание → параметрический код build123d → движок → STEP/STL/GLB
    "nj643.cad": nj643_cad.handle,
    # Лаборатория LLM (nj645_llmlab): промпт + задача → ответ модели (демонстрация)
    "nj645.review": nj645_review.handle,
    # Разбор состава (nj646_label): фото этикетки → текст состава (vision)
    "nj646.label": nj646_label.handle,
    # Разбор состава (nj646_label): незнакомое вещество → карточка знаний (LLM)
    "nj646.study": nj646_label.handle_study,
    # Чек по фото (nj650_receipt): кадр чека → строки и суммы (vision)
    "nj650.receipt": nj650_receipt.handle,
    # Калории по фото (nj651_meal): кадр тарелки → блюда и БЖУ (vision)
    "nj651.meal": nj651_meal.handle,
    # Растение по фото (nj652_plant): кадр → вид, диагноз, уход (vision)
    "nj652.plant": nj652_plant.handle,
    # Лаборатория векторов (nj653_veclab): текст и картинки → мультимодальные
    # эмбеддинги (проверка модели, коллекция, поиск по смыслу, сравнение пары)
    "nj653.embed": nj653_veclab.handle,
    # Похожие картинки (nj654_similar): векторы банка изображений и поисковых
    # запросов; сам поиск по векторам делает сайт
    "nj654.embed": nj654_similar.handle,
    # ИИ-радар (nj661_airadar): прогресс ИИ → база знаний → отчёт/письмо
    "nj661.airadar": nj661_airadar.handle,
    # Кузница систем (erp222_forge): цикл синтеза — роли выпускают артефакты
    # (спецификация, схема, API, промпт…), Критик правит их ценность
    "erp222.forge": erp222_forge.handle,
    # Диалог двух ИИ (nj662_dialog): обмен репликами и подбор участников.
    # Ход ведёт СЕРВЕР (сайт ставит следующий обмен сам), а не браузер.
    nj662_dialog.CHANNEL: nj662_dialog.handle,
    nj662_dialog.QUICK_CHANNEL: nj662_dialog.handle_quick,
    # Многоголосая озвучка книги (nj666_multivoice): воркер ведёт модель по
    # тексту окнами, копит персонажей и режет книгу на блоки; озвучку просит
    # у сайта (ключ синтеза и файлы — на его стороне).
    nj666_multivoice.CHANNEL: nj666_multivoice.handle,
    nj666_multivoice.VOICE_CHANNEL: nj666_multivoice.handle_voice,
    # Бизнес-процессы (nj670_processhub): синтез BPMN из текста/PDF/фото/аудио,
    # process mining Lite по журналу, AI-аудит «план ↔ факт», ГОСТ-экспорт.
    nj670_processhub.CHANNEL: nj670_processhub.handle_synthesize,
    nj670_processhub.MINE_CHANNEL: nj670_processhub.handle_mine,
    nj670_processhub.DIFF_CHANNEL: nj670_processhub.handle_diff,
    nj670_processhub.EXPORT_CHANNEL: nj670_processhub.handle_export,
    nj670_processhub.ONTOLOGY_CHANNEL: nj670_processhub.handle_ontology,
    # Стенд эмбеддингов (nj671_embedbench): один материал через два пути
    # получить вектор — EMBEDDING_API_URL и EMBEDDING_MM_API_URL.
    "nj671.bench": nj671_embedbench.handle,
    # Сканер безопасности ИИ-моделей (nj672_aiscan): батарея атак OWASP LLM
    # + стресс-профиль (TTFT/ITL/TPS, лестница контекста) по любому
    # OpenAI-совместимому эндпоинту или встроенной песочнице.
    nj672_aiscan.CHANNEL: nj672_aiscan.handle,
    # MCP Hub (nj676_mcphub): автосборка каталога — GitHub + поиск + README +
    # LLM-описания + живая проверка удалённых серверов.
    nj676_mcphub.CHANNEL: nj676_mcphub.handle,
    # MCP Hub (nj676_mcphub): импорт официального реестра MCP (server.json,
    # без LLM) — тем и живёт глобальный каталог.
    nj676_registry.CHANNEL: nj676_registry.handle,
    # MCP Hub: живая проверка удалённых серверов (initialize + tools/list).
    nj676_registry.CHANNEL_PROBE: nj676_registry.handle_probe,
    # MCP Hub: русские названия и описания карточек (модель переводит
    # порциями, сайт хранит перевод рядом с оригиналом автора).
    nj676_translate.CHANNEL: nj676_translate.handle,
    # MCP Hub: ИИ-анализ каталога — что реально работает и чего не хватает
    # (срез данных + модель + структурированный отчёт в админке).
    nj676_analysis.CHANNEL: nj676_analysis.handle,
    # MCP Hub: полевой ИИ-агент исследователь открытых MCP-серверов (поиск алмазов).
    nj676_audit.CHANNEL: nj676_audit.handle,
}
