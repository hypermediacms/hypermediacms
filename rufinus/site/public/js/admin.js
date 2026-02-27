/**
 * Hypermedia CMS Admin JavaScript
 *
 * Philosophy: Progressive enhancement with vanilla JS
 * - htmx handles all server interactions
 * - JS handles local UI state (dropdowns, modals, drag/drop)
 * - Components are self-initializing via data-* attributes
 */

const Components = {};

function initComponents(root = document) {
  const elements = root.querySelectorAll('[data-component]');
  elements.forEach(el => {
    const name = el.dataset.component;
    if (Components[name] && !el._componentInit) {
      Components[name].init(el);
      el._componentInit = true;
    }
  });
}

document.addEventListener('DOMContentLoaded', () => initComponents());
document.addEventListener('htmx:afterSwap', (e) => initComponents(e.detail.elt));

/* Dropdown Component */
Components.dropdown = {
  init(el) {
    const trigger = el.querySelector('[data-dropdown-trigger]');
    const menu = el.querySelector('[data-dropdown-menu]');
    if (!trigger || !menu) return;
    trigger.addEventListener('click', (e) => {
      e.stopPropagation();
      const isOpen = menu.classList.contains('show');
      document.querySelectorAll('[data-dropdown-menu].show').forEach(m => m.classList.remove('show'));
      if (!isOpen) menu.classList.add('show');
    });
    document.addEventListener('click', () => menu.classList.remove('show'));
  }
};

/* Toast Notifications */
const Toast = {
  container: null,
  init() {
    if (this.container) return;
    this.container = document.createElement('div');
    this.container.className = 'toast-container';
    this.container.style.cssText = 'position: fixed; bottom: 1rem; right: 1rem; z-index: 1000; display: flex; flex-direction: column; gap: 0.5rem;';
    document.body.appendChild(this.container);
  },
  show(message, type = 'info', duration = 3000) {
    this.init();
    const toast = document.createElement('div');
    toast.className = 'toast toast--' + type;
    toast.style.cssText = 'padding: 0.75rem 1rem; background: ' + (type === 'success' ? '#22c55e' : type === 'error' ? '#ef4444' : '#3b82f6') + '; color: white; border-radius: 6px; font-size: 0.875rem; box-shadow: 0 4px 12px rgba(0,0,0,0.15); animation: slideInRight 0.25s ease; display: flex; align-items: center; gap: 0.5rem;';
    const icon = type === 'success' ? '\u2713' : type === 'error' ? '\u2715' : '\u2139';
    toast.innerHTML = '<span>' + icon + '</span> ' + message;
    this.container.appendChild(toast);
    setTimeout(() => {
      toast.style.animation = 'fadeOut 0.25s ease forwards';
      setTimeout(() => toast.remove(), 250);
    }, duration);
  },
  success(message) { this.show(message, 'success'); },
  error(message) { this.show(message, 'error'); },
  info(message) { this.show(message, 'info'); }
};
window.Toast = Toast;

function debounce(fn, wait) {
  if (wait === undefined) wait = 300;
  var timeout;
  return function() {
    var args = arguments;
    var context = this;
    clearTimeout(timeout);
    timeout = setTimeout(function() { fn.apply(context, args); }, wait);
  };
}

/* ApiClient — Two-phase prepare → execute for all mutations */
const ApiClient = {
  async _execute(action, type, recordId, data, responseTemplates) {
    var headers = { 'Content-Type': 'application/json' };

    // Phase 1: Prepare — get signed action token
    var prepareBody = {
      meta: { action: action, type: type },
      responseTemplates: responseTemplates || []
    };
    if (recordId) prepareBody.meta.recordId = String(recordId);
    if (data) prepareBody.meta = Object.assign(prepareBody.meta, { data: data });

    var prepareRes = await fetch('/api/content/prepare', {
      method: 'POST', headers: headers, body: JSON.stringify(prepareBody)
    });
    if (!prepareRes.ok) {
      var err = await prepareRes.json();
      throw new Error(err.error || 'Prepare failed');
    }
    var prepareData = await prepareRes.json();
    var payload = JSON.parse(prepareData.data && prepareData.data.payload ? prepareData.data.payload : '{}');
    var token = payload['htx-token'];
    if (!token) throw new Error('No action token received');

    // Phase 2: Execute — send mutation with signed token
    var executeBody = { 'htx-context': action, 'htx-token': token };
    if (recordId) executeBody['htx-recordId'] = String(recordId);
    if (data) Object.assign(executeBody, data);

    var endpoint = action === 'delete' ? '/api/content/delete'
                 : action === 'save' ? '/api/content/save'
                 : '/api/content/update';

    var res = await fetch(endpoint, {
      method: 'POST', headers: headers, body: JSON.stringify(executeBody)
    });
    if (!res.ok) {
      var errData = await res.json();
      throw new Error(errData.error || action + ' failed');
    }
    return res.json();
  },

  save(type, data, responseTemplates) {
    return this._execute('save', type, null, data, responseTemplates);
  },
  update(type, recordId, data, responseTemplates) {
    return this._execute('update', type, recordId, data, responseTemplates);
  },
  delete(type, recordId) {
    return this._execute('delete', type, recordId, null, []);
  }
};
window.ApiClient = ApiClient;

