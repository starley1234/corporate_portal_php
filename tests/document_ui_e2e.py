#!/usr/bin/env python3
"""Проверка обеих кнопок на тестовом стенде с работающим воркером.

ADMIN_PASSWORD=... python3 tests/document_ui_e2e.py [--legacy]
Создаёт реальные задачи с синтетическими PDF. Не запускать на production.
"""
from __future__ import annotations

import argparse
import html
import os
import re
import time

import httpx

from document_pipeline_e2e import pdf_bytes


def csrf(page: httpx.Response, path: str) -> str:
    assert page.status_code == 200, (page.request.url.path, page.status_code)
    for form in re.findall(r'<form\b[^>]*>.*?</form>', page.text, re.S):
        if f'action="{path}"' in form.split('>', 1)[0]:
            token = re.search(r'name="csrf" value="([^"]+)"', form)
            if token:
                return html.unescape(token[1])
    raise AssertionError(f'CSRF form not found: {path}')


def wait(client: httpx.Client, path: str, predicate, deadline=30) -> httpx.Response:
    until = time.monotonic() + deadline
    while time.monotonic() < until:
        page = client.get(path)
        assert page.status_code == 200, (path, page.status_code)
        if predicate(page.text):
            return page
        time.sleep(.4)
    raise AssertionError(f'Expected result not found on {path}')


def main(args):
    with httpx.Client(base_url=args.url, follow_redirects=False) as client:
        login = client.get('/auth/login')
        result = client.post('/auth/login', data={
            'csrf': csrf(login, '/auth/login'), 'username': os.getenv('ADMIN_USERNAME', 'admin'),
            'password': os.environ['ADMIN_PASSWORD'],
        })
        assert result.status_code == 302
        assert 'href="/document-extract"' in client.get('/').text
        page = client.get('/document-extract')
        assert 'Распознать пробный PDF' in page.text
        assert client.post('/document-extract', data={'sample': '1'}).status_code == 403
        for sample, marker in [(True, 'PRX - PDF recognition is working.'), (False, 'PDF uploaded from the UI')]:
            payload = {'csrf': csrf(page, '/document-extract')}
            files = None
            if sample:
                payload['sample'] = '1'
            else:
                files = {'file': ('ui-test.pdf', pdf_bytes(marker), 'application/pdf')}
            created = client.post('/document-extract', data=payload, files=files)
            assert created.status_code == 303, created.status_code
            page_with_text = wait(client, created.headers['Location'], lambda text: marker in text and 'Текст готов' in text)
            assert 'no-store' in page_with_text.headers['Cache-Control']
            print(f"OK: PDF UI ({'sample' if sample else 'upload'}) {page_with_text.request.url.path}")

        if args.legacy:
            inbox = client.get('/inbox')
            assert 'Принять пробное письмо' in inbox.text
            accepted = client.post('/inbox/upload', data={'csrf': csrf(inbox, '/inbox/upload'), 'sample': '1'})
            assert accepted.status_code == 303, accepted.status_code
            ready = wait(client, '/inbox', lambda text: 'Пробное письмо' in text and re.search(r'href="/documents/\d+"', text))
            record = re.search(r'href="(/documents/\d+)"', ready.text)[1]
            assert client.get(record).status_code == 200
            assert client.get('/analysis').status_code == 200
            assert client.get('/sla').status_code == 200
            print(f'OK: legacy sample received, registered and visible: {record}')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--url', default='http://127.0.0.1:8080')
    parser.add_argument('--legacy', action='store_true')
    main(parser.parse_args())
