(function () {
  'use strict';

  var cfg = window.MatrixQCSnag;
  if (!cfg) {
    return;
  }

  var STORAGE_KEY = 'matrixQCEnabled';
  var EMAIL_KEY = 'matrixQCEmail';

  function getStoredEmail() {
    try {
      return window.localStorage.getItem(EMAIL_KEY) || '';
    } catch (e) {
      return '';
    }
  }

  function setStoredEmail(email) {
    try {
      window.localStorage.setItem(EMAIL_KEY, email || '');
    } catch (e) {}
  }

  var state = {
    enabled: false,
    loaded: false,
    picking: false,
    selected: null,
    general: false,
    snags: [],
    pinsVisible: true,
  };

  /* ---------- helpers ---------- */

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    attrs = attrs || {};
    Object.keys(attrs).forEach(function (k) {
      if (k === 'class') {
        node.className = attrs[k];
      } else if (k === 'text') {
        node.textContent = attrs[k];
      } else if (k === 'html') {
        node.innerHTML = attrs[k];
      } else {
        node.setAttribute(k, attrs[k]);
      }
    });
    (children || []).forEach(function (c) {
      if (c) {
        node.appendChild(c);
      }
    });
    node.setAttribute('data-qc-ui', '1');
    return node;
  }

  function isOurUi(target) {
    return !!(target.closest && target.closest('[data-qc-ui]'));
  }

  function currentViewport() {
    return window.innerWidth <= cfg.mobileMax ? 'mobile' : 'desktop';
  }

  var TYPE_LABELS = {
    frontend: 'Frontend',
    functionality: 'Functionality',
    backend: 'Backend',
    content: 'Content',
    asset: 'Asset',
    accessibility: 'Accessibility',
    performance: 'Performance',
    seo: 'SEO',
    other: 'Other',
    code: 'Design/code',
  };

  function typeLabel(t) {
    return TYPE_LABELS[t] || (t ? t.charAt(0).toUpperCase() + t.slice(1) : '');
  }

  var STATUS_LABELS = {
    new: 'New',
    triaged: 'Triaged',
    review_required: 'Review required',
    in_progress: 'In progress',
    pr_open: 'PR open',
    fixed: 'Fixed',
    reverted: 'Reverted',
    non_issue: 'Non-issue',
  };

  function statusLabel(s) {
    return STATUS_LABELS[s] || (s ? s.replace(/_/g, ' ') : '');
  }

  // Auto-generated titles start with "[VIEWPORT] ..."; treat those as "no custom title".
  function customTitle(snag) {
    var t = snag.title || '';
    return t && t.charAt(0) !== '[' ? t : '';
  }

  // Drop the m=dev flag so the link focuses the node for users without a Dev seat.
  function figmaViewUrl(url) {
    if (!url) {
      return url;
    }
    try {
      var u = new URL(url);
      u.searchParams.delete('m');
      return u.toString();
    } catch (e) {
      return url;
    }
  }

  // Detect auto-generated ids like "partners-<uuid>" or "stories-<uniqid>".
  // Returns the stable slug prefix (e.g. "partners") or null.
  function volatileSlug(id) {
    var uuid = /^(.+)-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
    var uniq = /^(.+)-[0-9a-f]{13}$/i;
    var m = id.match(uuid) || id.match(uniq);
    return m ? m[1] : null;
  }

  function cssPath(node) {
    if (!(node instanceof Element)) {
      return '';
    }
    var parts = [];
    while (node && node.nodeType === 1 && parts.length < 6) {
      var selector = node.nodeName.toLowerCase();
      if (node.id) {
        var slug = volatileSlug(node.id);
        if (slug) {
          // Stable across renders: anchor on the block slug, not the UUID.
          parts.unshift(selector + '[id^="' + slug + '-"]');
          break;
        }
        parts.unshift(selector + '#' + CSS.escape(node.id));
        break;
      }
      var sib = node;
      var nth = 1;
      while ((sib = sib.previousElementSibling)) {
        if (sib.nodeName === node.nodeName) {
          nth++;
        }
      }
      selector += ':nth-of-type(' + nth + ')';
      parts.unshift(selector);
      node = node.parentElement;
    }
    return parts.join(' > ');
  }

  // Nearest block/component slug from an ancestor's auto-generated id.
  function componentFor(node) {
    var n = node;
    while (n && n.nodeType === 1) {
      if (n.id) {
        var slug = volatileSlug(n.id);
        if (slug) {
          return slug;
        }
      }
      n = n.parentElement;
    }
    return '';
  }

  function classesFor(node) {
    return (node.getAttribute && node.getAttribute('class')) || '';
  }

  function xPath(node) {
    if (!(node instanceof Element)) {
      return '';
    }
    var parts = [];
    while (node && node.nodeType === 1) {
      var index = 1;
      var sib = node.previousSibling;
      while (sib) {
        if (sib.nodeType === 1 && sib.nodeName === node.nodeName) {
          index++;
        }
        sib = sib.previousSibling;
      }
      parts.unshift(node.nodeName.toLowerCase() + '[' + index + ']');
      node = node.parentElement;
    }
    return '/' + parts.join('/');
  }

  function elementText(node) {
    var text = (node.innerText || node.textContent || '').replace(/\s+/g, ' ').trim();
    return text.slice(0, 300);
  }

  function capturedStyles(node) {
    var cs = window.getComputedStyle(node);
    var keep = [
      'color', 'backgroundColor', 'fontSize', 'fontFamily', 'fontWeight',
      'lineHeight', 'letterSpacing', 'textAlign', 'display',
      'marginTop', 'marginRight', 'marginBottom', 'marginLeft',
      'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
      'borderRadius',
    ];
    var out = {};
    keep.forEach(function (k) {
      out[k] = cs[k];
    });
    return out;
  }

  function api(path, method, body) {
    return fetch(cfg.restUrl + path, {
      method: method,
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': cfg.nonce,
      },
      credentials: 'same-origin',
      body: body ? JSON.stringify(body) : undefined,
    }).then(function (r) {
      return r.json();
    });
  }

  function resolveNode(snag) {
    if (snag.selector) {
      try {
        var found = document.querySelector(snag.selector);
        if (found) {
          return found;
        }
      } catch (e) {}
    }
    return null;
  }

  function anchorFor(snag) {
    var node = resolveNode(snag);
    if (node) {
      var r = node.getBoundingClientRect();
      return {
        x: Math.round(r.left + window.scrollX),
        y: Math.round(r.top + window.scrollY),
        node: node,
      };
    }
    var bbox = parseBbox(snag.bbox);
    if (bbox) {
      return { x: bbox.x, y: bbox.y, node: null };
    }
    return null;
  }

  /* ---------- flash highlight ---------- */

  var flashBox = el('div', { class: 'qc-flash' });

  function flashElement(node) {
    if (!node) {
      return;
    }
    var r = node.getBoundingClientRect();
    flashBox.style.display = 'block';
    flashBox.style.top = r.top + window.scrollY + 'px';
    flashBox.style.left = r.left + window.scrollX + 'px';
    flashBox.style.width = r.width + 'px';
    flashBox.style.height = r.height + 'px';
    flashBox.classList.remove('is-on');
    void flashBox.offsetWidth;
    flashBox.classList.add('is-on');
    window.setTimeout(function () {
      flashBox.style.display = 'none';
    }, 1600);
  }

  function locateSnag(snag) {
    var node = resolveNode(snag);
    if (node) {
      node.scrollIntoView({ behavior: 'smooth', block: 'center' });
      window.setTimeout(function () {
        flashElement(node);
      }, 300);
    }
  }

  /* ---------- screenshot ---------- */

  function captureScreenshot(node) {
    if (typeof window.html2canvas !== 'function' || !node) {
      return Promise.resolve('');
    }
    return window
      .html2canvas(node, { logging: false, useCORS: true, scale: 1 })
      .then(function (canvas) {
        var max = 1000;
        if (canvas.width > max) {
          var scaled = document.createElement('canvas');
          var ratio = max / canvas.width;
          scaled.width = max;
          scaled.height = Math.round(canvas.height * ratio);
          scaled.getContext('2d').drawImage(canvas, 0, 0, scaled.width, scaled.height);
          return scaled.toDataURL('image/jpeg', 0.8);
        }
        return canvas.toDataURL('image/jpeg', 0.8);
      })
      .catch(function () {
        return '';
      });
  }

  /* ---------- element picking ---------- */

  var hoverBox = el('div', { class: 'qc-hoverbox' });
  var hoverLabel = el('div', { class: 'qc-hoverlabel' });

  function onMouseMove(e) {
    if (!state.picking) {
      return;
    }
    var target = e.target;
    if (isOurUi(target)) {
      hoverBox.style.display = 'none';
      hoverLabel.style.display = 'none';
      return;
    }
    var r = target.getBoundingClientRect();
    hoverBox.style.display = 'block';
    hoverBox.style.top = r.top + window.scrollY + 'px';
    hoverBox.style.left = r.left + window.scrollX + 'px';
    hoverBox.style.width = r.width + 'px';
    hoverBox.style.height = r.height + 'px';

    hoverLabel.style.display = 'block';
    hoverLabel.textContent =
      target.nodeName.toLowerCase() +
      (target.id ? '#' + target.id : '') +
      ' \u00b7 ' + Math.round(r.width) + '\u00d7' + Math.round(r.height);
    hoverLabel.style.top = Math.max(0, r.top + window.scrollY - 22) + 'px';
    hoverLabel.style.left = r.left + window.scrollX + 'px';
  }

  function onClickCapture(e) {
    if (!state.picking || isOurUi(e.target)) {
      return;
    }
    e.preventDefault();
    e.stopPropagation();
    var node = e.target;
    var rect = node.getBoundingClientRect();
    state.selected = {
      node: node,
      selector: cssPath(node),
      xpath: xPath(node),
      component: componentFor(node),
      classes: classesFor(node),
      element_text: elementText(node),
      styles: capturedStyles(node),
      bbox: {
        x: Math.round(rect.left + window.scrollX),
        y: Math.round(rect.top + window.scrollY),
        w: Math.round(rect.width),
        h: Math.round(rect.height),
      },
    };
    setPicking(false);
    state.general = false;
    openForm();
  }

  function onKeyDown(e) {
    if (e.key === 'Escape') {
      if (state.picking) {
        setPicking(false);
      }
      closePopover();
    }
  }

  function setPicking(on) {
    state.picking = on;
    document.body.classList.toggle('qc-picking', on);
    hoverBox.style.display = 'none';
    hoverLabel.style.display = 'none';
    pickBtn.textContent = on ? 'Cancel pick (Esc)' : '+ Add snag';
    pickBtn.classList.toggle('qc-btn--active', on);
  }

  /* ---------- form panel (create) ---------- */

  var panel = el('div', { class: 'qc-panel', 'aria-hidden': 'true' });

  function openForm() {
    var sel = state.selected;
    var viewport = currentViewport();
    var figma = viewport === 'mobile' ? cfg.figmaMobile : cfg.figmaDesktop;

    var general = state.general;

    panel.innerHTML = '';
    panel.appendChild(el('div', { class: 'qc-panel__head' }, [
      el('strong', { text: general ? 'New general snag' : 'New snag' }),
      el('button', { class: 'qc-x', text: '\u00d7', type: 'button' }),
    ]));

    var titleInput = el('input', { class: 'qc-input', type: 'text', placeholder: 'Optional short title' });
    var desc = el('textarea', {
      class: 'qc-input',
      rows: '4',
      placeholder: general
        ? 'Describe the issue (page-wide layout, missing content, a general request)...'
        : 'Describe the snag (what is wrong vs the design)...',
    });
    var type = el('select', { class: 'qc-input' });
    cfg.enums.type.forEach(function (t) {
      type.appendChild(el('option', { value: t, text: typeLabel(t) }));
    });
    var sev = el('select', { class: 'qc-input' });
    cfg.enums.severity.forEach(function (s) {
      sev.appendChild(el('option', { value: s, text: s }));
    });
    sev.value = 'medium';

    var figmaInput = el('input', {
      class: 'qc-input',
      type: 'url',
      placeholder: 'https://figma.com/design/...?node-id=...',
    });

    panel.appendChild(el('div', { class: 'qc-panel__body' }, [
      el('label', { class: 'qc-label', text: 'Title (optional)' }),
      titleInput,
      el('label', { class: 'qc-label', text: 'Description' }),
      desc,
      el('label', { class: 'qc-label', text: 'Category' }),
      type,
      el('label', { class: 'qc-label', text: 'Severity' }),
      sev,
      el('label', { class: 'qc-label', text: 'Figma element link' }),
      figmaInput,
      el('p', { class: 'qc-meta', text: 'Paste the exact Figma node for this element (right-click element in Figma > Copy link to selection).' }),
      figma
        ? el('button', { class: 'qc-linkbtn', type: 'button', text: 'Use page Figma reference' })
        : null,
      el('p', { class: 'qc-meta', html: 'Viewport: <b>' + viewport + '</b> (' + window.innerWidth + 'px)' }),
      general
        ? el('p', { class: 'qc-meta', text: 'Scope: page-level \u2014 not tied to a specific element. Shows in the List (no pin).' })
        : null,
      !general && sel && sel.component ? el('p', { class: 'qc-meta', html: 'Block: <b>' + sel.component + '</b>' }) : null,
      !general ? el('p', { class: 'qc-meta', text: 'Element: ' + (sel ? sel.selector : 'n/a') }) : null,
      !general && sel && sel.classes ? el('p', { class: 'qc-meta', text: 'Classes: ' + sel.classes.slice(0, 120) }) : null,
      !general && sel && sel.element_text ? el('p', { class: 'qc-meta', text: 'Text: \u201c' + sel.element_text.slice(0, 80) + '\u201d' }) : null,
    ]));

    var usePageBtn = panel.querySelector('.qc-linkbtn');
    if (usePageBtn) {
      usePageBtn.addEventListener('click', function () {
        figmaInput.value = figma;
      });
    }

    var save = el('button', { class: 'qc-btn qc-btn--primary', text: 'Save snag', type: 'button' });
    var status = el('span', { class: 'qc-status-msg' });
    panel.appendChild(el('div', { class: 'qc-panel__foot' }, [save, status]));

    panel.setAttribute('aria-hidden', 'false');
    panel.classList.add('is-open');

    panel.querySelector('.qc-x').addEventListener('click', closeForm);
    save.addEventListener('click', function () {
      if (!desc.value.trim()) {
        status.textContent = 'Add a description first';
        return;
      }
      save.disabled = true;
      status.textContent = 'Capturing...';
      submitSnag(sel, desc.value.trim(), type.value, sev.value, viewport, figma, figmaInput.value.trim(), titleInput.value.trim(), status, save);
    });
  }

  function closeForm() {
    panel.classList.remove('is-open');
    panel.setAttribute('aria-hidden', 'true');
    state.selected = null;
    state.general = false;
  }

  function submitSnag(sel, description, type, severity, viewport, figma, figmaElement, customTitle, status, save) {
    captureScreenshot(sel ? sel.node : null)
      .then(function (dataUrl) {
        if (!dataUrl) {
          return { id: 0 };
        }
        status.textContent = 'Uploading screenshot...';
        return api('/screenshot', 'POST', { image: dataUrl });
      })
      .then(function (shot) {
        status.textContent = 'Saving...';
        return api('/snags', 'POST', {
          title: customTitle || '',
          page_url: cfg.pageUrl,
          page_path: cfg.pagePath,
          page_title: cfg.pageTitle,
          selector: sel ? sel.selector : '',
          xpath: sel ? sel.xpath : '',
          component: sel ? sel.component : '',
          classes: sel ? sel.classes : '',
          element_text: sel ? sel.element_text : '',
          styles: sel ? sel.styles : null,
          bbox: sel ? sel.bbox : null,
          viewport: viewport,
          viewport_width: window.innerWidth,
          screenshot_id: shot && shot.id ? shot.id : 0,
          figma_node: figma || '',
          figma_element: figmaElement || '',
          type: type,
          severity: severity,
          description: description,
        });
      })
      .then(function (res) {
        if (res && res.ok) {
          state.snags.unshift(res.snag);
          renderPins();
          renderList();
          closeForm();
        } else {
          status.textContent = 'Save failed';
          save.disabled = false;
        }
      })
      .catch(function () {
        status.textContent = 'Error saving';
        save.disabled = false;
      });
  }

  /* ---------- pins ---------- */

  var pinLayer = el('div', { class: 'qc-pinlayer' });

  function renderPins() {
    pinLayer.innerHTML = '';
    if (!state.enabled || !state.pinsVisible) {
      return;
    }
    state.snags.forEach(function (snag, i) {
      var anchor = anchorFor(snag);
      if (!anchor) {
        return;
      }
      var status = snag.status || 'new';
      var pin = el('button', {
        class: 'qc-pin qc-pin--status-' + status,
        title: snag.title + ' \u00b7 ' + status,
        type: 'button',
      }, [el('span', { text: String(i + 1) })]);
      pin.style.top = anchor.y + 'px';
      pin.style.left = anchor.x + 'px';
      pin.addEventListener('click', function (e) {
        e.stopPropagation();
        openPopover(snag, i, e.clientX, e.clientY);
      });
      pinLayer.appendChild(pin);
    });
  }

  function parseBbox(raw) {
    if (!raw) {
      return null;
    }
    try {
      var b = typeof raw === 'string' ? JSON.parse(raw) : raw;
      if (b && typeof b.x !== 'undefined') {
        return b;
      }
    } catch (e) {}
    return null;
  }

  /* ---------- popover (view/edit single snag) ---------- */

  var popover = el('div', { class: 'qc-popover', 'aria-hidden': 'true' });

  function closePopover() {
    popover.classList.remove('is-open');
    popover.setAttribute('aria-hidden', 'true');
  }

  function openPopover(snag, index, clientX, clientY) {
    popover.innerHTML = '';
    popover.appendChild(el('div', { class: 'qc-pop__head' }, [
      el('span', { class: 'qc-badge qc-badge--' + (snag.severity || 'medium'), text: String(index + 1) }),
      el('strong', { text: typeLabel(snag.type) + ' \u00b7 ' + snag.viewport }),
      el('button', { class: 'qc-x', text: '\u00d7', type: 'button' }),
    ]));

    var descView = el('p', { class: 'qc-pop__desc', text: snag.description || snag.title });

    var statusSel = el('select', { class: 'qc-input qc-input--sm' });
    cfg.enums.status.forEach(function (s) {
      statusSel.appendChild(el('option', { value: s, text: statusLabel(s) }));
    });
    statusSel.value = snag.status || 'new';
    statusSel.addEventListener('change', function () {
      api('/snags/' + snag.id, 'POST', { status: statusSel.value }).then(function (res) {
        if (res && res.snag) {
          state.snags[index] = res.snag;
          renderPins();
          renderList();
        }
      });
    });

    var body = el('div', { class: 'qc-pop__body' }, [
      customTitle(snag) ? el('p', { class: 'qc-pop__title', text: customTitle(snag) }) : null,
      descView,
      el('label', { class: 'qc-label', text: 'Status' }),
      statusSel,
    ]);
    var figmaLink = snag.figma_element || snag.figma_node;
    if (figmaLink) {
      body.appendChild(el('a', {
        class: 'qc-meta qc-link',
        href: figmaViewUrl(figmaLink),
        target: '_blank',
        text: snag.figma_element ? 'Open Figma element' : 'Open Figma page reference',
      }));
    }
    popover.appendChild(body);

    var commentsEl = el('div', { class: 'qc-comments' });
    popover.appendChild(commentsEl);
    renderComments(snag.id, commentsEl);

    var locate = el('button', { class: 'qc-btn qc-btn--sm', text: 'Locate', type: 'button' });
    var edit = el('button', { class: 'qc-btn qc-btn--sm', text: 'Edit', type: 'button' });
    var del = el('button', { class: 'qc-btn qc-btn--sm qc-btn--danger', text: 'Delete', type: 'button' });
    popover.appendChild(el('div', { class: 'qc-pop__foot' }, [locate, edit, del]));

    locate.addEventListener('click', function () {
      locateSnag(snag);
    });
    edit.addEventListener('click', function () {
      startEdit(snag, index, descView);
    });
    del.addEventListener('click', function () {
      if (!window.confirm('Delete this snag?')) {
        return;
      }
      api('/snags/' + snag.id, 'DELETE').then(function () {
        state.snags.splice(index, 1);
        closePopover();
        renderPins();
        renderList();
      });
    });

    popover.querySelector('.qc-x').addEventListener('click', closePopover);

    var w = 280;
    popover.style.left = Math.min(clientX, window.innerWidth - w - 12) + 'px';
    popover.style.top = Math.min(clientY, window.innerHeight - 220) + 'px';
    popover.classList.add('is-open');
    popover.setAttribute('aria-hidden', 'false');
  }

  function startEdit(snag, index, descView) {
    var titleInput = el('input', { class: 'qc-input qc-input--sm', type: 'text', placeholder: 'Optional title' });
    titleInput.value = customTitle(snag);
    var ta = el('textarea', { class: 'qc-input', rows: '3' });
    ta.value = snag.description || '';
    var sev = el('select', { class: 'qc-input qc-input--sm' });
    cfg.enums.severity.forEach(function (s) {
      sev.appendChild(el('option', { value: s, text: s }));
    });
    sev.value = snag.severity || 'medium';
    var type = el('select', { class: 'qc-input qc-input--sm' });
    cfg.enums.type.forEach(function (t) {
      type.appendChild(el('option', { value: t, text: typeLabel(t) }));
    });
    type.value = snag.type || 'frontend';
    var figmaInput = el('input', {
      class: 'qc-input qc-input--sm',
      type: 'url',
      placeholder: 'Figma element link (node-id=...)',
    });
    figmaInput.value = snag.figma_element || '';
    var prio = el('input', { class: 'qc-input qc-input--sm', type: 'number', min: '0', max: '99', placeholder: 'Priority (1 = highest)' });
    prio.value = snag.priority && parseInt(snag.priority, 10) > 0 ? snag.priority : '';
    var save = el('button', { class: 'qc-btn qc-btn--sm qc-btn--primary', text: 'Save', type: 'button' });

    descView.replaceWith(ta);
    ta.insertAdjacentElement('beforebegin', titleInput);
    ta.insertAdjacentElement('afterend', type);
    type.insertAdjacentElement('afterend', sev);
    sev.insertAdjacentElement('afterend', prio);
    prio.insertAdjacentElement('afterend', figmaInput);
    figmaInput.insertAdjacentElement('afterend', save);

    save.addEventListener('click', function () {
      save.disabled = true;
      api('/snags/' + snag.id, 'POST', {
        title: titleInput.value.trim(),
        description: ta.value.trim(),
        severity: sev.value,
        type: type.value,
        priority: parseInt(prio.value, 10) || 0,
        figma_element: figmaInput.value.trim(),
      }).then(function (res) {
        if (res && res.snag) {
          state.snags[index] = res.snag;
          renderPins();
          renderList();
          closePopover();
        } else {
          save.disabled = false;
        }
      });
    });
  }

  /* ---------- comments ---------- */

  function renderComments(snagId, container) {
    container.innerHTML = '';
    container.appendChild(el('div', { class: 'qc-comments__head', text: 'Comments' }));
    var listEl = el('div', { class: 'qc-comments__list' });
    container.appendChild(listEl);

    var emailIn = el('input', { class: 'qc-input qc-input--sm', type: 'email', placeholder: 'your@email (optional)' });
    emailIn.value = getStoredEmail();
    var ta = el('textarea', { class: 'qc-input qc-input--sm', rows: '2', placeholder: 'Add a comment...' });
    var btn = el('button', { class: 'qc-btn qc-btn--sm qc-btn--primary', text: 'Comment', type: 'button' });
    container.appendChild(emailIn);
    container.appendChild(ta);
    container.appendChild(btn);

    function load() {
      api('/snags/' + snagId + '/comments', 'GET').then(function (res) {
        listEl.innerHTML = '';
        var cs = (res && res.comments) || [];
        if (!cs.length) {
          listEl.appendChild(el('p', { class: 'qc-meta', text: 'No comments yet.' }));
        }
        cs.forEach(function (c) {
          listEl.appendChild(el('div', { class: 'qc-comment' }, [
            el('p', { class: 'qc-comment__meta', text: c.author + (c.email ? ' \u00b7 ' + c.email : '') }),
            el('p', { class: 'qc-comment__body', text: c.content }),
          ]));
        });
      }).catch(function () {});
    }

    btn.addEventListener('click', function () {
      if (!ta.value.trim()) {
        return;
      }
      btn.disabled = true;
      setStoredEmail(emailIn.value.trim());
      api('/snags/' + snagId + '/comments', 'POST', {
        content: ta.value.trim(),
        email: emailIn.value.trim(),
      }).then(function (res) {
        btn.disabled = false;
        if (res && res.ok) {
          ta.value = '';
          load();
        }
      }).catch(function () {
        btn.disabled = false;
      });
    });

    load();
  }

  /* ---------- list panel ---------- */

  var listPanel = el('div', { class: 'qc-list', 'aria-hidden': 'true' });

  function renderList() {
    var open = listPanel.classList.contains('is-open');
    listPanel.innerHTML = '';
    listPanel.appendChild(el('div', { class: 'qc-panel__head' }, [
      el('strong', { text: 'Snags on this page (' + state.snags.length + ')' }),
      el('button', { class: 'qc-x', text: '\u00d7', type: 'button' }),
    ]));
    var body = el('div', { class: 'qc-list__body' });

    var legend = el('div', { class: 'qc-legend' });
    cfg.enums.status.forEach(function (s) {
      legend.appendChild(el('span', { class: 'qc-legend__item' }, [
        el('span', { class: 'qc-legend__dot qc-pin--status-' + s }),
        el('span', { text: statusLabel(s) }),
      ]));
    });
    body.appendChild(legend);

    if (!state.snags.length) {
      body.appendChild(el('p', { class: 'qc-meta', text: 'No snags logged yet.' }));
    }
    state.snags.forEach(function (snag, i) {
      var prioTxt = snag.priority && parseInt(snag.priority, 10) > 0 ? 'P' + snag.priority + ' \u00b7 ' : '';
      var item = el('div', { class: 'qc-list__item' }, [
        el('span', { class: 'qc-badge qc-badge--' + (snag.severity || 'medium'), text: String(i + 1) }),
        el('div', { class: 'qc-list__main' }, [
          el('p', { class: 'qc-list__desc', text: snag.description || snag.title }),
          el('p', { class: 'qc-meta', text: prioTxt + snag.viewport + ' \u00b7 ' + typeLabel(snag.type) + ' \u00b7 ' + statusLabel(snag.status || 'new') }),
        ]),
      ]);
      item.addEventListener('click', function () {
        locateSnag(snag);
      });
      body.appendChild(item);
    });
    listPanel.appendChild(body);
    listPanel.querySelector('.qc-x').addEventListener('click', function () {
      listPanel.classList.remove('is-open');
    });
    if (open) {
      listPanel.classList.add('is-open');
    }
  }

  /* ---------- compare overlay (vs design) ---------- */

  var COMPARE_PREFIX = 'matrixQCCompare:';
  var compareDefaults = {
    src: '', opacity: 50, blend: 'normal',
    x: 0, y: 0, width: 0, scale: 100, visible: true, locked: true,
  };
  var compare = Object.assign({}, compareDefaults);
  var compareTooBig = false;
  var compareDrag = null;

  var compareImg = el('img', { class: 'qc-compare-img', alt: 'Design overlay', draggable: 'false' });
  var compareCard = el('div', { class: 'qc-compare', 'aria-hidden': 'true' });
  var compareFileInput = el('input', { type: 'file', accept: 'image/*', style: 'display:none' });

  function compareKey() {
    return COMPARE_PREFIX + cfg.pagePath + ':' + currentViewport();
  }

  function loadCompareState() {
    var next = Object.assign({}, compareDefaults);
    try {
      var raw = window.localStorage.getItem(compareKey());
      if (raw) {
        next = Object.assign(next, JSON.parse(raw));
      }
    } catch (e) {}
    compare = next;
    compareTooBig = false;
  }

  function saveCompareState() {
    try {
      window.localStorage.setItem(compareKey(), JSON.stringify(compare));
      compareTooBig = false;
    } catch (e) {
      // Large data URLs can blow the localStorage quota: keep the image for this
      // session but persist the rest of the settings without it.
      compareTooBig = true;
      try {
        window.localStorage.setItem(compareKey(), JSON.stringify(Object.assign({}, compare, { src: '' })));
      } catch (e2) {}
    }
  }

  function applyCompare() {
    if (!compare.src) {
      compareImg.style.display = 'none';
      return;
    }
    if (compareImg.getAttribute('src') !== compare.src) {
      compareImg.src = compare.src;
    }
    compareImg.style.display = (state.enabled && compare.visible) ? 'block' : 'none';
    compareImg.style.width = (compare.width || window.innerWidth) + 'px';
    compareImg.style.left = compare.x + 'px';
    compareImg.style.top = compare.y + 'px';
    compareImg.style.transform = 'scale(' + (compare.scale / 100) + ')';
    compareImg.style.opacity = String(Math.max(0, Math.min(100, compare.opacity)) / 100);
    compareImg.style.mixBlendMode = compare.blend === 'difference' ? 'difference' : 'normal';
    compareImg.style.pointerEvents = compare.locked ? 'none' : 'auto';
    compareImg.classList.toggle('qc-compare-img--unlocked', !compare.locked);
  }

  function setCompareSrc(src) {
    compare.src = src || '';
    if (compare.src && !compare.width) {
      compare.width = window.innerWidth;
    }
    if (compare.src) {
      compare.visible = true;
    }
    saveCompareState();
    applyCompare();
    renderCompareCard();
  }

  function readFileAsDataUrl(file) {
    return new Promise(function (resolve, reject) {
      var fr = new FileReader();
      fr.onload = function () { resolve(fr.result); };
      fr.onerror = reject;
      fr.readAsDataURL(file);
    });
  }

  compareFileInput.addEventListener('change', function () {
    var file = compareFileInput.files && compareFileInput.files[0];
    if (file) {
      readFileAsDataUrl(file).then(setCompareSrc);
    }
    compareFileInput.value = '';
  });

  function onCompareDragStart(e) {
    if (compare.locked || !compare.src) {
      return;
    }
    e.preventDefault();
    compareDrag = { sx: e.clientX, sy: e.clientY, ox: compare.x, oy: compare.y };
  }

  function onCompareDragMove(e) {
    if (!compareDrag) {
      return;
    }
    compare.x = compareDrag.ox + (e.clientX - compareDrag.sx);
    compare.y = compareDrag.oy + (e.clientY - compareDrag.sy);
    applyCompare();
  }

  function onCompareDragEnd() {
    if (compareDrag) {
      compareDrag = null;
      saveCompareState();
    }
  }

  function onComparePaste(e) {
    if (!state.enabled || !compareCard.classList.contains('is-open')) {
      return;
    }
    var items = (e.clipboardData && e.clipboardData.items) || [];
    for (var i = 0; i < items.length; i++) {
      if (items[i].type && items[i].type.indexOf('image') === 0) {
        var file = items[i].getAsFile();
        if (file) {
          e.preventDefault();
          readFileAsDataUrl(file).then(setCompareSrc);
        }
        return;
      }
    }
  }

  function onCompareKey(e) {
    if (!compare.src || compare.locked || !compareCard.classList.contains('is-open')) {
      return;
    }
    var n = (e.target && e.target.nodeName) || '';
    if (n === 'INPUT' || n === 'TEXTAREA' || n === 'SELECT') {
      return;
    }
    var step = e.shiftKey ? 10 : 1;
    var moved = true;
    if (e.key === 'ArrowLeft') { compare.x -= step; }
    else if (e.key === 'ArrowRight') { compare.x += step; }
    else if (e.key === 'ArrowUp') { compare.y -= step; }
    else if (e.key === 'ArrowDown') { compare.y += step; }
    else { moved = false; }
    if (moved) {
      e.preventDefault();
      applyCompare();
      saveCompareState();
    }
  }

  function compareRow(children) {
    return el('div', { class: 'qc-compare__row' }, children);
  }

  function renderCompareCard() {
    var open = compareCard.classList.contains('is-open');
    compareCard.innerHTML = '';
    compareCard.appendChild(el('div', { class: 'qc-panel__head' }, [
      el('strong', { text: 'Compare vs design' }),
      el('button', { class: 'qc-x', text: '\u00d7', type: 'button' }),
    ]));

    var figma = currentViewport() === 'mobile' ? cfg.figmaMobile : cfg.figmaDesktop;
    var uploadBtn = el('button', { class: 'qc-btn qc-btn--sm qc-btn--primary', text: compare.src ? 'Replace image' : 'Upload image', type: 'button' });
    var urlInput = el('input', { class: 'qc-input qc-input--sm', type: 'url', placeholder: 'or paste image URL' });
    var urlBtn = el('button', { class: 'qc-btn qc-btn--sm', text: 'Load', type: 'button' });

    var rows = [
      compareRow([uploadBtn]),
      compareRow([urlInput, urlBtn]),
      el('p', { class: 'qc-meta qc-compare__row', text: 'Tip: copy a frame in Figma (or any image) and press Cmd/Ctrl+V here to drop it in.' }),
    ];
    if (figma) {
      rows.push(compareRow([el('a', { class: 'qc-link', href: figmaViewUrl(figma), target: '_blank', text: 'Open this page\u2019s Figma reference \u2197' })]));
    }
    if (compareTooBig) {
      rows.push(el('p', { class: 'qc-meta qc-compare__warn qc-compare__row', text: 'Image shown for this session only \u2014 too large to remember after reload.' }));
    }

    if (compare.src) {
      var opacity = el('input', { class: 'qc-range', type: 'range', min: '0', max: '100', value: String(compare.opacity) });
      var opacityVal = el('span', { class: 'qc-meta', text: compare.opacity + '%' });
      opacity.addEventListener('input', function () {
        compare.opacity = parseInt(opacity.value, 10) || 0;
        opacityVal.textContent = compare.opacity + '%';
        applyCompare();
      });
      opacity.addEventListener('change', saveCompareState);

      var scale = el('input', { class: 'qc-range', type: 'range', min: '25', max: '200', value: String(compare.scale) });
      var scaleVal = el('span', { class: 'qc-meta', text: compare.scale + '%' });
      scale.addEventListener('input', function () {
        compare.scale = parseInt(scale.value, 10) || 100;
        scaleVal.textContent = compare.scale + '%';
        applyCompare();
      });
      scale.addEventListener('change', saveCompareState);

      var diff = el('input', { type: 'checkbox' });
      diff.checked = compare.blend === 'difference';
      diff.addEventListener('change', function () {
        compare.blend = diff.checked ? 'difference' : 'normal';
        applyCompare();
        saveCompareState();
      });
      var diffLabel = el('label', { class: 'qc-compare__check' }, [diff, el('span', { text: ' Difference blend (highlights mismatches)' })]);

      var lockBtn = el('button', { class: 'qc-btn qc-btn--sm', text: compare.locked ? 'Unlock to move' : 'Lock position', type: 'button' });
      lockBtn.addEventListener('click', function () {
        compare.locked = !compare.locked;
        applyCompare();
        saveCompareState();
        renderCompareCard();
      });
      var visBtn = el('button', { class: 'qc-btn qc-btn--sm', text: compare.visible ? 'Hide overlay' : 'Show overlay', type: 'button' });
      visBtn.addEventListener('click', function () {
        compare.visible = !compare.visible;
        applyCompare();
        saveCompareState();
        renderCompareCard();
      });
      var fitBtn = el('button', { class: 'qc-btn qc-btn--sm', text: 'Fit width', type: 'button' });
      fitBtn.addEventListener('click', function () {
        compare.width = window.innerWidth;
        compare.x = 0;
        compare.scale = 100;
        applyCompare();
        saveCompareState();
        renderCompareCard();
      });
      var resetBtn = el('button', { class: 'qc-btn qc-btn--sm', text: 'Reset position', type: 'button' });
      resetBtn.addEventListener('click', function () {
        compare.x = 0;
        compare.y = 0;
        compare.scale = 100;
        applyCompare();
        saveCompareState();
        renderCompareCard();
      });
      var clearBtn = el('button', { class: 'qc-btn qc-btn--sm qc-btn--danger', text: 'Remove image', type: 'button' });
      clearBtn.addEventListener('click', function () {
        setCompareSrc('');
      });

      rows.push(el('label', { class: 'qc-label', text: 'Opacity' }));
      rows.push(compareRow([opacity, opacityVal]));
      rows.push(el('label', { class: 'qc-label', text: 'Scale' }));
      rows.push(compareRow([scale, scaleVal]));
      rows.push(compareRow([diffLabel]));
      rows.push(compareRow([lockBtn, visBtn]));
      rows.push(compareRow([fitBtn, resetBtn]));
      rows.push(compareRow([clearBtn]));
      rows.push(el('p', { class: 'qc-meta qc-compare__row', text: compare.locked ? 'Locked: clicks pass through to the page.' : 'Unlocked: drag the image, or nudge with arrow keys (Shift = 10px).' }));
    }

    compareCard.appendChild(el('div', { class: 'qc-compare__body' }, rows));

    uploadBtn.addEventListener('click', function () { compareFileInput.click(); });
    urlBtn.addEventListener('click', function () {
      var v = urlInput.value.trim();
      if (v) { setCompareSrc(v); }
    });
    urlInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        var v = urlInput.value.trim();
        if (v) { setCompareSrc(v); }
      }
    });
    compareCard.querySelector('.qc-x').addEventListener('click', closeCompare);

    if (open) {
      compareCard.classList.add('is-open');
    }
  }

  function openCompare() {
    loadCompareState();
    applyCompare();
    renderCompareCard();
    compareCard.classList.add('is-open');
    compareCard.setAttribute('aria-hidden', 'false');
    compareBtn.classList.add('qc-btn--active');
  }

  function closeCompare() {
    compareCard.classList.remove('is-open');
    compareCard.setAttribute('aria-hidden', 'true');
    compareBtn.classList.remove('qc-btn--active');
  }

  /* ---------- toolbar ---------- */

  var pickBtn = el('button', { class: 'qc-btn qc-btn--primary', text: '+ Add snag', type: 'button' });
  var generalBtn = el('button', { class: 'qc-btn', text: '+ General', title: 'Log a page-level snag without picking an element', type: 'button' });
  var listBtn = el('button', { class: 'qc-btn', text: 'List', type: 'button' });
  var pinsBtn = el('button', { class: 'qc-btn', text: 'Hide pins', type: 'button' });
  var compareBtn = el('button', { class: 'qc-btn', text: 'Compare', title: 'Overlay a design image to compare against the page', type: 'button' });

  var toolbar = el('div', { class: 'qc-toolbar' }, [
    el('span', { class: 'qc-brand', text: 'QC' }),
    pickBtn,
    generalBtn,
    listBtn,
    pinsBtn,
    compareBtn,
  ]);

  compareBtn.addEventListener('click', function () {
    if (compareCard.classList.contains('is-open')) {
      closeCompare();
    } else {
      openCompare();
    }
  });

  pickBtn.addEventListener('click', function () {
    setPicking(!state.picking);
  });
  generalBtn.addEventListener('click', function () {
    setPicking(false);
    state.selected = null;
    state.general = true;
    openForm();
  });
  listBtn.addEventListener('click', function () {
    renderList();
    listPanel.classList.toggle('is-open');
  });
  pinsBtn.addEventListener('click', function () {
    state.pinsVisible = !state.pinsVisible;
    pinsBtn.textContent = state.pinsVisible ? 'Hide pins' : 'Show pins';
    renderPins();
  });

  /* ---------- enable / disable ---------- */

  function loadSnags() {
    return api('/snags?path=' + encodeURIComponent(cfg.pagePath), 'GET')
      .then(function (res) {
        state.snags = (res && res.snags) || [];
        state.loaded = true;
        renderPins();
        renderList();
      })
      .catch(function () {});
  }

  function updateToggleLabel() {
    var label = document.querySelector('[data-qc-toggle-label]');
    if (label) {
      label.textContent = 'QC Mode: ' + (state.enabled ? 'On' : 'Off');
    }
    var item = cfg.toggleId ? document.getElementById('wp-admin-bar-' + cfg.toggleId) : null;
    if (item) {
      item.classList.toggle('qc-mode-on', state.enabled);
    }
  }

  function setEnabled(on, persist) {
    state.enabled = !!on;
    document.body.classList.toggle('qc-on', state.enabled);
    updateToggleLabel();

    if (persist) {
      try {
        window.localStorage.setItem(STORAGE_KEY, state.enabled ? '1' : '0');
      } catch (e) {}
    }

    if (state.enabled) {
      if (!state.loaded) {
        loadSnags();
      } else {
        renderPins();
      }
      applyCompare();
    } else {
      setPicking(false);
      closeForm();
      closePopover();
      listPanel.classList.remove('is-open');
      closeCompare();
      renderPins();
      applyCompare();
    }
  }

  /* ---------- boot ---------- */

  function boot() {
    document.body.appendChild(hoverBox);
    document.body.appendChild(hoverLabel);
    document.body.appendChild(flashBox);
    document.body.appendChild(pinLayer);
    document.body.appendChild(compareImg);
    document.body.appendChild(compareFileInput);
    document.body.appendChild(panel);
    document.body.appendChild(listPanel);
    document.body.appendChild(popover);
    document.body.appendChild(compareCard);
    document.body.appendChild(toolbar);

    loadCompareState();
    applyCompare();
    compareImg.addEventListener('mousedown', onCompareDragStart);
    document.addEventListener('mousemove', onCompareDragMove);
    document.addEventListener('mouseup', onCompareDragEnd);
    document.addEventListener('paste', onComparePaste);
    document.addEventListener('keydown', onCompareKey);

    document.addEventListener('mousemove', onMouseMove);
    document.addEventListener('click', onClickCapture, true);
    document.addEventListener('click', function (e) {
      if (popover.classList.contains('is-open') &&
          e.target.closest &&
          !e.target.closest('.qc-popover') &&
          !e.target.closest('.qc-pin')) {
        closePopover();
      }
    });
    document.addEventListener('keydown', onKeyDown);
    window.addEventListener('resize', function () {
      renderPins();
      applyCompare();
    });
    window.addEventListener('scroll', function () {
      if (state.enabled && state.pinsVisible) {
        renderPins();
      }
    }, { passive: true });

    var toggleItem = cfg.toggleId ? document.getElementById('wp-admin-bar-' + cfg.toggleId) : null;
    if (toggleItem) {
      toggleItem.addEventListener('click', function (e) {
        e.preventDefault();
        setEnabled(!state.enabled, true);
      });
      var stored = null;
      try {
        stored = window.localStorage.getItem(STORAGE_KEY);
      } catch (e2) {}
      setEnabled(stored === '1', false);
    } else {
      setEnabled(true, false);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
