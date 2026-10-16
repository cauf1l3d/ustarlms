import { readFile, writeFile } from "node:fs/promises";
import { createHash } from "node:crypto";
import { createRequire } from "node:module";
import { dirname, join } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

// Bundle the real UI with a local-only data adapter; never edit production API code.
const here = dirname(fileURLToPath(import.meta.url));
const ui = join(here, "../ui");
const require = createRequire(join(ui, "package.json"));
const { rolldown } = await import(
  pathToFileURL(require.resolve("rolldown")).href
);
const entry = join(ui, "src/main.tsx");
const source = await readFile(entry, "utf8");
const css = await readFile(join(ui, "src/style.css"), "utf8");
const charts = await readFile(join(ui, "src/charts.tsx"), "utf8");
const start = source.indexOf("async function api(");
const end = source.indexOf("function Badge(", start);
if (start < 0 || end < 0)
  throw Error("UI API boundary changed; review the prototype adapter");
const adapted =
  'import { createPrototypeApi } from "../../prototype/mock-api";\n' +
  (
    source.slice(0, start) +
    "const api = createPrototypeApi(ApiError);\n" +
    source.slice(end)
  )
    .replace('import "./style.css";', "")
    .replace(
      'SERVER1 <span className="env">PRODUCTION</span>',
      'SERVER1 <span className="env">ДЕМО</span>',
    )
    .replace(
      "Не удалось изменить пароль. Проверьте текущий пароль и соединение.",
      "В прототипе пароль не сохраняется.",
    );
const bundle = await rolldown({
  input: entry,
  platform: "browser",
  plugins: [
    {
      name: "offline-prototype",
      transform(code, id) {
        if (id === entry) return adapted;
      },
    },
  ],
});
try {
  const { output } = await bundle.generate({ format: "iife", minify: true });
  if (output.length !== 1 || output[0].type !== "chunk")
    throw Error("Prototype must be a single inline bundle");
  const js = output[0].code.replaceAll("</script", "<\\/script");
  if (/\bfetch\s*\(|XMLHttpRequest|WebSocket/.test(js))
    throw Error("Prototype must not contain network clients");
  const hash = createHash("sha256")
    .update(source)
    .update(css)
    .update(charts)
    .digest("hex");
  const template = await readFile(join(here, "index.template.html"), "utf8");
  const html = template
    .replace("__UI_SOURCE_SHA256__", hash)
    .replace("/* UI_STYLE */", () => css)
    .replace("/* UI_BUNDLE */", () => js);
  const target = join(here, "index.html");
  if (process.argv.includes("--check")) {
    if ((await readFile(target, "utf8")) !== html)
      throw Error(
        "Prototype is stale: run node server-console/prototype/build.mjs",
      );
  } else await writeFile(target, html);
  console.log(
    `PROTOTYPE_OK bytes=${Buffer.byteLength(html)} ui_sha256=${hash}`,
  );
} finally {
  await bundle.close();
}
