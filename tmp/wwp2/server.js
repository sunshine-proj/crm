const http = require("node:http");
const fs = require("node:fs");
const path = require("node:path");

const PORT = process.env.PORT || 3000;
const PUBLIC_DIR = path.join(__dirname, "public");

const MIME_TYPES = {
  ".html": "text/html; charset=utf-8",
  ".css": "text/css; charset=utf-8",
  ".js": "text/javascript; charset=utf-8",
  ".json": "application/json; charset=utf-8",
  ".png": "image/png",
  ".svg": "image/svg+xml",
  ".ico": "image/x-icon"
};

const server = http.createServer((req, res) => {
  if (req.method !== "GET" && req.method !== "HEAD") {
    res.writeHead(405, { "Content-Type": "text/plain" });
    res.end("Method not allowed");
    return;
  }

  let pathname;

  try {
    pathname = decodeURIComponent(
      new URL(req.url, `http://${req.headers.host || "localhost"}`)
        .pathname
    );
  } catch {
    res.writeHead(400);
    res.end("Bad request");
    return;
  }

  if (pathname === "/") {
    pathname = "/index.html";
  }

  const filePath = path.resolve(PUBLIC_DIR, `.${pathname}`);

  if (
    filePath !== PUBLIC_DIR &&
    !filePath.startsWith(PUBLIC_DIR + path.sep)
  ) {
    res.writeHead(403);
    res.end("Forbidden");
    return;
  }

  fs.stat(filePath, (statError, stats) => {
    if (statError || !stats.isFile()) {
      res.writeHead(404, { "Content-Type": "text/plain" });
      res.end("Not found");
      return;
    }

    const contentType =
      MIME_TYPES[path.extname(filePath)] ||
      "application/octet-stream";

    res.writeHead(200, {
      "Content-Type": contentType,
      "Cache-Control": "no-cache",
      "X-Content-Type-Options": "nosniff"
    });

    if (req.method === "HEAD") {
      res.end();
      return;
    }

    const stream = fs.createReadStream(filePath);

    stream.on("error", () => {
      if (!res.headersSent) {
        res.writeHead(500);
      }

      res.end();
    });

    stream.pipe(res);
  });
});

server.listen(PORT, "0.0.0.0", () => {
  console.log(`Worms PWA running at http://localhost:${PORT}`);
});
