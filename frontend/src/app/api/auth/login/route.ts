import { NextRequest, NextResponse } from "next/server";
import { moodleLogin } from "@/lib/moodle";
import { createSession } from "@/lib/session";
import { assertSameOrigin, readJsonObject, RequestError } from "@/lib/request-security";

export async function POST(req: NextRequest) {
  try {
    assertSameOrigin(req);
    const { username, password } = await readJsonObject(req, 8192);
    if (typeof username !== "string" || typeof password !== "string" || !username || !password || username.length > 100 || password.length > 1024) {
      return NextResponse.json({ error: "Введите логин и пароль" }, { status: 400 });
    }
    const token = await moodleLogin(username, password);
    await createSession(token);
    return NextResponse.json({ ok: true });
  } catch (e) {
    return NextResponse.json(
      { error: e instanceof Error ? e.message : "Ошибка входа" },
      { status: e instanceof RequestError ? e.status : 401 }
    );
  }
}
