"""Архив пакета документов (ZIP) с транслитерированными именами —
чтобы файлы корректно открывались и в старых распаковщиках Windows."""
from __future__ import annotations

import io
import re
import zipfile

_TRAN = str.maketrans({
    "а": "a", "б": "b", "в": "v", "г": "g", "д": "d", "е": "e", "ё": "e",
    "ж": "zh", "з": "z", "и": "i", "й": "y", "к": "k", "л": "l", "м": "m",
    "н": "n", "о": "o", "п": "p", "р": "r", "с": "s", "т": "t", "у": "u",
    "ф": "f", "х": "h", "ц": "c", "ч": "ch", "ш": "sh", "щ": "sch", "ъ": "",
    "ы": "y", "ь": "", "э": "e", "ю": "yu", "я": "ya",
    " ": "-", ".": "-", ",": "-", "«": "", "»": "", "(": "", ")": "",
})


def slug(text: str, max_len: int = 48) -> str:
    s = str(text or "").strip().lower().translate(_TRAN)
    s = re.sub(r"[^a-z0-9\-]+", "-", s)
    s = re.sub(r"-{2,}", "-", s).strip("-")
    return s[:max_len].strip("-") or "doc"


def make_zip(files: list, org_name: str = "") -> bytes:
    """files: [(имя_в_архиве, bytes)]. Имена уже с расширениями."""
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as z:
        folder = slug(org_name) or "docs"
        seen = set()
        for name, data in files:
            arc = f"{folder}/{name}"
            base, ext = (name.rsplit(".", 1) + [""])[:2]
            k = 2
            while arc in seen:
                arc = f"{folder}/{base}-{k}.{ext}"
                k += 1
            seen.add(arc)
            z.writestr(arc, data)
    return buf.getvalue()
