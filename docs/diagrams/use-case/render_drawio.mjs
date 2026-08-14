/**
 * Render DLMS_USE_CASE_DIAGRAM.drawio pages to PNG/SVG using the
 * diagrams.net viewer + system Chrome (puppeteer-core).
 */
import fs from "fs";
import http from "http";
import path from "path";
import { fileURLToPath } from "url";
import puppeteer from "puppeteer-core";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DRAWIO = path.join(__dirname, "DLMS_USE_CASE_DIAGRAM.drawio");
const EXPORTS = path.join(__dirname, "exports");

const PAGE_FILES = [
  "UC-OVERVIEW",
  "UC-00",
  "UC-01",
  "UC-02",
  "UC-03",
  "UC-04",
  "UC-05",
];

const CHROME_CANDIDATES = [
  "C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe",
  "C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe",
  "C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe",
];

function chromePath() {
  const env = process.env.CHROME_PATH;
  if (env && fs.existsSync(env)) return env;
  for (const p of CHROME_CANDIDATES) {
    if (fs.existsSync(p)) return p;
  }
  throw new Error("Chrome/Edge not found");
}

function extractDiagrams(xml) {
  const re = /<diagram\b[^>]*name="([^"]*)"[^>]*>([\s\S]*?)<\/diagram>/g;
  const out = [];
  let m;
  while ((m = re.exec(xml))) {
    out.push({ name: m[1], body: m[2].trim() });
  }
  return out;
}

function pageSize(body) {
  const w = /pageWidth="(\d+)"/.exec(body);
  const h = /pageHeight="(\d+)"/.exec(body);
  return { width: w ? Number(w[1]) : 1600, height: h ? Number(h[1]) : 900 };
}

function htmlFor(url, width, height) {
  const cfg = {
    highlight: "#0000ff",
    nav: false,
    resize: true,
    toolbar: null,
    lightbox: false,
    "auto-fit": false,
    "check-visible-state": false,
    zoom: 1,
    border: 24,
    url,
  };
  const json = JSON.stringify(cfg);
  return `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"/>
<style>
  html, body { margin: 0; padding: 0; background: #ffffff; overflow: hidden; }
  #stage { width: ${width}px; height: ${height}px; background: #ffffff; }
  .mxgraph { width: ${width}px; height: ${height}px; border: none !important; }
</style>
</head>
<body>
<div id="stage">
  <div class="mxgraph" data-mxgraph='${json}'></div>
</div>
<script src="https://viewer.diagrams.net/js/viewer-static.min.js"></script>
</body>
</html>`;
}

function startServer(root) {
  return new Promise((resolve) => {
    const server = http.createServer((req, res) => {
      const rel = decodeURIComponent(req.url.split("?")[0]).replace(/^\/+/, "");
      const fp = path.join(root, rel || "index.html");
      if (!fp.startsWith(root) || !fs.existsSync(fp) || fs.statSync(fp).isDirectory()) {
        res.writeHead(404);
        res.end("not found");
        return;
      }
      const ext = path.extname(fp);
      const types = {
        ".html": "text/html; charset=utf-8",
        ".js": "text/javascript",
        ".svg": "image/svg+xml",
        ".drawio": "application/xml; charset=utf-8",
        ".xml": "application/xml; charset=utf-8",
      };
      res.writeHead(200, { "Content-Type": types[ext] || "application/octet-stream" });
      fs.createReadStream(fp).pipe(res);
    });
    server.listen(0, "127.0.0.1", () => {
      resolve({ server, port: server.address().port });
    });
  });
}

async function main() {
  fs.mkdirSync(EXPORTS, { recursive: true });
  const xml = fs.readFileSync(DRAWIO, "utf8");
  const diagrams = extractDiagrams(xml);
  if (diagrams.length !== 7) {
    throw new Error(`Expected 7 diagrams, got ${diagrams.length}`);
  }

  const work = path.join(EXPORTS, "_html");
  fs.mkdirSync(work, { recursive: true });
  const sizes = [];
  diagrams.forEach((d, i) => {
    const { width, height } = pageSize(d.body);
    sizes.push({ width, height });
    const mxfile =
      `<?xml version="1.0" encoding="UTF-8"?>\n` +
      `<mxfile host="Electron" pages="1"><diagram id="p" name="${d.name}">${d.body}</diagram></mxfile>`;
    fs.writeFileSync(path.join(work, `${PAGE_FILES[i]}.drawio`), mxfile, "utf8");
    fs.writeFileSync(
      path.join(work, `${PAGE_FILES[i]}.html`),
      htmlFor(`${PAGE_FILES[i]}.drawio`, width, height),
      "utf8",
    );
  });

  const { server, port } = await startServer(work);
  const browser = await puppeteer.launch({
    executablePath: chromePath(),
    headless: "new",
    args: ["--no-sandbox", "--disable-gpu", "--hide-scrollbars", "--font-render-hinting=none"],
  });

  const only = process.argv.slice(2);
  const targets = diagrams
    .map((d, i) => ({ d, i, name: PAGE_FILES[i], ...sizes[i] }))
    .filter((t) => only.length === 0 || only.includes(t.name));

  try {
    for (const t of targets) {
      const name = t.name;
      const width = t.width;
      const height = t.height;
      const scale = name === "UC-00" ? 2 : 1.5;
      const page = await browser.newPage();
      await page.setViewport({ width: width + 48, height: height + 48, deviceScaleFactor: scale });
      page.on("pageerror", (err) => console.error(`pageerror ${name}:`, err.message));
      page.on("console", (msg) => {
        if (["error", "warning"].includes(msg.type())) console.error(`console ${name}:`, msg.type(), msg.text());
      });
      await page.goto(`http://127.0.0.1:${port}/${name}.html`, { waitUntil: "networkidle0", timeout: 120000 });
      const ready = await page.evaluate(() => ({
        scripts: [...document.scripts].map((s) => s.src),
        mx: !!document.querySelector(".mxgraph"),
        svg: !!document.querySelector("svg"),
        bodyText: (document.body && document.body.innerText || "").slice(0, 200),
      }));
      console.log(`debug ${name}`, JSON.stringify(ready));
      await page.waitForSelector("svg", { timeout: 90000 });
      await page.waitForFunction(() => {
        const svg = document.querySelector("svg");
        if (!svg) return false;
        const r = svg.getBoundingClientRect();
        return r.width > 80 && r.height > 80;
      }, { timeout: 60000 });
      await new Promise((r) => setTimeout(r, 800));

      const pngPath = path.join(EXPORTS, `${name}.png`);
      const svgPath = path.join(EXPORTS, `${name}.svg`);
      const svgHandle = await page.$("svg");
      if (svgHandle) {
        await svgHandle.screenshot({ path: pngPath, type: "png" });
        const svg = await page.$eval("svg", (el) => {
          const clone = el.cloneNode(true);
          if (!clone.getAttribute("xmlns")) clone.setAttribute("xmlns", "http://www.w3.org/2000/svg");
          if (!clone.getAttribute("xmlns:xlink")) clone.setAttribute("xmlns:xlink", "http://www.w3.org/1999/xlink");
          return clone.outerHTML;
        });
        fs.writeFileSync(svgPath, svg, "utf8");
      } else {
        await page.screenshot({ path: pngPath, type: "png", fullPage: true });
      }
      await page.close();
      const st = fs.statSync(pngPath);
      console.log(`exported ${name}.png (${st.size} bytes) scale=${scale} canvas=${width}x${height}`);
    }
  } finally {
    await browser.close();
    server.close();
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
