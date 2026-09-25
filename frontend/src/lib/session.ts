import { cookies } from "next/headers";
import { decodeSession, encodeSession, SESSION_TTL_SECONDS } from "./session-codec";
const COOKIE = "ustar_session";
export async function createSession(token: string) {
  const value = encodeSession(token, process.env.SESSION_SECRET || "");
  (await cookies()).set(COOKIE, value, {
    httpOnly: true,
    sameSite: "lax",
    secure: process.env.NODE_ENV === "production",
    path: "/",
    maxAge: SESSION_TTL_SECONDS,
  });
}

export async function getSessionToken(): Promise<string | null> {
  const raw = (await cookies()).get(COOKIE)?.value;
  return raw ? decodeSession(raw, process.env.SESSION_SECRET || "") : null;
}

export async function destroySession() {
  (await cookies()).delete(COOKIE);
}
