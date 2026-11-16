// Мини-разметка: HTML <-> Markdown без потерь.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fromMd, toMd, roundTrips } from '../scripts/lib/mdlite.mjs';

test('жирный и код', () => {
  assert.equal(fromMd('**важно** и `код`'), '<b>важно</b> и <code>код</code>');
  assert.equal(toMd('<b>важно</b> и <code>код</code>'), '**важно** и `код`');
});

test('ссылка превращается в <a> с rel=noopener', () => {
  assert.match(fromMd('[док](https://example.com)'), /<a href="https:\/\/example\.com" target="_blank" rel="noopener noreferrer">док<\/a>/);
});

test('html внутри кода экранируется', () => {
  const html = fromMd('`<b>не тег</b>`');
  assert.match(html, /<code>&lt;b&gt;/);
});

test('код с backtick обрамляется двойными', () => {
  assert.equal(toMd('<code>a ` b</code>'), '`` a ` b ``');
  assert.equal(roundTrips('<code>a ` b</code>'), true);
});

test('текст без разметки не меняется', () => {
  assert.equal(fromMd('обычный текст, 100%'), 'обычный текст, 100%');
  assert.equal(roundTrips('обычный текст'), true);
});

test('не-http ссылки не превращаются в <a>', () => {
  assert.equal(fromMd('[текст](javascript:alert)'), '[текст](javascript:alert)');
});
