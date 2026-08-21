// Minimal signed-cookie session (HMAC-SHA256). Stores the user's own
// Moodle token; httpOnly so JS can never read it.
import { createHmac, timingSafeEqual } from "crypto";
import { cookies } from "next/headers";

const SECRET = process.env.SESSION_SECRET || "dev-secret-change-me";
const COOKIE = "ustar_session";

function sign(value: string) {
  return createHmac("sha256", SECRET).update(value).digest("base64url");
}

export function createSession(token: string) {
  const payload = Buffer.from(JSON.stringify({ t: token, at: Date.now() })).toString("base64url");
  const cookieValue = `${payload}.${sign(payload)}`;
  cookies().set(COOKIE, cookieValue, {
    httpOnly: true,
    sameSite: "lax",
    secure: process.env.NODE_ENV === "production",
    path: "/",
    maxAge: 60 * 60 * 12, // 12h working session
  });
}

export function getSessionToken(): string | null {
  const raw = cookies().get(COOKIE)?.value;
  if (!raw) return null;
  const dot = raw.lastIndexOf(".");
  if (dot < 0) return null;
  const payload = raw.slice(0, dot);
  const sig = raw.slice(dot + 1);
  const expected = sign(payload);
  const a = Buffer.from(sig);
  const b = Buffer.from(expected);
  if (a.length !== b.length || !timingSafeEqual(a, b)) return null;
  try {
    const data = JSON.parse(Buffer.from(payload, "base64url").toString());
    return data.t as string;
  } catch {
    return null;
  }
}

export function destroySession() {
  cookies().delete(COOKIE);
}
