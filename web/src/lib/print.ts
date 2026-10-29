/**
 * Print one element through a hidden iframe: the page itself is untouched, and the
 * @page size matches the paper (80 mm thermal roll or A4).
 */
export function printElement(id: string, format: 'thermal' | 'a4' = 'thermal') {
  const el = document.getElementById(id)
  if (!el) return
  const frame = document.createElement('iframe')
  frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0'
  document.body.appendChild(frame)
  const css = [...document.querySelectorAll('style,link[rel="stylesheet"]')].map((n) => n.outerHTML).join('')
  const page = format === 'thermal' ? '@page{size:80mm auto;margin:0}' : '@page{size:A4;margin:10mm}'
  const doc = frame.contentDocument!
  doc.open()
  doc.write(`<!doctype html><html><head><meta charset="utf-8">${css}<style>${page}body{background:#fff;margin:0}</style></head><body>${el.innerHTML}</body></html>`)
  doc.close()
  const go = () => {
    frame.contentWindow!.focus()
    frame.contentWindow!.print()
    setTimeout(() => frame.remove(), 1000)
  }
  // Wait for the logo (if any) so it is on the paper.
  const imgs = [...doc.images].filter((i) => !i.complete)
  imgs.length ? Promise.all(imgs.map((i) => new Promise((r) => (i.onload = i.onerror = r)))).then(go) : setTimeout(go, 50)
}
