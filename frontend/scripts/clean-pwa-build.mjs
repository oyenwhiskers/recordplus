/**
 * Wipe previous Vite/PWA output under ../public so Workbox does not
 * precache every historical hashed chunk (emptyOutDir must stay false
 * to keep Laravel's public/index.php and static landing assets).
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const publicDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../public')

const removeDir = path.join(publicDir, 'assets')
const removeFiles = [
  'index.html',
  'sw.js',
  'manifest.webmanifest',
  'registerSW.js',
]

function rm(target) {
  fs.rmSync(target, { recursive: true, force: true })
}

rm(removeDir)
for (const name of removeFiles) {
  rm(path.join(publicDir, name))
}

for (const entry of fs.readdirSync(publicDir, { withFileTypes: true })) {
  if (entry.isFile() && /^workbox-.*\.js$/.test(entry.name)) {
    rm(path.join(publicDir, entry.name))
  }
}

console.assert(!fs.existsSync(removeDir), 'clean-pwa-build: public/assets must be gone before vite build')
console.assert(!fs.existsSync(path.join(publicDir, 'sw.js')), 'clean-pwa-build: sw.js must be gone before vite build')
