/** True when the browser can share a File via the Web Share API. */
export function canShareFiles(): boolean {
  try {
    if (typeof navigator === 'undefined' || typeof navigator.share !== 'function' || typeof navigator.canShare !== 'function') {
      return false
    }
    const probe = new File(['x'], 'probe.txt', { type: 'text/plain' })
    return navigator.canShare({ files: [probe] })
  } catch {
    return false
  }
}

export async function shareBlobFile(
  blob: Blob,
  filename: string,
  mimeType?: string | null,
  title?: string,
): Promise<boolean> {
  const file = new File([blob], filename, {
    type: mimeType || blob.type || 'application/octet-stream',
  })
  if (!navigator.canShare?.({ files: [file] })) {
    return false
  }
  await navigator.share({
    files: [file],
    title: title || filename,
  })
  return true
}
