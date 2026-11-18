#!/usr/bin/env python3
"""HTTP smoke только для изолированного dev/staging с тестовыми данными.

Использует секреты из окружения, создаёт/удаляет подписки и публикует тестовые
события, которые остаются в журнале. Не запускать против production-данных.
"""
import http.cookiejar
import html as html_module
import json
import os
import re
import secrets
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ.get('SMOKE_URL', 'http://localhost:8080').rstrip('/')

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

jar = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect())
count = 0

def request(path, expected, data=None, headers=None):
    global count
    req = urllib.request.Request(BASE + path, data=urllib.parse.urlencode(data).encode() if data is not None else None, headers=headers or {})
    try:
        response = client.open(req, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    body = response.read().decode('utf-8', 'replace')
    if response.code != expected:
        raise AssertionError(f'{path}: wanted {expected}, got {response.code}')
    count += 1
    print(f'OK {response.code} {path}')
    return body

request('/', 302)
login = request('/auth/login', 200)
request('/auth/register', 404)
request('/prx100_auth/register', 404)
for path in ['/search/api?q=test', '/prx110/api/catalog', '/prx110/api/favorites', '/prx597/api/unread', '/employee/api/me', '/employee/api/directory']:
    json.loads(request(path, 401))
json.loads(request('/prx110/api/favorites', 401, {'href': '/employee', 'pinned': '1'}))
request('/prx120/api/stats', 403)
request('/document-extract', 403)
assert json.loads(request('/api/v1/document-extractions/1', 401)) == {'error': {'code': 'unauthorized'}}
request('/services', 403)
request('/employee', 403)
request('/prx208_events/list_subscribers', 403)
for path in ['/prx208_events/api/subscribe', '/prx208_events/api/unsubscribe', '/prx208_events/api/publish_event']:
    request(path, 403, {})
request('/prx300/admin', 403)
request('/prx595/admin', 403)
request('/about', 200)
request('/privacy', 200)
request('/terms', 200)
design_cookies = {cookie.name for cookie in jar}
design_lab = request('/design-lab?company=yellow', 200)
assert 'Выберите характер корпоративного портала PRX' in design_lab
assert 'data-company-accent="yellow"' in design_lab
for slug in ['corporate', 'industrial', 'swiss', 'product', 'editorial', 'command', 'sample-classic', 'sample-compact', 'sample-operations', 'required-design-sample', 'sidebar-navigation']:
    variant = request(f'/design-lab/{slug}?theme=dark&company=orange', 200)
    assert re.search(r'<html[^>]+data-theme="dark"', variant), slug
    assert 'data-company-accent="orange"' in variant, slug
    assert f'data-variant="{slug}"' in variant, slug
    assert 'Добрый день, Анна. Всё важное для работы — в одном месте.' in variant, slug
    assert '<link' not in variant, slug
    if slug in ('required-design-sample', 'sidebar-navigation'):
        assert 'id="dl-icon-home"' in variant, slug
        assert variant.count('class="dl-icon"') >= 40, slug
        assert 'fontawesome' not in variant.lower(), slug
    if slug == 'sidebar-navigation':
        assert variant.index('class="dl-context-nav"') < variant.index('class="dl-favorites-nav"')
        assert 'class="dl-page-tools"' in variant
        assert '<header class="dl-topbar">' not in variant
for removed_slug in ['aurora', 'sample-workbench', 'sample-reading']:
    request(f'/design-lab/{removed_slug}', 404)
request('/design-lab/not-a-design', 404)
assert {cookie.name for cookie in jar} == design_cookies, 'Design lab must not create a session cookie'
request('/vat', 404)
request('/graph3d', 404)
request('/auth/login', 403, {'username': 'admin', 'password': 'invalid'})
login = request('/auth/login', 200)
token = re.search(r'name="csrf" value="([^"]+)"', login)
assert token, 'Login form has no CSRF token'
username = os.environ.get('ADMIN_USERNAME', 'admin')
password = os.environ.get('ADMIN_PASSWORD', '')
if not password:
    raise RuntimeError('ADMIN_PASSWORD is required for authenticated smoke; load local .env')
request('/auth/login?return_url=%2F', 302, {'username': username, 'password': password, 'csrf': token[1]})
pages = {}
for path in ['/', '/services', '/dashboard', '/admin', '/prx595/admin', '/account/security', '/prx597', '/prx597/admin', '/prx560', '/prx115', '/tasks', '/prx120/monitor', '/document-extract', '/prx120/workers', '/migrate_pg', '/llm_admin', '/prx218', '/prx100/errors', '/architecture', '/employee', '/employee/directory', '/prx300/admin', '/prx300/admin/employees', '/prx300/admin/employee/0', '/prx300/admin/import', '/prx300/admin/import_template']:
    pages[path] = request(path, 200)
assert 'value="probe"' in pages['/prx120/monitor'], 'Worker probe button is missing'
assert 'Распознать пробный PDF' in pages['/document-extract']
assert 'href="/document-extract"' in pages['/']
request('/document-extract', 403, {'sample': '1'})
legacy = os.environ.get('PRX_DOCFLOW_ENABLED') == '1'
legacy_paths = {'/inbox', '/documents', '/analysis', '/sla'}
archived_paths = {'/replies', '/vlm-demo'} | (set() if legacy else legacy_paths)
for path in sorted(legacy_paths):
    request(path, 200 if legacy else 404)
for module in [390, 392, 393]:
    request(f'/prx{module}/api/health', 403 if legacy else 404)
if legacy:
    assert 'Документооборот · тестовый режим' in pages['/']
    request('/inbox/scan', 405)
    request('/inbox/upload', 403, {'sample': '1'})
else:
    request('/inbox/scan', 404)
for path in ['/replies', '/vlm-demo', '/vlm-demo/analyze',
             '/assets/js/prx398_vlm_demo.js', '/assets/vendor/pdfjs/pdf.min.mjs',
             '/incoming_developments/index.html', '/archive/docflow/README.md', '/prx394/api/health']:
    request(path, 404)

# Администратор может безопасно управлять DB-подписками; тест использует
# случайное имя события и удаляет свою запись, не меняя штатные подписки.
# Сам факт сохранения НЕ означает, что загрузка из БД включена в настройках.
subscription_path = '/prx208_events/manage_subscription'
form = request(subscription_path, 200)
subscription_csrf = html_module.unescape(re.search(r'name="csrf" value="([^"]+)"', form)[1])

def is_checked(page, name):
    tag = re.search(r'<input\b[^>]*\bid="' + re.escape(name) + r'"[^>]*>', page, re.S)
    assert tag, f'Checkbox {name} not found'
    return 'checked' in tag[0]

assert is_checked(form, 'is_active') and not is_checked(form, 'is_notification')
request(subscription_path, 403, {'event_name': 'prx208.test.csrf'})
request(subscription_path, 403, {'csrf[]': subscription_csrf, 'event_name': 'prx208.test.csrf'})
invalid = request(subscription_path, 200, {'csrf': subscription_csrf, 'event_name': '', 'is_notification': '1'})
assert 'aria-invalid="true"' in invalid
assert not is_checked(invalid, 'is_active') and is_checked(invalid, 'is_notification')

test_event = 'prx208.test.ui.' + secrets.token_hex(8)
subscriber_id = None
try:
    request(subscription_path, 302, {
        'csrf': subscription_csrf, 'event_name': test_event, 'module_id': 'integration-test',
        'callback_method': '', 'is_active': '1', 'is_notification': '1',
    })
    listing = request('/prx208_events/list_subscribers?search=' + test_event, 200)
    row = re.search(r'<tr>(?:(?!</tr>).)*' + re.escape(test_event) + r'(?:(?!</tr>).)*</tr>', listing, re.S)
    assert row, 'Created subscription was not listed'
    match = re.search(r'manage_subscription/(\d+)', row[0])
    assert match, 'Created subscription has no edit link'
    subscriber_id = match[1]
    assert 'prx-badge-ok' in row[0] and 'prx-badge-info' in row[0]
    edit = subscription_path + '/' + subscriber_id
    saved = request(edit, 200)
    assert is_checked(saved, 'is_active') and is_checked(saved, 'is_notification')
    request(edit, 302, {'csrf': subscription_csrf, 'event_name': test_event, 'module_id': 'integration-test'})
    unchecked = request(edit, 200)
    assert not is_checked(unchecked, 'is_active') and not is_checked(unchecked, 'is_notification')
    listing = request('/prx208_events/list_subscribers?search=' + test_event, 200)
    row = re.search(r'<tr>(?:(?!</tr>).)*' + re.escape(test_event) + r'(?:(?!</tr>).)*</tr>', listing, re.S)
    assert row and row[0].count('prx-badge-wait') >= 2
    delete = '/prx208_events/delete_subscription/' + subscriber_id
    request(delete, 405)
    request(delete, 403, {})
finally:
    if subscriber_id:
        request('/prx208_events/delete_subscription/' + subscriber_id, 302, {'csrf': subscription_csrf})

# API подписок использует ту же сессию и требует CSRF, а не только право роли.
api_subscribe = '/prx208_events/api/subscribe'
api_unsubscribe = '/prx208_events/api/unsubscribe'
api_publish = '/prx208_events/api/publish_event'
for path in [api_subscribe, api_unsubscribe, api_publish]:
    json.loads(request(path, 405))
    json.loads(request(path, 403, {}))
    json.loads(request(path, 403, {'csrf[]': subscription_csrf}))
api_event = 'prx208.test.api.' + secrets.token_hex(8)
base_api_data = {'csrf': subscription_csrf, 'event_name': api_event,
                 'module_id': 'integration-test', 'callback_method': 'Prx208TestSubscriber::receive'}
json.loads(request(api_subscribe, 400, {**base_api_data, 'is_active': 'invalid'}))
json.loads(request(api_subscribe, 400, {**base_api_data, 'is_active[]': '1'}))
json.loads(request(api_unsubscribe, 400, {'csrf': subscription_csrf, 'subscriber_id[]': '1'}))
json.loads(request(api_unsubscribe, 400, {'csrf': subscription_csrf, 'subscriber_id': '1', 'deactivate_only': 'invalid'}))
api_id = None
try:
    created = json.loads(request(api_subscribe, 201, {
        'csrf': subscription_csrf, 'event_name': api_event, 'module_id': 'integration-test',
        'callback_method': 'Prx208TestSubscriber::receive', 'is_active': 'false',
    }))
    api_id = str(created['subscriber_id'])
    assert not is_checked(request(subscription_path + '/' + api_id, 200), 'is_active')
    json.loads(request(api_unsubscribe, 200, {'csrf': subscription_csrf, 'subscriber_id': api_id, 'deactivate_only': '1'}))
    assert not is_checked(request(subscription_path + '/' + api_id, 200), 'is_active')
    json.loads(request(api_unsubscribe, 200, {'csrf': subscription_csrf, 'subscriber_id': api_id, 'deactivate_only': 'false'}))
    request(subscription_path + '/' + api_id, 404)
    api_id = None
finally:
    if api_id:
        request('/prx208_events/delete_subscription/' + api_id, 302, {'csrf': subscription_csrf})
json.loads(request(api_publish, 400, {'csrf': subscription_csrf, 'event_name': api_event, 'payload': 'null'}))
published = json.loads(request(api_publish, 200, {'csrf': subscription_csrf, 'event_name': api_event, 'payload': '{"value":1}'}))
assert published['status'] == 'success' and published['event_id'] is None

# Избранное приходит с первым HTML, без браузерного запроса и плейсхолдера.
favorites = json.loads(request('/prx110/api/favorites', 200))
assert isinstance(favorites['user_id'], int) and isinstance(favorites['items'], list)
favorite_hrefs = [item['href'] for item in favorites['items']]
for path in ['/', '/services', '/employee']:
    html = pages[path]
    assert re.search(r'<html[^>]+data-company-accent="(?:orange|blue|yellow)"', html), path
    assert 'data-nav-status="ready"' in html, path
    assert 'Загрузка Избранного' not in html, path
    nav = re.search(r'<nav id="sidebar-nav"[^>]*>(.*?)</nav>', html, re.S)
    assert nav, path
    assert [html_module.unescape(href) for href in re.findall(r'<a\s+href="([^"]+)"', nav[1])] == favorite_hrefs + ['/services'], path
    group = re.search(r'<div class="sidebar-group" data-nav-group="pinned">(.*?)</ul>', nav[1], re.S)
    assert group, path
    assert [html_module.unescape(href) for href in re.findall(r'<a\s+href="([^"]+)"', group[1])] == favorite_hrefs, path

# POST не принимает чужой account_id, неверный сервис и запрос без CSRF.
sidebar_tag = re.search(r'<aside id="sidebar"(.*?)>', pages['/services'], re.S)
assert sidebar_tag
nav_csrf = html_module.unescape(re.search(r'data-nav-csrf="([^"]+)"', sidebar_tag[1])[1])
account_id = favorites['user_id']
json.loads(request('/prx110/api/favorites', 403, {'account_id': account_id, 'href': '/employee', 'pinned': '1'}))
json.loads(request('/prx110/api/favorites', 403, {'csrf[]': nav_csrf, 'account_id': account_id, 'href': '/employee', 'pinned': '1'}))
json.loads(request('/prx110/api/favorites', 409, {'csrf': nav_csrf, 'account_id': account_id + 1, 'href': '/employee', 'pinned': '1'}))
json.loads(request('/prx110/api/favorites', 409, {'csrf': nav_csrf, 'account_id[]': account_id, 'href': '/employee', 'pinned': '1'}))
json.loads(request('/prx110/api/favorites', 422, {'csrf': nav_csrf, 'account_id': account_id, 'href': '//evil.test', 'pinned': '1'}))
json.loads(request('/prx110/api/favorites', 422, {'csrf': nav_csrf, 'account_id': account_id, 'href': '/employee', 'pinned': 'invalid'}))
json.loads(request('/prx110/api/favorites', 422, {'csrf': nav_csrf, 'account_id': account_id, 'href': '/employee', 'pinned[]': '1'}))
json.loads(request('/prx110/api/favorites_import', 405))
json.loads(request('/prx110/api/favorites_import', 403, {'account_id': account_id, 'hrefs': '["/employee"]'}))
json.loads(request('/prx110/api/favorites_import', 422, {'csrf': nav_csrf, 'account_id': account_id, 'hrefs': '{"href":"/employee"}'}))
json.loads(request('/prx110/api/favorites_import', 422, {'csrf': nav_csrf, 'account_id': account_id, 'hrefs': json.dumps(['/inbox'] * 101)}))
assert json.loads(request('/prx110/api/favorites?user_id=' + str(account_id + 1), 200)) == favorites

# Реальное сохранение и первый HTML без JS. Если записи не было, удаляем её
# в finally; остальные закладки и порядок сохраняются, повторное pin идемпотентно.
was_pinned = '/employee' in favorite_hrefs
try:
    saved = json.loads(request('/prx110/api/favorites', 200, {'csrf': nav_csrf, 'account_id': account_id, 'href': '/employee', 'pinned': '1'}))
    assert [item['href'] for item in saved['items']].count('/employee') == 1
    saved_again = json.loads(request('/prx110/api/favorites', 200, {'csrf': nav_csrf, 'account_id': account_id, 'href': '/employee', 'pinned': '1'}))
    assert saved_again == saved
    services_pinned = request('/services', 200)
    card = re.search(r'<button\s+type="button"\s+data-nav-pin-card="/employee"[^>]*>', services_pinned, re.S)
    assert card and 'aria-pressed="true"' in card[0], 'Card state must be present in HTML'
    employee_pinned = request('/employee', 200)
    link = re.search(r'<a href="/employee"[^>]*>', employee_pinned, re.S)
    assert link and 'aria-current="page"' in link[0], 'Favorite must be active in HTML'
finally:
    if not was_pinned:
        json.loads(request('/prx110/api/favorites', 200, {'csrf': nav_csrf, 'account_id': account_id, 'href': '/employee', 'pinned': '0'}))
assert json.loads(request('/prx110/api/favorites', 200)) == favorites
# Мобильный ряд содержит контекстные ссылки, в том числе те, которых нет
# в каталоге; он отдельный от короткого sidebar и без повторяющихся href.
for path, expected in {
    '/prx595/admin': {'/prx595/admin', '/prx595/admin/roles', '/prx595/admin/activity'},
}.items():
    mobile = re.search(r'<nav id="mobile-section-nav"[^>]*>(.*?)</nav>', pages[path], re.S)
    assert mobile, path
    hrefs = re.findall(r'<a href="([^"]+)"', mobile[1])
    assert expected <= set(hrefs), path
    assert len(hrefs) == len(set(hrefs)), path

request('/llm_admin/load', 405)
request('/llm_admin/unload', 403, {'model': 'test'})
json.loads(request('/search/api?q=test', 200))
catalog = json.loads(request('/prx110/api/catalog', 200))
assert catalog['user_id'] == account_id, 'Catalog must identify the session account for stale tabs'
catalog_hrefs = {item['href'] for item in catalog['items']}
assert {'/architecture', '/design-lab', '/prx560', '/employee', '/prx597', '/account/security', '/document-extract'} <= catalog_hrefs
assert not legacy or legacy_paths <= catalog_hrefs
assert archived_paths.isdisjoint(catalog_hrefs)
assert archived_paths.isdisjoint(favorite_hrefs)
json.loads(request('/employee/api/me', 404))  # seed-администратор намеренно не сопоставлен с сотрудником
json.loads(request('/employee/api/directory', 200))
json.loads(request('/employee/api/colleague/2147483647', 404))
print(f'HTTP smoke passed: {count} requests')
