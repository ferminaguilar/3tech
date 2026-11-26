const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

function setup({ cancel = false, throwSerialize = false } = {}) {
  const attrs = new Map();
  const classes = new Set();
  const body = {
    inert: false,
    classList: {
      add: (name) => classes.add(name),
      remove: (name) => classes.delete(name),
    },
    getAttribute: (name) => attrs.get(name) ?? null,
    setAttribute: (name, value) => attrs.set(name, value),
    removeAttribute: (name) => attrs.delete(name),
  };
  const buttons = [{ disabled: false }, { disabled: true }];
  const dialog = { inert: true };
  const handlers = {};
  const document = {
    body,
    querySelectorAll: (selector) =>
      selector.includes('dialog') ? [dialog] : buttons,
    addEventListener: (type, callback) => {
      handlers[type] = callback;
    },
  };
  const $ = () => ({
    on: (name, fn) => {
      handlers[name] = fn;
    },
  });
  $.active = 0;
  $.ajaxPrefilter = (callback) => {
    $.prefilter = callback;
  };
  $.Deferred = () => ({
    reject: () => ({ promise: () => Promise.reject(new Error('Blocked')) }),
  });
  let requests = 0;
  const Drupal = {
    Ajax: function Ajax(element) {
      this.element = element;
      this.options = {};
    },
  };
  Drupal.Ajax.prototype.beforeSerialize = () => {
    if (throwSerialize) throw new Error('Serialization failed');
  };
  Drupal.Ajax.prototype.beforeSubmit = () => (cancel ? false : undefined);
  Drupal.Ajax.prototype.eventResponse = function eventResponse() {
    this.beforeSerialize(this.element, this.options);
    this.ajaxing = true;
    if (this.beforeSubmit() === false) return;
    requests += 1;
  };
  Drupal.Ajax.prototype.execute = function execute() {
    requests += 1;
    return Promise.resolve();
  };
  Drupal.Ajax.prototype.success = (commands) => commands;
  Drupal.Ajax.prototype.error = () => {
    throw new Error('Request failed');
  };
  vm.runInNewContext(
    fs.readFileSync(
      path.join(__dirname, '../../source/js/save-lock.js'),
      'utf8',
    ),
    { Drupal, jQuery: $, document, Event },
  );
  const save = new Drupal.Ajax({
    name: 'mercury_editor_save',
    closest: () => true,
  });
  return {
    Drupal,
    $,
    save,
    body,
    dialog,
    buttons,
    handlers,
    requests: () => requests,
  };
}

test('save locks immediately and blocks duplicate saves and other requests', async () => {
  const env = setup();
  env.save.eventResponse();
  assert.equal(env.Drupal.mercuryEditorSaveLock.isLocked(), true);
  assert.equal(env.body.inert, true);
  assert.equal(env.body.getAttribute('aria-busy'), 'true');
  assert.equal(env.buttons[0].disabled, true);
  env.save.eventResponse();
  const undo = new env.Drupal.Ajax();
  await assert.rejects(undo.execute(), /Blocked/);
  assert.equal(env.requests(), 1);
  const raw = {};
  env.$.prefilter(raw);
  assert.equal(raw.beforeSend(), false);
});

test('lock lasts through response commands and restores prior UI states', async () => {
  const env = setup();
  env.save.eventResponse();
  let finishCommands;
  const commands = new Promise((resolve) => {
    finishCommands = resolve;
  });
  const response = env.save.success(commands);
  let refreshed = false;
  env.Drupal.mercuryEditorSaveLock.afterUnlock(() => {
    refreshed = true;
  });
  assert.equal(env.Drupal.mercuryEditorSaveLock.isLocked(), true);
  assert.equal(refreshed, false);
  finishCommands();
  await response;
  assert.equal(refreshed, true);
  assert.equal(env.body.inert, false);
  assert.equal(env.body.getAttribute('aria-busy'), null);
  assert.equal(env.dialog.inert, true);
  assert.equal(env.buttons[0].disabled, false);
  assert.equal(env.buttons[1].disabled, true);
  assert.equal(env.Drupal.mercuryEditorSaveLock.isLocked(), false);
});

test('transport errors, aborted requests, and failed commands release the lock', async () => {
  const env = setup();
  env.save.eventResponse();
  assert.throws(() => env.save.error(), /Request failed/);
  assert.equal(env.body.inert, false);
  env.save.eventResponse();
  await assert.rejects(
    env.save.success(Promise.reject(new Error('Command failed'))),
    /Command failed/,
  );
  assert.equal(env.body.inert, false);
  env.save.eventResponse();
  env.handlers['ajaxComplete.meSaveLock']({}, {}, env.save.options);
  assert.equal(env.body.inert, false);
});

test('cancelled submission and serialization errors do not strand the lock', () => {
  const cancelled = setup({ cancel: true });
  cancelled.save.eventResponse();
  assert.equal(cancelled.requests(), 0);
  assert.equal(cancelled.body.inert, false);
  const failed = setup({ throwSerialize: true });
  assert.throws(() => failed.save.eventResponse(), /Serialization failed/);
  assert.equal(failed.body.inert, false);
});

test('saves cannot start while another AJAX operation is running', () => {
  const env = setup();
  env.$.active = 1;
  env.save.eventResponse();
  assert.equal(env.requests(), 0);
  assert.equal(env.body.inert, false);
});

test('synthetic editing and submission events are blocked only while saving', async () => {
  const env = setup();
  let prevented = 0;
  const event = {
    preventDefault: () => {
      prevented += 1;
    },
    stopImmediatePropagation() {},
  };
  env.handlers.submit(event);
  assert.equal(prevented, 0);
  env.save.eventResponse();
  ['click', 'mousedown', 'keydown', 'submit'].forEach((name) =>
    env.handlers[name](event),
  );
  assert.equal(prevented, 4);
  await env.save.success(Promise.resolve());
  env.handlers.submit(event);
  assert.equal(prevented, 4);
});

test('implicit keyboard submissions use the guarded AJAX save', () => {
  const env = setup();
  env.save.element.dispatchEvent = () => env.save.eventResponse();
  let prevented = false;
  env.handlers.submit({
    target: { matches: () => true, querySelector: () => env.save.element },
    preventDefault: () => {
      prevented = true;
    },
    stopImmediatePropagation() {},
  });
  assert.equal(prevented, true);
  assert.equal(env.requests(), 1);
  assert.equal(env.body.inert, true);
});