/* Sortable List (Drag & Drop) */
Components.sortable = {
  init(el) {
    var dragging = null;
    el.addEventListener('dragstart', function(e) {
      if (!e.target.matches('[draggable="true"]')) return;
      dragging = e.target;
      e.target.style.opacity = '0.5';
      e.dataTransfer.effectAllowed = 'move';
    });
    el.addEventListener('dragend', function() { if (!dragging) return; dragging.style.opacity = ''; dragging = null; });
    el.addEventListener('dragover', function(e) {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      var target = e.target.closest('[draggable="true"]');
      if (!target || target === dragging) return;
      var rect = target.getBoundingClientRect();
      var midpoint = rect.top + rect.height / 2;
      if (e.clientY < midpoint) target.parentNode.insertBefore(dragging, target);
      else target.parentNode.insertBefore(dragging, target.nextSibling);
    });
    el.addEventListener('drop', function(e) {
      e.preventDefault();
      el.dispatchEvent(new CustomEvent('sort-change', { bubbles: true, detail: { items: Array.from(el.children).map(function(c) { return c.dataset.id; }) } }));
    });
  }
};

/* Modal Component */
const Modal = {
  show(content, options) {
    if (!options) options = {};
    var overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.style.cssText = 'position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000; animation: fadeIn 0.2s ease;';
    var modal = document.createElement('div');
    modal.className = 'modal';
    modal.style.cssText = 'background: white; border-radius: 8px; padding: 1.5rem; max-width: ' + (options.width || '480px') + '; width: 90%; max-height: 90vh; overflow: auto; animation: slideInRight 0.2s ease;';
    if (typeof content === 'string') modal.innerHTML = content;
    else modal.appendChild(content);
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    var self = this;
    overlay.addEventListener('click', function(e) { if (e.target === overlay && !options.persistent) self.close(overlay); });
    var escHandler = function(e) { if (e.key === 'Escape' && !options.persistent) { self.close(overlay); document.removeEventListener('keydown', escHandler); } };
    document.addEventListener('keydown', escHandler);
    return overlay;
  },
  close(overlay) {
    overlay.style.animation = 'fadeOut 0.2s ease forwards';
    setTimeout(function() { overlay.remove(); }, 200);
  },
  confirm(message, options) {
    if (!options) options = {};
    var self = this;
    return new Promise(function(resolve) {
      var content = '<div style="text-align: center;"><p style="margin-bottom: 1.5rem; color: var(--color-gray-700);">' + message + '</p><div style="display: flex; gap: 0.75rem; justify-content: center;"><button class="btn btn--secondary" data-action="cancel">' + (options.cancelText || 'Cancel') + '</button><button class="btn ' + (options.danger ? 'btn--danger' : 'btn--primary') + '" data-action="confirm">' + (options.confirmText || 'Confirm') + '</button></div></div>';
      var overlay = self.show(content, { persistent: true });
      overlay.querySelector('[data-action="cancel"]').addEventListener('click', function() { self.close(overlay); resolve(false); });
      overlay.querySelector('[data-action="confirm"]').addEventListener('click', function() { self.close(overlay); resolve(true); });
    });
  }
};
window.Modal = Modal;

/* Keyboard Shortcuts */
const Shortcuts = {
  handlers: new Map(),
  init() {
    document.addEventListener('keydown', function(e) {
      if (e.target.matches('input, textarea, select')) return;
      var key = [e.ctrlKey ? 'ctrl' : '', e.altKey ? 'alt' : '', e.shiftKey ? 'shift' : '', e.key.toLowerCase()].filter(Boolean).join('+');
      var handler = Shortcuts.handlers.get(key);
      if (handler) { e.preventDefault(); handler(); }
    });
  },
  register(combo, handler) { this.handlers.set(combo.toLowerCase(), handler); }
};
Shortcuts.init();

/* CSS animations injected */
var adminStyle = document.createElement('style');
adminStyle.textContent = '@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } } @keyframes fadeOut { from { opacity: 1; } to { opacity: 0; } } @keyframes slideInRight { from { transform: translateX(20px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }';
document.head.appendChild(adminStyle);
