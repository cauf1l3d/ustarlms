export class RequestError extends Error {
  status: number;
  constructor(status: number, message: string) { super(message); this.status = status; }
}

/** Browser mutation endpoints require the configured public origin. */
export function assertSameOrigin(req: Request): void {
  const expected = new URL(process.env.APP_ORIGIN || req.url).origin;
  if (req.headers.get("origin") !== expected) throw new RequestError(403, "Недопустимый источник запроса");
}

/** Bound streamed bodies, including requests without a Content-Length header. */
export async function readBoundedBody(req: Request, maximum: number): Promise<ArrayBuffer> {
  const declared = req.headers.get("content-length");
  if (declared !== null && (!/^\d+$/.test(declared) || Number(declared) > maximum)) {
    throw new RequestError(413, "Запрос слишком большой");
  }
  const reader = req.body?.getReader();
  if (!reader) return new ArrayBuffer(0);
  const parts: Uint8Array[] = [];
  let size = 0;
  try {
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      size += value.byteLength;
      if (size > maximum) { await reader.cancel(); throw new RequestError(413, "Запрос слишком большой"); }
      parts.push(value);
    }
  } finally { reader.releaseLock(); }
  const bytes = new Uint8Array(size);
  let offset = 0;
  for (const part of parts) { bytes.set(part, offset); offset += part.byteLength; }
  return bytes.buffer;
}

export async function readJsonObject(req: Request, maximum = 64 * 1024): Promise<Record<string, unknown>> {
  if (req.headers.get("content-type")?.split(";")[0].trim() !== "application/json") {
    throw new RequestError(415, "Ожидается JSON");
  }
  const body = await readBoundedBody(req, maximum);
  try {
    const data = JSON.parse(new TextDecoder().decode(body));
    if (!data || typeof data !== "object" || Array.isArray(data)) throw new Error();
    return data;
  } catch { throw new RequestError(400, "Некорректный JSON"); }
}
