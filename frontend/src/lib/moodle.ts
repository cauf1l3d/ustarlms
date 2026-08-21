// Server-side Moodle Web Services client.
// The frontend NEVER talks to the Moodle DB — only to Moodle REST API,
// always with the PERSONAL token of the logged-in user, so all
// capability checks are enforced by Moodle itself.

const MOODLE_URL = process.env.MOODLE_URL!;
const SERVICE = process.env.MOODLE_SERVICE || "ustar_workspace";

export async function moodleLogin(username: string, password: string) {
  const url = `${MOODLE_URL}/login/token.php`;
  const body = new URLSearchParams({ username, password, service: SERVICE });
  const res = await fetch(url, { method: "POST", body, cache: "no-store" });
  const data = await res.json();
  if (!data.token) {
    throw new Error(data.error || "Неверный логин или пароль");
  }
  return data.token as string;
}

export async function moodleCall<T = any>(
  token: string,
  wsfunction: string,
  params: Record<string, string | number> = {}
): Promise<T> {
  const url = `${MOODLE_URL}/webservice/rest/server.php`;
  const body = new URLSearchParams({
    wstoken: token,
    wsfunction,
    moodlewsrestformat: "json",
    ...Object.fromEntries(
      Object.entries(params).map(([k, v]) => [k, String(v)])
    ),
  });
  const res = await fetch(url, { method: "POST", body, cache: "no-store" });
  const data = await res.json();
  if (data && typeof data === "object" && "exception" in data) {
    throw new Error(`${data.errorcode}: ${data.message}`);
  }
  return data as T;
}

/** Helper for local_ustar_* functions that wrap payload in { json: "..." }. */
export async function ustarCall<T = any>(
  token: string,
  fn: string,
  params: Record<string, string | number> = {}
): Promise<T> {
  const data = await moodleCall<{ json?: string } | any>(token, fn, params);
  if (data && typeof data === "object" && typeof data.json === "string") {
    return JSON.parse(data.json) as T;
  }
  return data as T;
}

export function moodleUploadUrl() {
  return `${MOODLE_URL}/webservice/upload.php`;
}
export function moodleFileUrl(fileurl: string, token: string) {
  // Convert pluginfile URL to webservice download URL with token.
  const wsUrl = fileurl.replace("/pluginfile.php", "/webservice/pluginfile.php");
  return `${wsUrl}${wsUrl.includes("?") ? "&" : "?"}token=${token}`;
}
