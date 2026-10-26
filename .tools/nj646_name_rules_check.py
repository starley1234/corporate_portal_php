# -*- coding: utf-8 -*-
"""Проверка правил имени товара (`Service_Nj646_About::label_name`).

Зеркало PHP-правил на python: в песочнице PHP нет, а правила имени (адрес сайта,
телефоны, реквизиты не должны становиться названием товара) ломаются тихо.
При правке `label_name()/_name_cut()/_title_looks_like_name()` поправить и здесь:
керамика проверяет ровно те кейсы, что перечислены ниже.

Запуск: python3 .tools/nj646_name_rules_check.py → «провалов: 0 из N».
"""

import re

CUT = re.compile(r'\b(?:состав|ингредиент|изготовит|производител|импорт[её]р|адрес|срок|годен|хранить|услови[яе]|масса|нетто|брутто|госстандарт|гост|ту\s|штрих|партия|дата\s+изготовл|тел\.?|телефон|факс|инн|огрн|кпп|сайт|www|http)', re.I | re.U)
SERVICE = re.compile(r'\b(состав|ингредиент|ингредиенты|энергетическ|пищевая ценност|срок годност|годен до|условия хранен|хранить|изготовит|импорт|адрес|товарн(ый|ого) знак|знак обращения|штрих|госстандарт|гост|ту\s|транспорт|упакован|партия|дата изготовл|белк|жир|углевод|ккал|кдж|масса нетто|нетто|брутто|допускается|не допускается)', re.I | re.U)
CONTACTS = re.compile(r'\b(?:www\.|сайт|интернет-магазин|официальн\w+ сайт|магазин)\b', re.I | re.U)
DOMAIN = re.compile(r'\b[a-z0-9\-]{2,}\.(?:ru|com|su|net|org|biz|info|pro)\b', re.I | re.U)
PHONE = re.compile(r'\b(?:8|7)?[\s\-]?\(?\d{3}\)?[\s\-]?\d{3}[\s\-]?\d{2}[\s\-]?\d{2}\b', re.U)
REQ = re.compile(r'\b(?:инн|огрн|кпп|ул|обл|край|респ|район|р-н|кв|стр|корп|наб|пер|проезд|проспект|шоссе|микрорайон|пгт|пос|рп|а/я)\b|\b[дг]\b\s*[\dА-ЯЁA-Z]|^(?:ооо|оао|зао|пао|ао|ип|оо|тоо|чп)\b', re.I | re.U)
WEIGHT = re.compile(r'^[\d\s\.,]+\s*(г|гр|кг|мг|мл|л|шт|см)\b', re.I | re.U)


def title_clean(value):
    value = re.sub(r'\s+', ' ', str(value or '')).strip()
    if not value:
        return ''
    value = value.strip('"\'`.,;·-–— ')
    value = re.sub(r'^[«„“”]+', '', value)
    value = re.sub(r'[»“”]+$', '', value)
    value = value.strip('"\'`.,;·-–— ')
    if len(value) < 2 or not re.search(r'[A-Za-zА-Яа-яЁё]', value):
        return ''
    return value[:120]


def name_cut(value):
    value = title_clean(value)
    value = CUT.split(value, 1)[0]
    parts = [x for x in re.split(r'\s*,\s*', title_clean(value))]
    if len(parts) > 1:
        value = parts[0] if (len(parts[0]) >= 8 or len(parts) > 2) else parts[0] + ', ' + parts[1]
    return re.sub(r'\s+', ' ', value).strip(' ,;.-–—')


def looks_like_name(line):
    line = title_clean(line)
    n = len(line)
    if n < 3 or n > 80:
        return False
    if re.match(r'^[\d\s\.,\-\/\+:№%×*]+$', line) or re.match(r'^[+\d\s\-()]{7,}$', line):
        return False
    if line.lower().startswith('http'):
        return False
    if re.search(r'\S+@\S+\.\S+', line):
        return False
    if CONTACTS.search(line) or DOMAIN.search(line) or PHONE.search(line):
        return False
    if REQ.search(line):
        return False
    if SERVICE.search(line):
        return False
    if WEIGHT.match(line):
        return False
    letters = len(re.findall(r'[A-Za-zА-Яа-яЁё]', line))
    digits = len(re.findall(r'\d', line))
    return letters >= 3 and letters >= digits


def label_name(value):
    value = name_cut(value)
    return value if looks_like_name(value) else ''


samples = [
    ('наименование с хвостом', 'Мыло туалетное «Лаванда», изготовитель ООО «Ромашка», сайт www.romashka.ru', 'Мыло туалетное «Лаванда»'),
    ('адрес сайта в имени', 'www.romashka.ru', ''),
    ('интернет-магазин', 'интернет-магазин мыла', ''),
    ('имя с весом', 'Шоколад молочный «Алёнка», 100 г', 'Шоколад молочный «Алёнка»'),
    ('короткое имя + уточнение', 'Мыло, лаванда', 'Мыло, лаванда'),
    ('телефон', '8 800 555 35 35', ''),
    ('юрлицо', 'ООО «Ромашка»', ''),
    ('адрес', 'Московская обл., г. Мытищи, ул. Ленина, д. 1', ''),
    ('служебная строка', 'Состав: aqua, sodium cocoate', ''),
    ('ГОСТ', 'ГОСТ 28546-2014', ''),
    ('нормальное имя', 'Мыло туалетное детское', 'Мыло туалетное детское'),
    ('имя + марка', 'Крем для рук «Бархат», ТМ Barhat', 'Крем для рук «Бархат»'),
]

bad = 0
for title, given, want in samples:
    got = label_name(given)
    mark = 'ок  ' if got == want else 'ПЛОХО'
    if got != want:
        bad += 1
    print(f'{mark} {title:26} {got!r:40} (ждали {want!r})')

print('\nпровалов:', bad, 'из', len(samples))
