/* Measurements stay on this WordPress site. No URLs or customer data are sent. */
(() => {
  'use strict';
  const collect = () => {
    if (new URL(fawsMediaAudit.url, window.location.href).origin !== window.location.origin) return;
    const rows = [...document.images].filter(image => image.complete && image.naturalWidth && image.getBoundingClientRect().width > 0)
      .map(image => {
        const id = Number(image.dataset.fawsId || image.className.match(/\bwp-image-(\d+)\b/)?.[1]);
        const box = image.getBoundingClientRect();
        const entries = performance.getEntriesByName(image.currentSrc);
        const timing = entries[entries.length - 1];
        return {id, width: Math.round(box.width), height: Math.round(box.height), naturalWidth: image.naturalWidth,
          naturalHeight: image.naturalHeight, dpr: window.devicePixelRatio || 1, bytes: timing?.transferSize || 0};
      }).filter(row => Number.isInteger(row.id) && row.id > 0).slice(0, 50);
    if (!rows.length) return;
    const body = new URLSearchParams({action: 'faws_media_audit', nonce: fawsMediaAudit.nonce, context: fawsMediaAudit.context, measurements: JSON.stringify(rows)});
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 5000);
    fetch(fawsMediaAudit.url, {method: 'POST', credentials: 'same-origin', body, signal: controller.signal})
      .then(response => response.json()).catch(() => {}).finally(() => window.clearTimeout(timeout));
  };
  if (document.readyState === 'complete') collect();
  else window.addEventListener('load', collect, {once: true});
})();
