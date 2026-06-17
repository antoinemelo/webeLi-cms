(() => {
  const root = document.documentElement;
  if (root.dataset.amcmsVisualBridge === '1') return;
  root.dataset.amcmsVisualBridge = '1';

  function parentOrigin() {
    return window.location.origin;
  }

  function measureHeight() {
    const body = document.body;
    const html = document.documentElement;
    return Math.max(
      body?.scrollHeight || 0,
      body?.offsetHeight || 0,
      html?.clientHeight || 0,
      html?.scrollHeight || 0,
      html?.offsetHeight || 0
    );
  }

  function readableBackgroundColor(value) {
    const color = String(value || '').trim();
    return color && color !== 'transparent' && color !== 'rgba(0, 0, 0, 0)' ? color : '';
  }

  function previewChromeColor() {
    const selectors = ['.site-header', 'header.site-header', '.navbar', '.site-navigation', 'header[role="banner"]', 'header'];
    for (const selector of selectors) {
      const node = document.querySelector(selector);
      if (!node) continue;
      const color = readableBackgroundColor(window.getComputedStyle(node).backgroundColor);
      if (color) return color;
    }
    return readableBackgroundColor(window.getComputedStyle(document.body).backgroundColor) || '#ffffff';
  }

  function notifySize() {
    window.parent?.postMessage({ type: 'amcms:visual-frame-resize', payload: { height: measureHeight(), chromeColor: previewChromeColor() } }, parentOrigin());
  }

  function fieldPayloadFromBlockHint(target, block) {
    if (!block) return null;
    const clickedButton = target.closest('a, button, [role="button"], .btn, .button');
    const clickedMedia = target.closest('img, picture, figure, video, audio, [data-amcms-media="1"]:not([data-amcms-block="1"])');
    if (clickedButton && block.dataset.amcmsButtonFieldPath) {
      return {
        kind: 'block',
        blockId: block.dataset.amcmsBlockId || '',
        blockType: block.dataset.amcmsBlockType || '',
        blockIndex: block.dataset.amcmsBlockIndex || '',
        fieldPath: block.dataset.amcmsButtonFieldPath || '',
        editor: 'button_list',
        label: block.dataset.amcmsButtonLabel || 'Boutons',
        buttonIndex: clickedButton.dataset.amcmsButtonIndex || '',
      };
    }
    if (clickedMedia && block.dataset.amcmsImageFieldPath) {
      return {
        kind: 'block',
        blockId: block.dataset.amcmsBlockId || '',
        blockType: block.dataset.amcmsBlockType || '',
        blockIndex: block.dataset.amcmsBlockIndex || '',
        fieldPath: block.dataset.amcmsImageFieldPath || '',
        editor: 'media_url',
        label: block.dataset.amcmsImageLabel || 'Image',
      };
    }
    return null;
  }

  function payloadFrom(target) {
    const direct = target.closest('[data-amcms-editable="1"]');
    const block = target.closest('[data-amcms-block="1"]');
    const editable = direct || block;
    if (!editable) return null;
    const hinted = !direct ? fieldPayloadFromBlockHint(target, block) : null;
    if (hinted) return hinted;
    return {
      kind: editable.dataset.amcmsKind || (editable.dataset.amcmsBlock === '1' ? 'block' : ''),
      blockId: editable.dataset.amcmsBlockId || block?.dataset.amcmsBlockId || '',
      blockType: editable.dataset.amcmsBlockType || block?.dataset.amcmsBlockType || '',
      blockIndex: editable.dataset.amcmsBlockIndex || block?.dataset.amcmsBlockIndex || '',
      fieldPath: editable.dataset.amcmsFieldPath || '',
      editor: editable.dataset.amcmsEditor || '',
      label: editable.dataset.amcmsLabel || editable.dataset.amcmsBlockType || 'Bloc',
      buttonIndex: editable.dataset.amcmsButtonIndex || '',
    };
  }

  function select(payload) {
    window.parent?.postMessage({ type: 'amcms:visual-field-selected', payload }, parentOrigin());
  }

  document.addEventListener('click', (event) => {
    const link = event.target.closest('a[href]');
    const payload = payloadFrom(event.target);
    if (!payload && link) {
      const href = link.getAttribute('href') || '';
      const target = link.getAttribute('target') || '';
      if (href && !href.startsWith('#') && target !== '_blank') {
        try {
          const url = new URL(href, window.location.href);
          if (url.origin === window.location.origin) {
            event.preventDefault();
            event.stopPropagation();
            window.parent?.postMessage({ type: 'amcms:visual-navigate', payload: { url: url.toString(), path: url.pathname + url.search + url.hash } }, parentOrigin());
          }
        } catch (_) {}
      }
      return;
    }
    if (!payload) return;
    event.preventDefault();
    event.stopPropagation();
    document.querySelectorAll('[data-amcms-selected="1"]').forEach((node) => node.removeAttribute('data-amcms-selected'));
    const block = event.target.closest('[data-amcms-block="1"]');
    if (block) block.setAttribute('data-amcms-selected', '1');
    select(payload);
  }, true);


  const STATUS_LABELS = {
    draft: 'BLOC :: BROUILLON',
    review: 'BLOC :: EN RELECTURE',
    ready: 'BLOC :: PRÊT À PUBLIER',
    archived: 'BLOC :: ARCHIVÉ',
  };

  function normaliseStatus(value) {
    const status = String(value || '').trim().toLowerCase().replace(/_/g, '-');
    if (status === 'draft' || status === 'brouillon') return 'draft';
    if (status === 'review' || status === 'relecture') return 'review';
    if (status === 'ready' || status === 'ready-to-publish' || status === 'validated' || status === 'approved') return 'ready';
    if (status === 'archived' || status === 'archive') return 'archived';
    return 'none';
  }

  function applyBlockStatus(block, rawStatus, rawLabel) {
    if (!block) return;
    const status = normaliseStatus(rawStatus);
    if (!status || status === 'none' || status === 'published') {
      block.removeAttribute('data-amcms-editorial-status');
      block.removeAttribute('data-amcms-editorial-label');
      return;
    }
    block.setAttribute('data-amcms-editorial-status', status);
    block.setAttribute('data-amcms-editorial-label', String(rawLabel || STATUS_LABELS[status] || 'BLOC'));
  }

  function syncBlockStatuses(blocks) {
    if (!Array.isArray(blocks)) return;
    for (const item of blocks) {
      const id = String(item?.id || '');
      if (!id) continue;
      const block = document.querySelector(`[data-amcms-block-id="${CSS.escape(id)}"]`);
      applyBlockStatus(block, item?.status || item?.visual_status || item?.editorial_status, item?.label);
    }
  }

  window.addEventListener('message', (event) => {
    if (event.origin !== parentOrigin()) return;
    const message = event.data || {};
    if (message.type === 'amcms:visual-request-size') {
      notifySize();
      return;
    }
    if (message.type === 'amcms:visual-sync-block-statuses') {
      syncBlockStatuses(message.payload?.blocks);
      notifySize();
      return;
    }
    if (message.type !== 'amcms:visual-highlight-block') return;
    const blockId = String(message.payload?.blockId || '');
    document.querySelectorAll('[data-amcms-selected="1"]').forEach((node) => node.removeAttribute('data-amcms-selected'));
    if (!blockId) return;
    const block = document.querySelector(`[data-amcms-block-id="${CSS.escape(blockId)}"]`);
    if (block) {
      block.setAttribute('data-amcms-selected', '1');
      block.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }
    notifySize();
  });

  if ('ResizeObserver' in window) {
    const observer = new ResizeObserver(() => notifySize());
    observer.observe(document.documentElement);
    if (document.body) observer.observe(document.body);
  }
  window.addEventListener('load', notifySize);
  window.addEventListener('resize', notifySize);
  setTimeout(notifySize, 80);
  setTimeout(notifySize, 400);
})();
