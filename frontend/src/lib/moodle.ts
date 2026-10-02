// Server-side Moodle Web Services client.
// The frontend NEVER talks to the Moodle DB — only to Moodle REST API,
// always with the PERSONAL token of the logged-in user, so all
// capability checks are enforced by Moodle itself.

const MOODLE_URL = process.env.MOODLE_URL;
const SERVICE = process.env.MOODLE_SERVICE || "ustar_workspace";
const RESERVED = new Set(["wstoken", "wsfunction", "moodlewsrestformat"]);

function moodleUrl(path: string): string {
  if (!MOODLE_URL) throw new Error("Moodle connection is not configured");
  const root = new URL(MOODLE_URL);
  if (!["http:", "https:"].includes(root.protocol) || root.username || root.password) throw new Error("Invalid Moodle URL");
  return `${MOODLE_URL.replace(/\/$/, "")}${path}`;
}

export async function moodleLogin(username: string, password: string) {
  const url = moodleUrl("/login/token.php");
  const body = new URLSearchParams({ username, password, service: SERVICE });
  const res = await fetch(url, { method: "POST", body, cache: "no-store", signal: AbortSignal.timeout(20000) });
  if (!res.ok) throw new Error("Сервис входа временно недоступен");
  const data = await res.json();
  if (typeof data.token !== "string" || !data.token || data.token.length > 4096) {
    throw new Error("Неверный логин или пароль");
  }
  return data.token as string;
}

export async function moodleCall<T = any>(
  token: string,
  wsfunction: string,
  params: Record<string, unknown> = {}
): Promise<T> {
  const body = new URLSearchParams();
  for (const [name, value] of Object.entries(params)) {
    if (RESERVED.has(name.toLowerCase()) || !/^[a-zA-Z][a-zA-Z0-9_]*$/.test(name)
        || !["string", "number", "boolean"].includes(typeof value)) {
      throw new Error("Недопустимый параметр запроса");
    }
    body.set(name, String(value));
  }
  body.set("wstoken", token);
  body.set("wsfunction", wsfunction);
  body.set("moodlewsrestformat", "json");
  const url = moodleUrl("/webservice/rest/server.php");
  const res = await fetch(url, { method: "POST", body, cache: "no-store", signal: AbortSignal.timeout(20000) });
  if (!res.ok) throw new Error("Moodle временно недоступен");
  const data = await res.json();
  if (data && typeof data === "object" && "exception" in data) {
    throw new Error("Moodle отклонил запрос. Проверьте доступ и введённые данные.");
  }
  return data as T;
}

/** Helper for local_ustar_* functions that wrap payload in { json: "..." }. */
export async function ustarCall<T = any>(
  token: string,
  fn: string,
  params: Record<string, unknown> = {}
): Promise<T> {
  const data = await moodleCall<{ json?: string } | any>(token, fn, params);
  if (data && typeof data === "object" && typeof data.json === "string") {
    return JSON.parse(data.json) as T;
  }
  return data as T;
}

export function moodleUploadUrl() {
  return moodleUrl("/webservice/upload.php");
}
export function moodleFileUrl(fileurl: string, token: string) {
  if (new URL(fileurl).origin !== new URL(moodleUrl("/")).origin) throw new Error("Недопустимый адрес файла");
  // Convert pluginfile URL to webservice download URL with token.
  const wsUrl = fileurl.replace("/pluginfile.php", "/webservice/pluginfile.php");
  return `${wsUrl}${wsUrl.includes("?") ? "&" : "?"}token=${token}`;
}
