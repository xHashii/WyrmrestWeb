(function () {
  const MAX_POINTS = 71;
  const GRID_WIDTH = 204;
  const GRID_HEIGHT = 550;
  const CELL_WIDTH = 51;
  const CELL_HEIGHT = 50;
  const ICON_SIZE = 36;
  const GLYPH_UNLOCK_LEVELS = [15, 30, 50];

  const root = document.getElementById('talent-calculator');
  if (!root) return;

  const dataUrl = root.getAttribute('data-data-url') || 'data/talent-calculator.json';
  const initialClass = (root.getAttribute('data-initial-class') || '').toLowerCase();

  const els = {
    classTitle: document.getElementById('talent-current-class'),
    totalPoints: document.getElementById('talent-total-points'),
    level: document.getElementById('talent-level'),
    glyphSummary: document.getElementById('talent-glyph-summary'),
    remaining: document.getElementById('talent-remaining-points'),
    toolbar: document.getElementById('talent-toolbar'),
    progressFill: document.getElementById('talent-progress-fill'),
    classPicker: document.getElementById('talent-class-picker'),
    status: document.getElementById('talent-status'),
    trees: document.getElementById('talent-tree-grid-wrap'),
    majorGlyphs: document.getElementById('talent-major-glyphs'),
    minorGlyphs: document.getElementById('talent-minor-glyphs'),
    refundMode: document.getElementById('talent-refund-mode'),
    resetBuild: document.getElementById('talent-reset-build'),
    clearGlyphs: document.getElementById('talent-clear-glyphs'),
    tooltip: document.getElementById('talent-tooltip'),
    pickerOverlay: document.getElementById('glyph-picker-overlay'),
    pickerTitle: document.getElementById('glyph-picker-title'),
    pickerSubtitle: document.getElementById('glyph-picker-subtitle'),
    pickerClose: document.getElementById('glyph-picker-close'),
    pickerClear: document.getElementById('glyph-picker-clear'),
    pickerList: document.getElementById('glyph-picker-list')
  };

  const state = {
    classes: [],
    classesById: new Map(),
    builds: new Map(),
    glyphSelections: new Map(),
    currentClassId: null,
    refundMode: false,
    dom: {
      treePanels: [],
      treePoints: [],
      treeSubs: [],
      treeResetButtons: [],
      buttons: [],
      arrowPaths: [],
      glyphSlots: { major: [], minor: [] }
    },
    toast: {
      timer: null
    },
    picker: {
      open: false,
      type: 'major',
      slotIndex: 0
    },
    tooltip: {
      visible: false,
      kind: null,
      payload: null,
      x: 0,
      y: 0,
      anchor: null
    }
  };

  init();

  async function init() {
    els.refundMode.addEventListener('click', toggleRefundMode);
    els.resetBuild.addEventListener('click', function () {
      armConfirm(els.resetBuild, 'Confirm reset?', function () {
        disarmConfirm(els.resetBuild);
        resetBuild();
      });
    });
    els.clearGlyphs.addEventListener('click', clearGlyphs);
    els.pickerClose.addEventListener('click', closeGlyphPicker);
    els.pickerClear.addEventListener('click', clearPickerSlot);

    els.pickerOverlay.addEventListener('click', function (event) {
      if (event.target === els.pickerOverlay) {
        closeGlyphPicker();
      }
    });

    els.status.addEventListener('mouseenter', pauseToast);
    els.status.addEventListener('mouseleave', resumeToast);

    document.addEventListener('keydown', handleGlobalKeydown);
    window.addEventListener('scroll', function () {
      hideTooltip();
      updateStuckState();
    }, { passive: true });
    window.addEventListener('resize', function () {
      hideTooltip();
      updateStickyOffset();
      updateStuckState();
    });
    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(updateStickyOffset);
    }
    updateStickyOffset();

    try {
      const response = await fetch(dataUrl, { credentials: 'same-origin' });
      if (!response.ok) {
        throw new Error('HTTP ' + response.status);
      }

      const payload = await response.json();
      prepareData(payload);
      renderClassPicker();

      const firstClass = state.classes[0] ? state.classes[0].id : null;
      const selected = state.classesById.has(initialClass) ? initialClass : firstClass;
      if (!selected) {
        throw new Error('No talent data was found.');
      }

      selectClass(selected, false);
      hideToast();
    } catch (error) {
      console.error(error);
      els.classPicker.innerHTML = '';
      els.trees.innerHTML = '<div class="panel talent-loading">The talent calculator could not be loaded.</div>';
      els.majorGlyphs.innerHTML = '';
      els.minorGlyphs.innerHTML = '';
      els.classTitle.textContent = 'Unavailable';
      setStatus('The talent and glyph data could not be loaded. Please check that data/talent-calculator.json is present and readable.', 'error');
    }
  }

  function handleGlobalKeydown(event) {
    if (event.key !== 'Escape') {
      return;
    }

    if (state.picker.open) {
      closeGlyphPicker();
      return;
    }

    hideTooltip();
  }

  function prepareData(payload) {
    const classOrder = [1, 2, 3, 4, 5, 6, 7, 8, 9, 11];
    const classes = Array.isArray(payload && payload.classes) ? payload.classes.slice() : [];

    classes.sort(function (a, b) {
      return classOrder.indexOf(a.classId) - classOrder.indexOf(b.classId);
    });

    classes.forEach(function (cls) {
      cls.glyphs = normalizeGlyphData(cls.glyphs || {});

      cls.trees.forEach(function (tree) {
        const indexByName = new Map();
        tree.talents.forEach(function (talent, talentIndex) {
          talent._index = talentIndex;
          indexByName.set(talent.name, talentIndex);
        });
        tree.talents.forEach(function (talent) {
          talent._prereqIndex = talent.attached && talent.attached !== 'none'
            ? (indexByName.has(talent.attached) ? indexByName.get(talent.attached) : null)
            : null;
        });
      });

      state.classesById.set(cls.id, cls);
      state.builds.set(cls.id, cls.trees.map(function (tree) {
        return tree.talents.map(function () { return 0; });
      }));
      state.glyphSelections.set(cls.id, {
        major: [null, null, null],
        minor: [null, null, null]
      });
    });

    state.classes = classes;
  }

  function normalizeGlyphData(glyphs) {
    return {
      major: normalizeGlyphArray(glyphs.major),
      minor: normalizeGlyphArray(glyphs.minor)
    };
  }

  function normalizeGlyphArray(items) {
    return (Array.isArray(items) ? items : []).map(function (glyph, glyphIndex) {
      return {
        index: glyphIndex,
        name: glyph.name || 'Unknown Glyph',
        displayName: glyphDisplayName(glyph.name || 'Unknown Glyph'),
        icon: glyph.icon || '',
        iconUrl: glyph.icon ? wowIconUrl(glyph.icon, 'large') : '',
        fallbackUrl: glyph.icon ? wowIconUrl(glyph.icon, 'medium') : '',
        description: glyph.description || 'No description available.'
      };
    });
  }

  function renderClassPicker() {
    els.classPicker.innerHTML = '';

    state.classes.forEach(function (cls) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'talent-class-btn' + (cls.id === state.currentClassId ? ' is-active' : '');
      button.setAttribute('aria-pressed', cls.id === state.currentClassId ? 'true' : 'false');

      const img = document.createElement('img');
      img.src = cls.classIconUrl;
      img.alt = '';
      img.loading = 'lazy';
      img.onerror = function () {
        img.onerror = null;
        img.src = 'images/class/' + cls.classId + '.gif';
        img.style.imageRendering = 'pixelated';
      };

      const label = document.createElement('span');
      label.textContent = cls.name;

      button.appendChild(img);
      button.appendChild(label);
      button.addEventListener('click', function () {
        selectClass(cls.id, true);
      });

      els.classPicker.appendChild(button);
    });
  }

  function selectClass(classId, updateUrl) {
    if (!state.classesById.has(classId)) {
      return;
    }

    state.currentClassId = classId;
    closeGlyphPicker();
    hideTooltip();
    renderClassPicker();
    renderCurrentClass();

    if (updateUrl) {
      const url = new URL(window.location.href);
      url.searchParams.set('class', classId);
      history.replaceState(null, '', url.toString());
    }
  }

  function renderCurrentClass() {
    const cls = currentClass();
    if (!cls) {
      return;
    }

    els.classTitle.textContent = cls.name;
    els.trees.innerHTML = '';
    els.majorGlyphs.innerHTML = '';
    els.minorGlyphs.innerHTML = '';

    state.dom = {
      treePanels: [],
      treePoints: [],
      treeSubs: [],
      treeResetButtons: [],
      buttons: [],
      arrowPaths: [],
      glyphSlots: { major: [], minor: [] }
    };

    cls.trees.forEach(function (tree, treeIndex) {
      renderTree(tree, treeIndex);
    });

    renderGlyphSlots();
    refresh();
  }

  function renderTree(tree, treeIndex) {
    const panel = document.createElement('article');
    panel.className = 'panel talent-tree-panel';

    const head = document.createElement('div');
    head.className = 'talent-tree-head';

    const headLeft = document.createElement('div');
    const treeName = document.createElement('div');
    treeName.className = 'talent-tree-name';
    treeName.textContent = tree.name;

    const flag = document.createElement('span');
    flag.className = 'talent-tree-flag';
    flag.textContent = 'Main spec';

    const treeSubtitle = document.createElement('div');
    treeSubtitle.className = 'talent-tree-sub';

    headLeft.appendChild(treeName);
    headLeft.appendChild(flag);
    headLeft.appendChild(treeSubtitle);

    const side = document.createElement('div');
    side.className = 'talent-tree-side';

    const points = document.createElement('div');
    points.className = 'talent-tree-points';
    points.innerHTML = '0<span>pts</span>';

    const reset = document.createElement('button');
    reset.type = 'button';
    reset.className = 'talent-action-btn';
    reset.textContent = 'Reset Tree';
    reset.addEventListener('click', function () {
      armConfirm(reset, 'Confirm?', function () {
        disarmConfirm(reset);
        resetTree(treeIndex);
      });
    });

    side.appendChild(points);
    side.appendChild(reset);

    head.appendChild(headLeft);
    head.appendChild(side);
    panel.appendChild(head);

    const stage = document.createElement('div');
    stage.className = 'talent-tree-stage';

    const tierLabels = document.createElement('div');
    tierLabels.className = 'talent-tier-labels';
    for (let row = 0; row < 11; row += 1) {
      const label = document.createElement('span');
      label.textContent = String(row * 5);
      tierLabels.appendChild(label);
    }

    const grid = document.createElement('div');
    grid.className = 'talent-tree-grid';
    grid.style.backgroundImage = 'url("' + tree.backgroundPath + '")';

    const arrowLayer = createArrowLayer(tree, treeIndex);
    grid.appendChild(arrowLayer.svg);

    const buttons = [];
    tree.talents.forEach(function (talent, talentIndex) {
      const cell = document.createElement('div');
      cell.className = 'talent-cell';
      cell.style.gridColumn = String(talent.col + 1);
      cell.style.gridRow = String(talent.row + 1);

      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'talent-talent is-locked';

      const sprite = document.createElement('span');
      sprite.className = 'talent-sprite';
      sprite.style.backgroundImage = 'url("' + tree.spritePath + '")';
      sprite.style.backgroundPosition = (-talent.icon * ICON_SIZE) + 'px 0px';

      const badge = document.createElement('span');
      badge.className = 'talent-rank-badge';
      badge.textContent = '0/' + talent.maxRank;

      button.appendChild(sprite);
      button.appendChild(badge);

      button.addEventListener('click', function (event) {
        if (state.refundMode || event.shiftKey) {
          removePoint(treeIndex, talentIndex);
        } else {
          spendPoint(treeIndex, talentIndex);
        }
      });
      button.addEventListener('contextmenu', function (event) {
        event.preventDefault();
        removePoint(treeIndex, talentIndex);
      });
      button.addEventListener('keydown', function (event) {
        if (event.key === 'Backspace' || event.key === 'Delete' || event.key === '-') {
          event.preventDefault();
          removePoint(treeIndex, talentIndex);
        }
      });
      button.addEventListener('mouseenter', function (event) {
        openTooltip('talent', { treeIndex: treeIndex, talentIndex: talentIndex }, button, event);
      });
      button.addEventListener('mousemove', moveTooltip);
      button.addEventListener('mouseleave', hideTooltip);
      button.addEventListener('focus', function () {
        openTooltip('talent', { treeIndex: treeIndex, talentIndex: talentIndex }, button, null);
      });
      button.addEventListener('blur', hideTooltip);

      cell.appendChild(button);
      grid.appendChild(cell);
      buttons[talentIndex] = { button: button, sprite: sprite, badge: badge };
    });

    stage.appendChild(tierLabels);
    stage.appendChild(grid);
    panel.appendChild(stage);

    els.trees.appendChild(panel);

    state.dom.treePanels[treeIndex] = panel;
    state.dom.treePoints[treeIndex] = points;
    state.dom.treeSubs[treeIndex] = treeSubtitle;
    state.dom.treeResetButtons[treeIndex] = reset;
    state.dom.buttons[treeIndex] = buttons;
    state.dom.arrowPaths[treeIndex] = arrowLayer.paths;
  }

  function renderGlyphSlots() {
    ['major', 'minor'].forEach(function (type) {
      const container = type === 'major' ? els.majorGlyphs : els.minorGlyphs;
      container.innerHTML = '';
      state.dom.glyphSlots[type] = [];

      for (let slotIndex = 0; slotIndex < 3; slotIndex += 1) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'glyph-slot-button';

        const icon = document.createElement('img');
        icon.className = 'glyph-slot-icon';
        icon.alt = '';
        icon.loading = 'lazy';
        icon.hidden = true;

        const fallback = document.createElement('div');
        fallback.className = 'glyph-slot-fallback';
        fallback.textContent = type === 'major' ? 'M' : 'm';

        const main = document.createElement('div');
        main.className = 'glyph-slot-main';

        const title = document.createElement('div');
        title.className = 'glyph-slot-title';

        const subtitle = document.createElement('div');
        subtitle.className = 'glyph-slot-subtitle';

        main.appendChild(title);
        main.appendChild(subtitle);

        const badge = document.createElement('span');
        badge.className = 'glyph-slot-badge';

        button.appendChild(icon);
        button.appendChild(fallback);
        button.appendChild(main);
        button.appendChild(badge);

        button.addEventListener('click', function () {
          openGlyphPicker(type, slotIndex);
        });
        button.addEventListener('mouseenter', function (event) {
          openTooltip('glyph-slot', { type: type, slotIndex: slotIndex }, button, event);
        });
        button.addEventListener('mousemove', moveTooltip);
        button.addEventListener('mouseleave', hideTooltip);
        button.addEventListener('focus', function () {
          openTooltip('glyph-slot', { type: type, slotIndex: slotIndex }, button, null);
        });
        button.addEventListener('blur', hideTooltip);

        container.appendChild(button);
        state.dom.glyphSlots[type][slotIndex] = {
          button: button,
          icon: icon,
          fallback: fallback,
          title: title,
          subtitle: subtitle,
          badge: badge
        };
      }
    });
  }

  function createArrowLayer(tree, treeIndex) {
    const svgNs = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(svgNs, 'svg');
    svg.classList.add('talent-arrow-layer');
    svg.setAttribute('viewBox', '0 0 ' + GRID_WIDTH + ' ' + GRID_HEIGHT);
    svg.setAttribute('aria-hidden', 'true');

    const defs = document.createElementNS(svgNs, 'defs');
    const activeMarkerId = 'talent-arrow-active-' + treeIndex;
    const inactiveMarkerId = 'talent-arrow-inactive-' + treeIndex;
    defs.appendChild(createArrowMarker(svgNs, activeMarkerId, '#d7be7e'));
    defs.appendChild(createArrowMarker(svgNs, inactiveMarkerId, '#63563f'));
    svg.appendChild(defs);

    const indexByName = new Map();
    tree.talents.forEach(function (talent, index) {
      indexByName.set(talent.name, index);
    });

    const paths = [];
    tree.talents.forEach(function (talent, index) {
      if (!talent.attached || talent.attached === 'none') {
        return;
      }

      const parentIndex = indexByName.get(talent.attached);
      if (parentIndex === undefined) {
        return;
      }

      const parent = tree.talents[parentIndex];
      const path = document.createElementNS(svgNs, 'path');
      path.setAttribute('d', buildArrowPath(parent, talent));
      path.setAttribute('fill', 'none');
      path.setAttribute('stroke', '#63563f');
      path.setAttribute('stroke-width', '6');
      path.setAttribute('stroke-linecap', 'round');
      path.setAttribute('stroke-linejoin', 'round');
      path.setAttribute('marker-end', 'url(#' + inactiveMarkerId + ')');
      path.dataset.activeMarker = activeMarkerId;
      path.dataset.inactiveMarker = inactiveMarkerId;
      svg.appendChild(path);

      paths[index] = path;
    });

    return { svg: svg, paths: paths };
  }

  function createArrowMarker(svgNs, id, color) {
    const marker = document.createElementNS(svgNs, 'marker');
    marker.setAttribute('id', id);
    marker.setAttribute('markerWidth', '10');
    marker.setAttribute('markerHeight', '10');
    marker.setAttribute('refX', '7');
    marker.setAttribute('refY', '3');
    marker.setAttribute('orient', 'auto');

    const path = document.createElementNS(svgNs, 'path');
    path.setAttribute('d', 'M 0 0 L 8 3 L 0 6 z');
    path.setAttribute('fill', color);
    marker.appendChild(path);
    return marker;
  }

  function buildArrowPath(parent, talent) {
    const px = parent.col * CELL_WIDTH + CELL_WIDTH / 2;
    const py = parent.row * CELL_HEIGHT + CELL_HEIGHT / 2;
    const cx = talent.col * CELL_WIDTH + CELL_WIDTH / 2;
    const cy = talent.row * CELL_HEIGHT + CELL_HEIGHT / 2;
    const offset = 18;

    if (parent.col === talent.col) {
      return 'M ' + px + ' ' + (py + offset) + ' L ' + cx + ' ' + (cy - offset);
    }

    if (parent.row === talent.row) {
      const startX = parent.col < talent.col ? px + offset : px - offset;
      const endX = parent.col < talent.col ? cx - offset : cx + offset;
      return 'M ' + startX + ' ' + py + ' L ' + endX + ' ' + cy;
    }

    const startX = parent.col < talent.col ? px + offset : px - offset;
    const bendX = cx;
    const endY = cy - offset;
    return 'M ' + startX + ' ' + py + ' L ' + bendX + ' ' + py + ' L ' + bendX + ' ' + endY;
  }

  function refresh() {
    const cls = currentClass();
    if (!cls) {
      return;
    }

    const total = totalPoints();
    const level = currentLevel();
    const primaryTree = primaryTreeIndex();
    const unlockedGlyphCount = unlockedGlyphSlots(level);
    const selectedGlyphCount = selectedGlyphsCount();

    els.totalPoints.textContent = String(total);
    els.level.textContent = String(level);
    els.glyphSummary.textContent = (unlockedGlyphCount * 2) + '/6 glyphs';
    els.remaining.textContent = total >= MAX_POINTS
      ? 'All points spent'
      : (MAX_POINTS - total) + ' left';
    if (els.progressFill) {
      els.progressFill.style.width = Math.min(100, (total / MAX_POINTS) * 100) + '%';
    }
    els.refundMode.textContent = state.refundMode ? 'Refund Mode' : 'Spend Mode';
    els.refundMode.setAttribute('aria-pressed', state.refundMode ? 'true' : 'false');
    els.refundMode.classList.toggle('is-toggled', state.refundMode);
    root.classList.toggle('is-refund-mode', state.refundMode);
    els.resetBuild.disabled = total === 0 && selectedGlyphCount === 0;
    if (els.resetBuild.disabled) {
      disarmConfirm(els.resetBuild);
    }
    els.clearGlyphs.disabled = selectedGlyphCount === 0;

    cls.trees.forEach(function (tree, treeIndex) {
      const treeTotal = treePoints(treeIndex);
      const buttons = state.dom.buttons[treeIndex] || [];
      const pointLabel = state.dom.treePoints[treeIndex];
      const sub = state.dom.treeSubs[treeIndex];
      const panel = state.dom.treePanels[treeIndex];
      const reset = state.dom.treeResetButtons[treeIndex];

      if (pointLabel) {
        pointLabel.innerHTML = String(treeTotal) + '<span>pts</span>';
      }
      if (reset) {
        reset.disabled = treeTotal === 0;
        if (reset.disabled) {
          disarmConfirm(reset);
        }
      }
      if (panel) {
        panel.classList.toggle('is-primary', treeIndex === primaryTree && treeTotal > 0);
      }
      if (sub) {
        sub.textContent = treeFooterText(treeIndex);
      }

      tree.talents.forEach(function (talent, talentIndex) {
        const refs = buttons[talentIndex];
        if (!refs) {
          return;
        }

        const rank = currentBuild()[treeIndex][talentIndex];
        const active = talentIsActive(treeIndex, talentIndex);
        const maxed = rank >= talent.maxRank;

        refs.button.classList.toggle('is-active', active);
        refs.button.classList.toggle('is-locked', !active);
        refs.button.classList.toggle('is-maxed', maxed);
        refs.button.classList.toggle('is-spent', rank > 0);
        refs.badge.textContent = rank + '/' + talent.maxRank;
        refs.sprite.style.backgroundPosition = (-talent.icon * ICON_SIZE) + 'px ' + (active ? '0px' : (-ICON_SIZE) + 'px');
        refs.button.setAttribute('aria-label', talent.name + ', ' + rank + ' of ' + talent.maxRank + ' points');
      });

      (state.dom.arrowPaths[treeIndex] || []).forEach(function (path, talentIndex) {
        if (!path) {
          return;
        }
        const active = talentIsActive(treeIndex, talentIndex);
        path.setAttribute('stroke', active ? '#d7be7e' : '#63563f');
        path.setAttribute('marker-end', 'url(#' + (active ? path.dataset.activeMarker : path.dataset.inactiveMarker) + ')');
      });
    });

    refreshGlyphSlots();
    if (state.picker.open) {
      renderGlyphPicker();
    }
    renderOpenTooltip();
  }

  function refreshGlyphSlots() {
    ['major', 'minor'].forEach(function (type) {
      const selections = currentGlyphs()[type];
      const refsList = state.dom.glyphSlots[type] || [];

      for (let slotIndex = 0; slotIndex < refsList.length; slotIndex += 1) {
        const refs = refsList[slotIndex];
        const glyph = getSelectedGlyph(type, slotIndex);
        const unlocked = glyphSlotUnlocked(slotIndex);
        const unlockLevel = glyphUnlockLevel(slotIndex);
        const slotLabel = glyphSlotLabel(type, slotIndex);

        refs.button.classList.toggle('has-glyph', !!glyph);
        refs.button.classList.toggle('is-locked', !unlocked);
        refs.button.setAttribute('aria-disabled', unlocked ? 'false' : 'true');

        if (glyph) {
          refs.icon.hidden = false;
          refs.icon.src = glyph.iconUrl;
          refs.icon.onerror = function () {
            refs.icon.onerror = null;
            refs.icon.src = glyph.fallbackUrl;
          };
          refs.fallback.hidden = true;
          refs.title.textContent = glyph.displayName;
          refs.subtitle.textContent = unlocked
            ? glyph.description
            : 'Selected — slot unlocks at level ' + unlockLevel + '.';
          refs.badge.textContent = unlocked ? 'Selected' : 'Locked';
          refs.subtitle.title = glyph.description;
          refs.button.setAttribute('aria-label', slotLabel + ': ' + glyph.name);
        } else {
          refs.icon.hidden = true;
          refs.fallback.hidden = false;
          refs.title.textContent = unlocked ? 'Empty ' + slotLabel : slotLabel + ' Locked';
          refs.subtitle.textContent = unlocked
            ? 'Click to choose a ' + type + ' glyph.'
            : 'Unlocks at level ' + unlockLevel + '.';
          refs.subtitle.title = '';
          refs.badge.textContent = unlocked ? 'Empty' : 'Level ' + unlockLevel;
          refs.button.setAttribute('aria-label', unlocked
            ? 'Empty ' + slotLabel + '. Click to choose a glyph.'
            : slotLabel + ' locked until level ' + unlockLevel + '.');
        }
      }
    });
  }

  function openGlyphPicker(type, slotIndex) {
    if (!glyphSlotUnlocked(slotIndex)) {
      setStatus(glyphSlotLabel(type, slotIndex) + ' unlocks at level ' + glyphUnlockLevel(slotIndex) + '.', 'error');
      renderOpenTooltip();
      return;
    }

    state.picker.open = true;
    state.picker.type = type;
    state.picker.slotIndex = slotIndex;
    els.pickerOverlay.hidden = false;
    renderGlyphPicker();
  }

  function closeGlyphPicker() {
    state.picker.open = false;
    els.pickerOverlay.hidden = true;
    hideTooltip();
  }

  function renderGlyphPicker() {
    if (!state.picker.open) {
      return;
    }

    const cls = currentClass();
    const type = state.picker.type;
    const slotIndex = state.picker.slotIndex;
    const selectedIndex = currentGlyphs()[type][slotIndex];
    const glyphs = cls.glyphs[type] || [];

    els.pickerTitle.textContent = 'Select ' + glyphSlotLabel(type, slotIndex);
    els.pickerSubtitle.textContent = cls.name + ' — choose one of the available ' + type + ' glyphs for this slot.';
    els.pickerClear.disabled = selectedIndex === null;
    els.pickerList.innerHTML = '';

    if (!glyphs.length) {
      const empty = document.createElement('div');
      empty.className = 'glyph-empty-state';
      empty.textContent = 'No ' + type + ' glyphs were found for this class.';
      els.pickerList.appendChild(empty);
      return;
    }

    glyphs.forEach(function (glyph, glyphIndex) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'glyph-option';

      const isSelected = selectedIndex === glyphIndex;
      const usedInOtherSlot = glyphUsedElsewhere(type, glyphIndex, slotIndex);
      if (isSelected) {
        button.classList.add('is-selected');
      }
      if (usedInOtherSlot) {
        button.classList.add('is-disabled');
      }

      const img = document.createElement('img');
      img.src = glyph.iconUrl;
      img.alt = '';
      img.loading = 'lazy';
      img.onerror = function () {
        img.onerror = null;
        img.src = glyph.fallbackUrl;
      };

      const body = document.createElement('div');

      const title = document.createElement('div');
      title.className = 'glyph-option-title';
      title.textContent = glyph.name;

      const desc = document.createElement('div');
      desc.className = 'glyph-option-desc';
      desc.textContent = glyph.description;

      body.appendChild(title);
      body.appendChild(desc);

      if (isSelected || usedInOtherSlot) {
        const note = document.createElement('div');
        note.className = 'glyph-option-note';
        note.textContent = isSelected
          ? 'Selected in this slot'
          : 'Already used in ' + glyphSlotLabel(type, findGlyphSlot(type, glyphIndex));
        body.appendChild(note);
      }

      button.appendChild(img);
      button.appendChild(body);

      button.addEventListener('click', function () {
        if (usedInOtherSlot) {
          setStatus(glyph.name + ' is already used in another ' + type + ' slot.', 'error');
          return;
        }
        setGlyph(type, slotIndex, glyphIndex);
      });
      button.addEventListener('mouseenter', function (event) {
        openTooltip('glyph-option', { type: type, slotIndex: slotIndex, glyphIndex: glyphIndex }, button, event);
      });
      button.addEventListener('mousemove', moveTooltip);
      button.addEventListener('mouseleave', hideTooltip);
      button.addEventListener('focus', function () {
        openTooltip('glyph-option', { type: type, slotIndex: slotIndex, glyphIndex: glyphIndex }, button, null);
      });
      button.addEventListener('blur', hideTooltip);

      els.pickerList.appendChild(button);
    });
  }

  function setGlyph(type, slotIndex, glyphIndex) {
    currentGlyphs()[type][slotIndex] = glyphIndex;
    refresh();
    closeGlyphPicker();
    setStatus('Selected ' + currentClass().glyphs[type][glyphIndex].name + ' for ' + glyphSlotLabel(type, slotIndex) + '.', 'success');
  }

  function clearPickerSlot() {
    if (!state.picker.open) {
      return;
    }
    clearGlyphSlot(state.picker.type, state.picker.slotIndex);
    closeGlyphPicker();
  }

  function clearGlyphSlot(type, slotIndex) {
    const glyph = getSelectedGlyph(type, slotIndex);
    if (!glyph) {
      setStatus(glyphSlotLabel(type, slotIndex) + ' is already empty.');
      return;
    }

    currentGlyphs()[type][slotIndex] = null;
    refresh();
    setStatus('Cleared ' + glyphSlotLabel(type, slotIndex) + '.', 'success');
  }

  function clearGlyphs() {
    const glyphs = currentGlyphs();
    const hadGlyphs = selectedGlyphsCount() > 0;
    if (!hadGlyphs) {
      setStatus('No glyphs are selected for this class.');
      return;
    }

    glyphs.major = [null, null, null];
    glyphs.minor = [null, null, null];
    refresh();
    closeGlyphPicker();
    setStatus(currentClass().name + ' glyphs have been cleared.', 'success');
  }

  function spendPoint(treeIndex, talentIndex) {
    const result = canSpendPoint(treeIndex, talentIndex);
    if (!result.ok) {
      setStatus(result.message, 'error');
      renderOpenTooltip();
      return;
    }

    currentBuild()[treeIndex][talentIndex] += 1;
    refresh();
    setStatus('Added a point to ' + currentClass().trees[treeIndex].talents[talentIndex].name + '.', 'success');
  }

  function removePoint(treeIndex, talentIndex) {
    const result = canRemovePoint(treeIndex, talentIndex);
    if (!result.ok) {
      setStatus(result.message, 'error');
      renderOpenTooltip();
      return;
    }

    currentBuild()[treeIndex][talentIndex] -= 1;
    refresh();
    setStatus('Removed a point from ' + currentClass().trees[treeIndex].talents[talentIndex].name + '.', 'success');
  }

  function canSpendPoint(treeIndex, talentIndex) {
    const tree = currentClass().trees[treeIndex];
    const talent = tree.talents[talentIndex];
    const ranks = currentBuild()[treeIndex];
    const rank = ranks[talentIndex];

    if (rank >= talent.maxRank) {
      return { ok: false, message: talent.name + ' is already at max rank.' };
    }
    if (totalPoints() >= MAX_POINTS) {
      return { ok: false, message: 'All 71 talent points have already been spent.' };
    }
    if (treePoints(treeIndex) < talent.row * 5) {
      return { ok: false, message: 'Requires ' + (talent.row * 5) + ' points in ' + tree.name + ' first.' };
    }
    if (talent._prereqIndex !== null) {
      const parent = tree.talents[talent._prereqIndex];
      if (ranks[talent._prereqIndex] < parent.maxRank) {
        return {
          ok: false,
          message: 'Requires ' + parent.maxRank + ' point' + (parent.maxRank === 1 ? '' : 's') + ' in ' + parent.name + '.'
        };
      }
    }

    return { ok: true, message: '' };
  }

  function canRemovePoint(treeIndex, talentIndex) {
    const tree = currentClass().trees[treeIndex];
    const talent = tree.talents[talentIndex];
    const ranks = currentBuild()[treeIndex];

    if (ranks[talentIndex] <= 0) {
      return { ok: false, message: talent.name + ' has no points to remove.' };
    }

    for (let index = 0; index < tree.talents.length; index += 1) {
      const other = tree.talents[index];
      if (other._prereqIndex === talentIndex && ranks[index] > 0) {
        return { ok: false, message: 'Remove points from ' + other.name + ' before lowering ' + talent.name + '.' };
      }
    }

    const simulated = ranks.slice();
    simulated[talentIndex] -= 1;

    for (let index = 0; index < tree.talents.length; index += 1) {
      const other = tree.talents[index];
      if (simulated[index] <= 0 || other.row === 0) {
        continue;
      }
      if (pointsBeforeRow(treeIndex, other.row, simulated) < other.row * 5) {
        return { ok: false, message: 'Remove higher-tier talents first before taking points out of ' + talent.name + '.' };
      }
    }

    return { ok: true, message: '' };
  }

  function resetTree(treeIndex) {
    if (treePoints(treeIndex) === 0) {
      setStatus('That tree is already empty.');
      return;
    }

    currentBuild()[treeIndex] = currentBuild()[treeIndex].map(function () { return 0; });
    refresh();
    setStatus(currentClass().trees[treeIndex].name + ' has been reset.', 'success');
  }

  function resetBuild() {
    const hadTalents = totalPoints() > 0;
    const hadGlyphs = selectedGlyphsCount() > 0;

    if (!hadTalents && !hadGlyphs) {
      setStatus('This build is already empty.');
      return;
    }

    const resetTalents = currentBuild().map(function (tree) {
      return tree.map(function () { return 0; });
    });
    state.builds.set(state.currentClassId, resetTalents);
    state.glyphSelections.set(state.currentClassId, {
      major: [null, null, null],
      minor: [null, null, null]
    });

    refresh();
    closeGlyphPicker();
    setStatus(currentClass().name + ' talents and glyphs have been reset.', 'success');
  }

  function toggleRefundMode() {
    state.refundMode = !state.refundMode;
    refresh();
    setStatus(
      state.refundMode
        ? 'Refund mode enabled. Click a spent talent to remove a point.'
        : 'Spend mode enabled. Click a talent to add a point.',
      'success'
    );
  }

  function openTooltip(kind, payload, anchor, event) {
    state.tooltip.visible = true;
    state.tooltip.kind = kind;
    state.tooltip.payload = payload;
    state.tooltip.anchor = anchor || null;

    if (event && typeof event.clientX === 'number') {
      state.tooltip.x = event.clientX;
      state.tooltip.y = event.clientY;
    } else if (anchor && anchor.getBoundingClientRect) {
      const rect = anchor.getBoundingClientRect();
      state.tooltip.x = rect.left + rect.width / 2;
      state.tooltip.y = rect.top + rect.height / 2;
    }

    renderOpenTooltip();
  }

  function moveTooltip(event) {
    if (!state.tooltip.visible || typeof event.clientX !== 'number') {
      return;
    }

    state.tooltip.x = event.clientX;
    state.tooltip.y = event.clientY;
    positionTooltip();
  }

  function hideTooltip() {
    state.tooltip.visible = false;
    state.tooltip.kind = null;
    state.tooltip.payload = null;
    state.tooltip.anchor = null;
    els.tooltip.hidden = true;
  }

  function renderOpenTooltip() {
    if (!state.tooltip.visible) {
      return;
    }

    let html = '';
    if (state.tooltip.kind === 'talent') {
      html = talentTooltipHtml(state.tooltip.payload.treeIndex, state.tooltip.payload.talentIndex);
    } else if (state.tooltip.kind === 'glyph-slot') {
      html = glyphSlotTooltipHtml(state.tooltip.payload.type, state.tooltip.payload.slotIndex);
    } else if (state.tooltip.kind === 'glyph-option') {
      html = glyphOptionTooltipHtml(state.tooltip.payload.type, state.tooltip.payload.slotIndex, state.tooltip.payload.glyphIndex);
    }

    if (!html) {
      hideTooltip();
      return;
    }

    els.tooltip.innerHTML = html;
    els.tooltip.hidden = false;
    positionTooltip();
  }

  function talentTooltipHtml(treeIndex, talentIndex) {
    const tree = currentClass().trees[treeIndex];
    const talent = tree.talents[talentIndex];
    const ranks = currentBuild()[treeIndex];
    const currentRank = ranks[talentIndex];
    const treeTotal = treePoints(treeIndex);
    const descriptions = Array.isArray(talent.descriptions) ? talent.descriptions : [];
    const currentDescription = currentRank === 0
      ? (descriptions[0] || 'No description available.')
      : (descriptions[Math.min(currentRank - 1, descriptions.length - 1)] || descriptions[descriptions.length - 1] || 'No description available.');
    const nextDescription = currentRank < talent.maxRank && descriptions[currentRank]
      ? descriptions[currentRank]
      : '';

    const meta = [talent.cost, talent.range, talent.castTime, talent.cooldown].filter(Boolean);
    const warnings = [];
    const hints = [];

    if (treeTotal < talent.row * 5) {
      warnings.push('Requires ' + (talent.row * 5) + ' points in ' + tree.name + '.');
    }
    if (talent._prereqIndex !== null) {
      const parent = tree.talents[talent._prereqIndex];
      if (ranks[talent._prereqIndex] < parent.maxRank) {
        warnings.push('Requires ' + parent.maxRank + ' point' + (parent.maxRank === 1 ? '' : 's') + ' in ' + parent.name + '.');
      }
    }
    if (totalPoints() >= MAX_POINTS && currentRank === 0) {
      warnings.push('All 71 talent points are already allocated.');
    }

    if (canSpendPoint(treeIndex, talentIndex).ok && !state.refundMode) {
      hints.push('Click to learn the next rank.');
    }
    if (canRemovePoint(treeIndex, talentIndex).ok) {
      hints.push(state.refundMode
        ? 'Refund Mode: click to remove a point.'
        : 'Right-click or Shift-click to remove a point.');
    }

    let html = '';
    html += '<div class="talent-tooltip-title">' + escapeHtml(talent.name) + '</div>';
    html += '<div class="talent-tooltip-rank">Rank ' + currentRank + '/' + talent.maxRank + '</div>';

    if (meta.length) {
      html += '<div class="talent-tooltip-meta">';
      meta.forEach(function (line) {
        html += '<div>' + escapeHtml(line) + '</div>';
      });
      html += '</div>';
    }

    html += '<div class="talent-tooltip-text">' + escapeHtml(currentDescription) + '</div>';

    if (nextDescription) {
      html += '<div class="talent-tooltip-next">';
      html += '<div class="talent-tooltip-next-label">Next rank</div>';
      html += '<div class="talent-tooltip-text">' + escapeHtml(nextDescription) + '</div>';
      html += '</div>';
    }

    if (warnings.length) {
      html += '<div class="talent-tooltip-warning">' + warnings.map(escapeHtml).join('<br>') + '</div>';
    }

    if (hints.length) {
      html += '<div class="talent-tooltip-hints">';
      hints.forEach(function (hint) {
        html += '<div>' + escapeHtml(hint) + '</div>';
      });
      html += '</div>';
    }

    return html;
  }

  function glyphSlotTooltipHtml(type, slotIndex) {
    const glyph = getSelectedGlyph(type, slotIndex);
    const unlocked = glyphSlotUnlocked(slotIndex);
    const label = glyphSlotLabel(type, slotIndex);
    const unlockLevel = glyphUnlockLevel(slotIndex);

    let html = '';
    if (glyph) {
      html += '<div class="talent-tooltip-title">' + escapeHtml(glyph.name) + '</div>';
      html += '<div class="talent-tooltip-label">' + escapeHtml(label) + '</div>';
      html += '<div class="talent-tooltip-text">' + escapeHtml(glyph.description) + '</div>';
      html += '<div class="talent-tooltip-hints">';
      html += '<div>' + escapeHtml(unlocked ? 'Click to change this glyph.' : 'This selection is kept, but the slot unlocks at level ' + unlockLevel + '.') + '</div>';
      html += '</div>';
      return html;
    }

    html += '<div class="talent-tooltip-title">' + escapeHtml(label) + '</div>';
    html += '<div class="talent-tooltip-label">' + escapeHtml(typeLabel(type) + ' Glyph Slot') + '</div>';
    html += '<div class="talent-tooltip-text">' + escapeHtml(unlocked
      ? 'Choose a glyph for this slot. Each glyph can only be used once per type.'
      : 'This slot unlocks at level ' + unlockLevel + '.') + '</div>';
    return html;
  }

  function glyphOptionTooltipHtml(type, slotIndex, glyphIndex) {
    const glyph = currentClass().glyphs[type][glyphIndex];
    if (!glyph) {
      return '';
    }

    const duplicateSlot = findGlyphSlot(type, glyphIndex);
    const isDuplicate = duplicateSlot !== -1 && duplicateSlot !== slotIndex;
    const isSelectedHere = duplicateSlot === slotIndex;

    let html = '';
    html += '<div class="talent-tooltip-title">' + escapeHtml(glyph.name) + '</div>';
    html += '<div class="talent-tooltip-label">' + escapeHtml(typeLabel(type) + ' Glyph') + '</div>';
    html += '<div class="talent-tooltip-text">' + escapeHtml(glyph.description) + '</div>';

    if (isDuplicate || isSelectedHere) {
      html += '<div class="talent-tooltip-hints">';
      html += '<div>' + escapeHtml(isSelectedHere
        ? 'Currently selected in this slot.'
        : 'Already used in ' + glyphSlotLabel(type, duplicateSlot) + '.') + '</div>';
      html += '</div>';
    }

    return html;
  }

  function positionTooltip() {
    if (els.tooltip.hidden) {
      return;
    }

    const margin = 14;
    const rect = els.tooltip.getBoundingClientRect();
    let left = state.tooltip.x ? state.tooltip.x + 18 : window.innerWidth / 2 - rect.width / 2;
    let top = state.tooltip.y ? state.tooltip.y + 18 : window.innerHeight / 2 - rect.height / 2;

    if (left + rect.width + margin > window.innerWidth) {
      left = Math.max(margin, (state.tooltip.x || left) - rect.width - 18);
    }
    if (top + rect.height + margin > window.innerHeight) {
      top = Math.max(margin, window.innerHeight - rect.height - margin);
    }

    els.tooltip.style.left = Math.max(margin, left) + 'px';
    els.tooltip.style.top = Math.max(margin, top) + 'px';
  }

  function setStatus(message, tone) {
    if (!message) {
      hideToast();
      return;
    }

    els.status.textContent = message;
    els.status.classList.remove('is-error', 'is-success');
    if (tone === 'error') {
      els.status.classList.add('is-error');
    } else if (tone === 'success') {
      els.status.classList.add('is-success');
    }

    els.status.classList.add('is-visible');
    clearToastTimers();
    state.toast.timer = window.setTimeout(hideToast, tone === 'error' ? 4200 : 2000);
  }

  function hideToast() {
    clearToastTimers();
    els.status.classList.remove('is-visible');
  }

  function pauseToast() {
    if (state.toast.timer !== null) {
      window.clearTimeout(state.toast.timer);
      state.toast.timer = null;
    }
  }

  function resumeToast() {
    if (els.status.classList.contains('is-visible') && state.toast.timer === null) {
      state.toast.timer = window.setTimeout(hideToast, 1600);
    }
  }

  function clearToastTimers() {
    if (state.toast.timer !== null) {
      window.clearTimeout(state.toast.timer);
      state.toast.timer = null;
    }
  }

  /* Two-step confirm for destructive resets: the first click arms the button
     and the second click within a few seconds actually resets. */
  const armedButtons = new WeakMap();

  function armConfirm(button, confirmLabel, action) {
    if (armedButtons.has(button)) {
      action();
      return;
    }

    const previousLabel = button.textContent;
    button.textContent = confirmLabel;
    button.classList.add('is-armed');

    const timer = window.setTimeout(function () {
      disarmConfirm(button, previousLabel);
    }, 3000);
    armedButtons.set(button, { label: previousLabel, timer: timer });
  }

  function disarmConfirm(button, restoreLabel) {
    const armed = armedButtons.get(button);
    if (!armed) {
      return;
    }

    window.clearTimeout(armed.timer);
    armedButtons.delete(button);
    if (restoreLabel !== false) {
      button.textContent = armed.label;
    }
    button.classList.remove('is-armed');
  }

  /* The toolbar sticks just below the site navbar; the navbar can wrap to
     multiple rows, so measure its height instead of hardcoding an offset. */
  function updateStickyOffset() {
    const navbar = document.querySelector('.navbar');
    const top = (navbar ? navbar.offsetHeight : 0) + 8;
    root.style.setProperty('--talent-sticky-top', top + 'px');
    updateStuckState();
  }

  function updateStuckState() {
    if (!els.toolbar) {
      return;
    }

    const stickyTop = parseFloat(getComputedStyle(root).getPropertyValue('--talent-sticky-top')) || 0;
    const stuck = els.toolbar.getBoundingClientRect().top <= stickyTop + 1;
    els.toolbar.classList.toggle('is-stuck', stuck);
  }

  function currentClass() {
    return state.classesById.get(state.currentClassId) || null;
  }

  function currentBuild() {
    return state.builds.get(state.currentClassId);
  }

  function currentGlyphs() {
    return state.glyphSelections.get(state.currentClassId);
  }

  function treePoints(treeIndex) {
    return currentBuild()[treeIndex].reduce(function (sum, rank) {
      return sum + rank;
    }, 0);
  }

  function totalPoints() {
    return currentBuild().reduce(function (sum, tree) {
      return sum + tree.reduce(function (treeSum, rank) {
        return treeSum + rank;
      }, 0);
    }, 0);
  }

  function currentLevel() {
    return Math.min(80, 9 + totalPoints());
  }

  function pointsBeforeRow(treeIndex, row, ranks) {
    const tree = currentClass().trees[treeIndex];
    let points = 0;

    tree.talents.forEach(function (talent, index) {
      if (talent.row < row) {
        points += ranks[index];
      }
    });

    return points;
  }

  function talentIsActive(treeIndex, talentIndex) {
    const tree = currentClass().trees[treeIndex];
    const talent = tree.talents[talentIndex];
    const ranks = currentBuild()[treeIndex];
    const rank = ranks[talentIndex];
    const treeTotal = treePoints(treeIndex);
    const rowMet = treeTotal >= talent.row * 5;
    const prereqMet = talent._prereqIndex === null || ranks[talent._prereqIndex] >= tree.talents[talent._prereqIndex].maxRank;
    const cappedOut = totalPoints() >= MAX_POINTS && rank === 0;
    return rowMet && prereqMet && !cappedOut;
  }

  function primaryTreeIndex() {
    let bestIndex = 0;
    let bestPoints = -1;

    currentClass().trees.forEach(function (_tree, treeIndex) {
      const points = treePoints(treeIndex);
      if (points > bestPoints) {
        bestPoints = points;
        bestIndex = treeIndex;
      }
    });

    return bestIndex;
  }

  function treeFooterText(treeIndex) {
    const spent = treePoints(treeIndex);
    if (spent === 0) {
      return 'No points spent yet. The first locked tier opens every 5 points.';
    }

    const currentTier = Math.min(11, Math.floor(spent / 5) + 1);
    const nextRequirement = currentTier >= 11 ? null : currentTier * 5;
    if (nextRequirement === null || nextRequirement > 50) {
      return 'Tier ' + currentTier + ' is unlocked. The final 51-point talent is available once you have 50 points in this tree.';
    }

    const remaining = Math.max(0, nextRequirement - spent);
    return 'Tier ' + currentTier + ' unlocked. ' + remaining + ' more point' + (remaining === 1 ? '' : 's') + ' opens the next tier.';
  }

  function unlockedGlyphSlots(level) {
    let count = 0;
    GLYPH_UNLOCK_LEVELS.forEach(function (unlockLevel) {
      if (level >= unlockLevel) {
        count += 1;
      }
    });
    return count;
  }

  function glyphSlotUnlocked(slotIndex) {
    return slotIndex < unlockedGlyphSlots(currentLevel());
  }

  function glyphUnlockLevel(slotIndex) {
    return GLYPH_UNLOCK_LEVELS[slotIndex] || 80;
  }

  function glyphSlotLabel(type, slotIndex) {
    return typeLabel(type) + ' ' + (slotIndex + 1);
  }

  function typeLabel(type) {
    return type === 'major' ? 'Major Glyph' : 'Minor Glyph';
  }

  function getSelectedGlyph(type, slotIndex) {
    const index = currentGlyphs()[type][slotIndex];
    return index === null ? null : currentClass().glyphs[type][index] || null;
  }

  function selectedGlyphsCount() {
    const glyphs = currentGlyphs();
    return glyphs.major.concat(glyphs.minor).filter(function (value) {
      return value !== null;
    }).length;
  }

  function glyphUsedElsewhere(type, glyphIndex, exceptSlotIndex) {
    return currentGlyphs()[type].some(function (value, index) {
      return index !== exceptSlotIndex && value === glyphIndex;
    });
  }

  function findGlyphSlot(type, glyphIndex) {
    const selections = currentGlyphs()[type];
    for (let index = 0; index < selections.length; index += 1) {
      if (selections[index] === glyphIndex) {
        return index;
      }
    }
    return -1;
  }

  function glyphDisplayName(name) {
    return String(name)
      .replace(/^Glyph of the\s+/i, '')
      .replace(/^Glyph of\s+/i, '');
  }

  function wowIconUrl(iconName, size) {
    return 'https://wow.zamimg.com/images/wow/icons/' + (size || 'large') + '/' + iconName + '.jpg';
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }
})();
