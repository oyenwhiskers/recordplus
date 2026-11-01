/**
 * Laravel routes excluded from the SPA catch-all (routes/web.php).
 * The PWA navigateFallback must deny these or OAuth opens a blank shell.
 */
export const PWA_NAVIGATE_FALLBACK_DENYLIST = [/^\/(?:auth|api|sanctum|up)(?:\/|$)/]

export function isLaravelNavigationPath(pathname: string): boolean {
  return PWA_NAVIGATE_FALLBACK_DENYLIST.some((pattern) => pattern.test(pathname))
}

console.assert(isLaravelNavigationPath('/auth/google'), 'pwaServerPaths: google redirect must bypass SW')
console.assert(
  isLaravelNavigationPath('/auth/google/callback'),
  'pwaServerPaths: google callback must bypass SW',
)
console.assert(isLaravelNavigationPath('/api/user') === true, 'pwaServerPaths: api must bypass SW')
console.assert(isLaravelNavigationPath('/login') === false, 'pwaServerPaths: SPA routes stay on fallback')
