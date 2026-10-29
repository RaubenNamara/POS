/**
 * Resize and re-encode an image in the browser before upload: a 4 MB phone photo becomes
 * a ~100 KB WebP (plus a ~15 KB thumbnail). Faster uploads on slow links, faster pages for
 * shoppers, and no image library needed on the server.
 */
export async function toWebp(file: File, maxSize: number, quality = 0.8): Promise<{ blob: Blob; width: number; height: number }> {
  const bitmap = await createImageBitmap(file)
  const scale = Math.min(1, maxSize / Math.max(bitmap.width, bitmap.height))
  const width = Math.round(bitmap.width * scale)
  const height = Math.round(bitmap.height * scale)
  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height
  const ctx = canvas.getContext('2d')!
  ctx.fillStyle = '#fff' // transparent PNGs get a white background, matching the product cards
  ctx.fillRect(0, 0, width, height)
  ctx.drawImage(bitmap, 0, 0, width, height)
  bitmap.close()
  const blob = await new Promise<Blob>((res, rej) => canvas.toBlob((b) => (b ? res(b) : rej(new Error('Encoding failed'))), 'image/webp', quality))
  // Very old browsers fall back to PNG from toBlob('image/webp'); send JPEG instead.
  if (blob.type !== 'image/webp') {
    const jpg = await new Promise<Blob>((res, rej) => canvas.toBlob((b) => (b ? res(b) : rej(new Error('Encoding failed'))), 'image/jpeg', quality))
    return { blob: jpg, width, height }
  }
  return { blob, width, height }
}
