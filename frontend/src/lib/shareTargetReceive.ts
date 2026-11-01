/** Cache key used by public/share-target-sw.js for inbound Web Share Target files. */
export const SHARE_TARGET_CACHE = 'rp-share-target'
const META_URL = '/__share_target_meta'
const FILE_URL_PREFIX = '/__share_target_file_'

type ShareMeta = {
  names: string[]
  types: string[]
}

/** Read and clear files stashed by the share-target service worker. */
export async function takeSharedFiles(): Promise<File[]> {
  if (typeof caches === 'undefined') {
    return []
  }

  const cache = await caches.open(SHARE_TARGET_CACHE)
  const metaResponse = await cache.match(META_URL)
  if (!metaResponse) {
    return []
  }

  let meta: ShareMeta
  try {
    meta = (await metaResponse.json()) as ShareMeta
  } catch {
    await cache.delete(META_URL)
    return []
  }

  const names = Array.isArray(meta.names) ? meta.names : []
  const types = Array.isArray(meta.types) ? meta.types : []
  const files: File[] = []

  for (let index = 0; index < names.length; index += 1) {
    const response = await cache.match(`${FILE_URL_PREFIX}${index}`)
    if (!response) {
      continue
    }
    const blob = await response.blob()
    files.push(
      new File([blob], names[index] || `shared-${index}`, {
        type: types[index] || blob.type || 'application/octet-stream',
      }),
    )
  }

  const keys = await cache.keys()
  await Promise.all(keys.map((key) => cache.delete(key)))

  return files
}

console.assert(SHARE_TARGET_CACHE === 'rp-share-target', 'shareTargetReceive: cache name must match SW')
