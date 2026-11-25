"""prx399_docmerge — слияние договоров и допсоглашений (DocMergeAI).

Каналы:
    doc.merge.delta — один файл пакета: PDF → текст → AST/дельты ДС;
    doc.merge.audit — собранный договор → риск-аудит (Map-Reduce).

Принцип модуля: модель формирует только ИНСТРУКЦИИ (replace/add/annul),
итоговый текст собирает PHP-код (Service_Prx399_Merge) — детерминированно.
"""
