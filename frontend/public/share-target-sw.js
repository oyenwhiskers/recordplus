/** Web Share Target: stash POST files, then open Capture. */
const SHARE_CACHE = 'rp-share-target'
const META_URL = '/__share_target_meta'
const FILE_URL_PREFIX = '/__share_target_file_'

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url)
  if (event.request.method !== 'POST' || url.pathname !== '/share-target') {
    return
  }

  event.respondWith(
    (async () => {
      const formData = await event.request.formData()
      const files = formData.getAll('files').filter((entry) => entry instanceof File && entry.size > 0)

      const cache = await caches.open(SHARE_CACHE)
      const keys = await cache.keys()
      await Promise.all(keys.map((key) => cache.delete(key)))

      const meta = {
        names: files.map((file) => file.name || 'shared'),
        types: files.map((file) => file.type || 'application/octet-stream'),
      }
      await cache.put(META_URL, new Response(JSON.stringify(meta), {
        headers: { 'content-type': 'application/json' },
      }))

      await Promise.all(
        files.map((file, index) =>
          cache.put(
            `${FILE_URL_PREFIX}${index}`,
            new Response(file, {
              headers: { 'content-type': file.type || 'application/octet-stream' },
            }),
          ),
        ),
      )

      return Response.redirect('/capture?shared=1', 303)
    })(),
  )
})
