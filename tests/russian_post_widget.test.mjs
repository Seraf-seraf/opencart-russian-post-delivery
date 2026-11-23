import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../upload/catalog/view/javascript/shipping/russian_post.js', import.meta.url), 'utf8');

test('a failed widget request waits for an explicit retry', () => {
  const handlers = new Map();
  const pending = [];
  const attributes = new Map([['data-state', 'idle']]);
  const status = {message: '', hidden: true};
  const retry = {hidden: true};
  let removed = false;
  const document = {};
  const box = {
    length: 1,
    remove() { removed = true; return this; },
    attr(name, value) {
      if (value === undefined) return attributes.get(name);
      attributes.set(name, value);
      return this;
    },
    find(selector) {
      const target = selector === '[data-russian-post-status]' ? status : retry;
      return {
        text(value) { target.message = value; return this; },
        prop(name, value) { target[name] = value; return this; }
      };
    }
  };
  const input = {
    length: 1,
    is() { return true; },
    off() { return this; },
    on() { return this; }
  };
  const events = {
    off(name) { handlers.delete(name); return this; },
    on(name, selector, callback) {
      handlers.set(name, typeof selector === 'function' ? selector : callback);
      return this;
    }
  };
  function $(value) {
    if (typeof value === 'function') { value(); return; }
    if (value === document) return events;
    if (value === '#russian-post-widget-wrap') return box;
    if (value === 'input[value="russian_post.office"]') return input;
    throw new Error(`Unexpected selector: ${value}`);
  }
  $.getJSON = () => {
    const request = {
      done(callback) { pending.push(callback); return this; },
      fail() { return this; }
    };
    return request;
  };
  const window = {jQuery: $, setTimeout, clearTimeout};
  vm.runInNewContext(script, {window, document, Promise});

  assert.equal(pending.length, 1);
  pending.shift()({error: 'Не настроен идентификатор виджета'});
  handlers.get('ajaxComplete.russianPost')();
  assert.equal(attributes.get('data-state'), 'error');
  assert.equal(status.message, 'Не настроен идентификатор виджета');
  assert.equal(retry.hidden, false);
  assert.equal(pending.length, 0, 'an unrelated AJAX completion must not retry automatically');

  handlers.get('click.russianPostRetry')();
  assert.equal(pending.length, 1, 'the retry button sends one new request');
  pending.shift()({error: 'Не настроен идентификатор виджета'});
  handlers.get('ajaxComplete.russianPost')();
  assert.equal(pending.length, 0, 'a failed retry must not start a request loop');

  handlers.get('change.russianPost').call({value: 'cdek_official.office_136', checked: true});
  assert.equal(removed, true, 'selecting another delivery method removes the postal widget');
});

test('a failed map script can be requested again', async () => {
  const handlers = new Map();
  const attributes = new Map([['data-state', 'idle']]);
  let activeScript = null;
  let attempts = 0;
  const box = {
    length: 1,
    attr(name, value) { if (value === undefined) return attributes.get(name); attributes.set(name, value); return this; },
    find() { return {text() { return this; }, prop() { return this; }, empty() { return this; }}; }
  };
  const input = {length: 1, is() { return true; }};
  const events = {
    off(name) { handlers.delete(name); return this; },
    on(name, selector, callback) { handlers.set(name, typeof selector === 'function' ? selector : callback); return this; }
  };
  const document = {
    querySelector() { return activeScript; },
    createElement() {
      const listeners = {};
      return {
        setAttribute() {},
        addEventListener(name, callback) { listeners[name] = callback; },
        remove() { if (activeScript === this) activeScript = null; },
        fire(name) { listeners[name](); }
      };
    },
    head: {appendChild(element) {
      activeScript = element;
      attempts++;
      queueMicrotask(() => {
        if (attempts === 1) element.fire('error');
        else { window.ecomStartWidget = () => {}; element.fire('load'); }
      });
    }}
  };
  function $(value) {
    if (typeof value === 'function') { value(); return; }
    if (value === document) return events;
    if (value === '#russian-post-widget-wrap') return box;
    if (value === 'input[value="russian_post.office"]') return input;
    throw new Error(`Unexpected selector: ${value}`);
  }
  $.extend = Object.assign;
  $.getJSON = () => ({done(callback) { callback({}); return this; }, fail() { return this; }});
  const window = {jQuery: $, setTimeout, clearTimeout};
  vm.runInNewContext(script, {window, document, Promise});
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(attributes.get('data-state'), 'error');
  assert.equal(activeScript, null, 'the failed script must be removed');
  handlers.get('click.russianPostRetry')();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(attempts, 2);
  assert.equal(attributes.get('data-state'), 'widget-ready');
});

test('refreshing the calculated tariff preserves the selected method and draft comment', async () => {
  const document = {};
  const fields = {
    selected: 'russian_post.office',
    comment: 'Позвоните перед доставкой',
  };
  let chooseOffice;
  let finishRefresh;
  let state = 'idle';
  const chain = { on() { return this; }, off() { return this; } };
  const widget = {
    length: 1,
    attr(name, value) {
      if (name === 'data-state') {
        if (value === undefined) return state;
        state = value;
      }
      return this;
    },
    find() { return { text() { return this; }, prop() { return this; }, empty() { return this; } }; },
  };
  const panel = {
    html(html) {
      assert.equal(html, 'fresh shipping methods');
      fields.selected = 'flat.flat';
      fields.comment = '';
    },
    find(selector) {
      if (selector === 'input[name="shipping_method"]:checked') return { val: () => fields.selected };
      if (selector === 'textarea[name="comment"]') {
        return { val(value) { if (value === undefined) return fields.comment; fields.comment = value; } };
      }
      assert.equal(selector, 'input[name="shipping_method"]');
      return { filter(matches) {
        const choice = ['flat.flat', 'russian_post.office'].find(value => matches.call({ value }));
        return { prop(name, checked) { if (name === 'checked' && checked && choice) fields.selected = choice; } };
      } };
    },
  };
  function $(target) {
    if (typeof target === 'function') return target();
    if (target === document) return chain;
    if (target === '#russian-post-widget-wrap') return widget;
    if (target === 'input[value="russian_post.office"]') return { length: 1, is: () => true };
    if (target === '#collapse-shipping-method .panel-body') return panel;
    throw new Error(`Unexpected selector: ${target}`);
  }
  $.extend = Object.assign;
  $.getJSON = () => ({ done(callback) { callback({}); return this; }, fail() { return this; } });
  $.ajax = () => ({ done(callback) { callback({ selected: { index: '644099' } }); return this; }, fail() { return this; } });
  $.get = () => ({ done(callback) { finishRefresh = callback; return this; }, fail() { return this; } });
  const window = { jQuery: $, ecomStartWidget(params) { chooseOffice = params.callbackFunction; } };
  vm.runInNewContext(script, { window, document, Promise });
  await new Promise(resolve => setImmediate(resolve));

  chooseOffice({ indexTo: '644099' });
  finishRefresh('fresh shipping methods');

  assert.equal(fields.selected, 'russian_post.office');
  assert.equal(fields.comment, 'Позвоните перед доставкой');
});
