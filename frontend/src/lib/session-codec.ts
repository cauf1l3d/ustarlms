import { createCipheriv, createDecipheriv, createHash, randomBytes } from "node:crypto";

export const SESSION_TTL_SECONDS = 12 * 60 * 60;
const AAD = Buffer.from("ustar-session:v1");

function key(secret: string): Buffer {
  if (!secret || Buffer.byteLength(secret) < 32 || secret === "dev-secret-change-me") {
    throw new Error("SESSION_SECRET must contain at least 32 bytes of random secret material");
  }
  return createHash("sha256").update(secret).digest();
}

/** Confidential, authenticated cookie. Changing the secret revokes all sessions. */
export function encodeSession(token: string, secret: string, now = Date.now()): string {
  if (!token || token.length > 4096) throw new Error("Invalid session token");
  const iv = randomBytes(12);
  const cipher = createCipheriv("aes-256-gcm", key(secret), iv);
  cipher.setAAD(AAD);
  const payload = JSON.stringify({ token, issuedAt: now, expiresAt: now + SESSION_TTL_SECONDS * 1000 });
  const encrypted = Buffer.concat([cipher.update(payload, "utf8"), cipher.final()]);
  return ["v1", iv.toString("base64url"), encrypted.toString("base64url"), cipher.getAuthTag().toString("base64url")].join(".");
}

export function decodeSession(raw: string, secret: string, now = Date.now()): string | null {
  const encryptionKey = key(secret);
  if (raw.length > 8192) return null;
  const [version, ivText, payload, tagText, extra] = raw.split(".");
  if (version !== "v1" || !ivText || !payload || !tagText || extra !== undefined) return null;
  try {
    const iv = Buffer.from(ivText, "base64url"), tag = Buffer.from(tagText, "base64url");
    if (iv.length !== 12 || tag.length !== 16) return null;
    const decipher = createDecipheriv("aes-256-gcm", encryptionKey, iv);
    decipher.setAAD(AAD);
    decipher.setAuthTag(tag);
    const data = JSON.parse(Buffer.concat([decipher.update(Buffer.from(payload, "base64url")), decipher.final()]).toString("utf8"));
    if (typeof data.token !== "string" || !data.token || data.token.length > 4096
        || !Number.isSafeInteger(data.issuedAt) || !Number.isSafeInteger(data.expiresAt)
        || data.issuedAt > now || data.expiresAt <= now
        || data.expiresAt - data.issuedAt !== SESSION_TTL_SECONDS * 1000) return null;
    return data.token;
  } catch {
    return null;
  }
}
