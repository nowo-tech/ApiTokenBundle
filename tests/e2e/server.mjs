// Static server of the e2e check: fixtures/ at /, UiKit's public assets at /bundles/nowouikit/.
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { extname, join, normalize } from 'node:path';

const fixtures = new URL('./fixtures/', import.meta.url).pathname;
const uikit = process.env.UIKIT_PUBLIC ?? new URL('../../vendor/nowo-tech/ui-kit-bundle/src/Resources/public/', import.meta.url).pathname;
const types = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' };

createServer(async (req, res) => {
  const path = decodeURIComponent(new URL(req.url, 'http://x').pathname);
  if (path === '/health') { res.end('ok'); return; }
  const [root, rest] = path.startsWith('/bundles/nowouikit/') ? [uikit, path.slice('/bundles/nowouikit/'.length)] : [fixtures, path.slice(1)];
  const file = normalize(join(root, rest));
  if (!file.startsWith(root)) { res.statusCode = 403; res.end(); return; }
  try {
    const body = await readFile(file);
    res.setHeader('Content-Type', types[extname(file)] ?? 'application/octet-stream');
    res.setHeader('Cache-Control', 'no-store, private');
    res.end(body);
  } catch {
    res.statusCode = 404; res.end();
  }
}).listen(8399, '127.0.0.1');
