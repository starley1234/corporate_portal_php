#!/usr/bin/env python3
"""HTTP smoke против работающего dev-сайта; секреты берёт только из окружения."""
import http.cookiejar
import json
import os
import re
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
request('/prl100_auth/register', 404)
for path in ['/search/api?q=test', '/prl110/api/catalog', '/prl597/api/unread', '/employee/api/me', '/employee/api/directory']:
    json.loads(request(path, 401))
request('/prl120/api/stats', 403)
request('/services', 403)
request('/employee', 403)
request('/prl300/admin', 403)
request('/about', 200)
request('/privacy', 200)
request('/terms', 200)
design_cookies = {cookie.name for cookie in jar}
design_lab = request('/design-lab?company=yellow', 200)
assert 'Выберите характер корпоративного портала PRL' in design_lab
assert 'data-company-accent="yellow"' in design_lab
for slug in ['corporate', 'industrial', 'swiss', 'product', 'editorial', 'command', 'sample-classic', 'sample-workbench', 'sample-compact', 'sample-reading', 'sample-operations']:
    variant = request(f'/design-lab/{slug}?theme=dark&company=orange', 200)
    assert re.search(r'<html[^>]+data-theme="dark"', variant), slug
    assert 'data-company-accent="orange"' in variant, slug
    assert f'data-variant="{slug}"' in variant, slug
    assert 'Добрый день, Анна. Всё важное для работы — в одном месте.' in variant, slug
    assert '<link' not in variant, slug
request('/design-lab/aurora', 404)
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
for path in ['/', '/services', '/dashboard', '/admin', '/prl595/admin', '/account/security', '/prl597', '/prl597/admin', '/prl560', '/prl115', '/tasks', '/prl120/monitor', '/prl120/workers', '/migrate_pg', '/llm_admin', '/prl218', '/prl100/errors', '/employee', '/employee/directory', '/prl300/admin', '/prl300/admin/employees', '/prl300/admin/employee/0', '/prl300/admin/import', '/prl300/admin/import_template']:
    request(path, 200)
request('/llm_admin/load', 405)
request('/llm_admin/unload', 403, {'model': 'test'})
json.loads(request('/search/api?q=test', 200))
json.loads(request('/prl110/api/catalog', 200))
json.loads(request('/employee/api/me', 404))  # seed-администратор намеренно не сопоставлен с сотрудником
json.loads(request('/employee/api/directory', 200))
json.loads(request('/employee/api/colleague/2147483647', 404))
print(f'HTTP smoke passed: {count} requests')
