// Client-side helper: all requests go through our own /api — the Moodle
// token never reaches the browser.
export async function ws<T = any>(fn: string, params: Record<string, any> = {}): Promise<T> {
  const res = await fetch(`/api/ws/${fn}`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(params),
  });
  if (res.status === 401) {
    window.location.href = "/login";
    throw new Error("unauthorized");
  }
  const data = await res.json();
  if (!res.ok) throw new Error(data.error || "API error");
  return data as T;
}
