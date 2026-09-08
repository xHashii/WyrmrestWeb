(function () {
  'use strict';
  var paperdoll = document.getElementById('character-equipment');
  if (!paperdoll) return;
  var slots = Array.from(paperdoll.querySelectorAll('[data-gear-slot]'));
  paperdoll.classList.add('js-tooltips');

  function positionDetails(slot) {
    if (!slot.open) return;
    var icon = slot.querySelector('summary').getBoundingClientRect();
    var panel = slot.querySelector('.gear-tooltip');
    var width = panel.offsetWidth;
    var height = panel.offsetHeight;
    var left = slot.closest('.gear-right') ? icon.left - width - 12 : icon.right + 12;
    var top = icon.top;
    if (slot.closest('.gear-weapons')) {
      left = icon.left + icon.width / 2 - width / 2;
      top = icon.top - height - 12;
    }
    panel.style.left = Math.max(12, Math.min(left, window.innerWidth - width - 12)) + 'px';
    panel.style.top = Math.max(12, Math.min(top, window.innerHeight - height - 12)) + 'px';
    panel.classList.add('is-positioned');
  }
  function closeOthers(keep) {
    slots.forEach(function (slot) { if (slot !== keep) slot.open = false; });
  }
  slots.forEach(function (slot) {
    var timer;
    slot.addEventListener('mouseenter', function () {
      if (!window.matchMedia('(hover: hover)').matches) return;
      clearTimeout(timer);
      closeOthers(slot);
      slot.open = true;
      positionDetails(slot);
    });
    slot.addEventListener('mouseleave', function () {
      timer = setTimeout(function () {
        if (!slot.contains(document.activeElement)) slot.open = false;
      }, 150);
    });
    slot.addEventListener('toggle', function () {
      if (slot.open) {
        closeOthers(slot);
        positionDetails(slot);
      } else {
        slot.querySelector('.gear-tooltip').classList.remove('is-positioned');
      }
    });
    slot.addEventListener('focusin', function () {
      // Native <details> remains operable with Enter/Space, even without JS.
      positionDetails(slot);
    });
    slot.addEventListener('focusout', function () {
      setTimeout(function () {
        if (!slot.contains(document.activeElement) && !slot.matches(':hover')) slot.open = false;
      }, 0);
    });
  });
  document.addEventListener('click', function (event) {
    if (!event.target.closest('[data-gear-slot]')) closeOthers(null);
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      var open = slots.find(function (slot) { return slot.open; });
      if (open) {
        open.open = false;
        open.querySelector('summary').focus();
      }
    }
  });
  window.addEventListener('resize', function () { slots.forEach(positionDetails); });
  window.addEventListener('scroll', function () { slots.forEach(positionDetails); }, { passive: true });

  // 3D is an optional, on-demand enhancement. Slot icons/details are independent
  // of jQuery, WebGL, the viewer library, the model provider, and this checkbox.
  var toggle = document.getElementById('enable-character-model');
  var modelData = document.getElementById('character-model-data');
  if (!toggle || !modelData) return;
  toggle.disabled = false;
  var container = document.getElementById('character-model');
  var status = document.getElementById('model-status');
  var disclaimer = document.getElementById('model-disclaimer');
  var viewer = null;
  var pending = null;
  var changeId = 0;
  var scripts = {};
  var model = JSON.parse(modelData.textContent);
  var contentPath = new URL('armory-model-asset.php?path=', window.location.href).href;

  function loadScript(url, integrity) {
    if (scripts[url]) return scripts[url];
    scripts[url] = new Promise(function (resolve, reject) {
      var script = document.createElement('script');
      var timer = setTimeout(fail, 12000);
      function fail() {
        clearTimeout(timer);
        script.remove();
        delete scripts[url];
        reject(new Error('3D library unavailable'));
      }
      script.src = url;
      script.referrerPolicy = 'no-referrer';
      if (integrity) { script.integrity = integrity; script.crossOrigin = 'anonymous'; }
      script.onload = function () { clearTimeout(timer); resolve(); };
      script.onerror = fail;
      document.head.appendChild(script);
    });
    return scripts[url];
  }
  function pauseModel(paused) {
    try {
      if (viewer && typeof viewer.method === 'function') viewer.method('setAnimPaused', [paused]);
    } catch (_) { /* Older viewer builds may not expose animation controls. */ }
  }
  async function createViewer() {
    var canvas = document.createElement('canvas');
    var gl = canvas.getContext('webgl2') || canvas.getContext('webgl');
    if (!gl) throw new Error('WebGL unavailable');
    var release = gl.getExtension('WEBGL_lose_context');
    if (release) release.loseContext();
    if (!window.jQuery) {
      await loadScript('https://code.jquery.com/jquery-3.7.1.min.js', 'sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=');
    }
    window.CONTENT_PATH = contentPath;
    window.WOTLK_TO_RETAIL_DISPLAY_ID_API = undefined;
    window.WH = window.WH || {};
    window.WH.debug = window.WH.debug || function () {};
    window.WH.defaultAnimation = 'Stand';
    window.WH.WebP = window.WH.WebP || { getImageExtension: function () { return '.webp'; } };
    window.WH.Wow = window.WH.Wow || {};
    window.WH.Wow.Item = window.WH.Wow.Item || {};
    ('NONE HEAD NECK SHOULDERS SHIRT CHEST WAIST LEGS FEET WRISTS HANDS FINGER TRINKET ONE_HAND SHIELD RANGED BACK TWO_HAND BAG TABARD ROBE MAIN_HAND OFF_HAND HELD_IN_OFF_HAND PROJECTILE THROWN RANGED_RIGHT QUIVER RELIC PROFESSION_TOOL PROFESSION_ACCESSORY').split(' ').forEach(function (name, id) {
      window.WH.Wow.Item['INVENTORY_TYPE_' + name] = id;
    });
    if (!window.ZamModelViewer) await loadScript('https://wow.zamimg.com/modelviewer/classic/viewer/viewer.min.js');
    if (!toggle.checked) return null;

    // Check the model metadata first. A blocked/missing upstream becomes a
    // clear local error, not an eternally blank canvas with a checked box.
    var controller = new AbortController();
    var timer = setTimeout(function () { controller.abort(); }, 14000);
    var modelId = model.race * 2 - 1 + model.gender;
    try {
      var response = await fetch(contentPath + 'meta/character/' + modelId + '.json', { signal: controller.signal });
      if (!response.ok) throw new Error('Model metadata unavailable');
      await response.json();
    } finally {
      clearTimeout(timer);
    }
    if (!toggle.checked) return null;
    var mount = document.createElement('div');
    mount.style.cssText = 'width:100%;height:100%';
    container.appendChild(mount);
    return await new window.ZamModelViewer({
      type: 2,
      container: window.jQuery(mount),
      contentPath: contentPath,
      aspect: container.clientWidth / Math.max(1, container.clientHeight),
      models: { id: modelId, type: 16 },
      items: model.items,
      hd: false,
      dataEnv: 'classic', env: 'classic', gameDataEnv: 'classic'
    });
  }
  function createViewerWithDeadline() {
    return new Promise(function (resolve, reject) {
      var expired = false;
      var timer = setTimeout(function () { expired = true; reject(new Error('3D preview timed out')); }, 30000);
      createViewer().then(function (instance) {
        clearTimeout(timer);
        if (expired) {
          try { if (instance && typeof instance.destroy === 'function') instance.destroy(); } catch (_) { /* Late provider cleanup must not raise an unhandled rejection. */ }
          return;
        }
        resolve(instance);
      }, function (error) { clearTimeout(timer); reject(error); });
    });
  }
  function stopViewer() {
    if (viewer && typeof viewer.destroy === 'function') {
      try { viewer.destroy(); } catch (_) { /* The canvas is removed below too. */ }
      viewer = null;
      container.replaceChildren();
    } else {
      pauseModel(true);
    }
  }
  window.addEventListener('pagehide', stopViewer);
  toggle.addEventListener('change', async function () {
    var request = ++changeId;
    container.hidden = !toggle.checked;
    disclaimer.hidden = !toggle.checked;
    status.classList.remove('is-error');
    if (!toggle.checked) {
      status.textContent = '';
      stopViewer();
      return;
    }
    if (viewer) {
      pauseModel(window.matchMedia('(prefers-reduced-motion: reduce)').matches);
      status.textContent = '';
      return;
    }
    status.textContent = 'Loading 3D preview…';
    try {
      if (!pending) pending = createViewerWithDeadline().finally(function () { pending = null; });
      viewer = await pending;
      if (request !== changeId) { if (!toggle.checked) stopViewer(); return; }
      if (!viewer) return;
      status.textContent = '';
      pauseModel(window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    } catch (_) {
      if (request !== changeId) return;
      toggle.checked = false;
      container.hidden = true;
      container.replaceChildren();
      disclaimer.hidden = true;
      status.classList.add('is-error');
      status.textContent = '3D preview could not be loaded. Your equipment and item details are still available. You can try again using the checkbox.';
    }
  });
})();
