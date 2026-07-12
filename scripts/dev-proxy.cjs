/**
 * Dev-only reverse proxy for Windows: PHP's built-in server (php artisan
 * serve) corrupts responses under concurrent keep-alive requests, which is
 * exactly what a mobile app does when it loads a shelf of cover images.
 * This proxy accepts fully concurrent keep-alive traffic from clients and
 * talks to PHP with one fresh connection per request (its reliable mode).
 *
 * Usage:
 *   php artisan serve --host=127.0.0.1 --port=8001
 *   node scripts/dev-proxy.js   # listens on 0.0.0.0:8000 -> 127.0.0.1:8001
 */
const http = require('http');

const LISTEN_PORT = Number(process.env.PROXY_PORT || 8000);
const TARGET_HOST = '127.0.0.1';
const TARGET_PORT = Number(process.env.PHP_PORT || 8001);

const server = http.createServer((req, res) => {
  const headers = { ...req.headers, connection: 'close' };

  const upstream = http.request(
    {
      host: TARGET_HOST,
      port: TARGET_PORT,
      method: req.method,
      path: req.url,
      headers,
      agent: false, // fresh socket per request; php -S handles these cleanly
    },
    (upstreamRes) => {
      const responseHeaders = { ...upstreamRes.headers };
      delete responseHeaders.connection;

      res.writeHead(upstreamRes.statusCode || 502, responseHeaders);
      upstreamRes.pipe(res);
    },
  );

  upstream.on('error', () => {
    if (!res.headersSent) {
      res.writeHead(502, { 'content-type': 'application/json' });
    }

    res.end(JSON.stringify({ message: 'Dev proxy: PHP server unreachable.' }));
  });

  req.pipe(upstream);
});

server.listen(LISTEN_PORT, '0.0.0.0', () => {
  console.log(`dev proxy: 0.0.0.0:${LISTEN_PORT} -> ${TARGET_HOST}:${TARGET_PORT}`);
});
