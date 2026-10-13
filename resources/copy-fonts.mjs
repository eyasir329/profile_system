// Copies the self-hosted font files from node_modules into public/assets/fonts.
// Run by `npm run build`. Only the latin subsets and the weights the design
// system uses are copied; see docs/DESIGN.md ("Typography").
import { copyFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const out = join(root, 'public/assets/fonts');
mkdirSync(out, { recursive: true });

const files = [
  '@fontsource-variable/source-serif-4/files/source-serif-4-latin-wght-normal.woff2',
  '@fontsource/ibm-plex-sans/files/ibm-plex-sans-latin-400-normal.woff2',
  '@fontsource/ibm-plex-sans/files/ibm-plex-sans-latin-400-italic.woff2',
  '@fontsource/ibm-plex-sans/files/ibm-plex-sans-latin-500-normal.woff2',
  '@fontsource/ibm-plex-sans/files/ibm-plex-sans-latin-600-normal.woff2',
];
for (const f of files) {
  copyFileSync(join(root, 'node_modules', f), join(out, f.split('/').pop()));
}
console.log(`fonts: copied ${files.length} files to public/assets/fonts`);
