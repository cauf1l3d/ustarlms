import { NextRequest, NextResponse } from "next/server";
import { moodleLogin } from "@/lib/moodle";
import { createSession } from "@/lib/session";

export async function POST(req: NextRequest) {
  try {
    const { username, password } = await req.json();
    if (!username || !password) {
      return NextResponse.json({ error: "Введите логин и пароль" }, { status: 400 });
    }
    const token = await moodleLogin(username, password);
    createSession(token);
    return NextResponse.json({ ok: true });
  } catch (e) {
    return NextResponse.json(
      { error: e instanceof Error ? e.message : "Ошибка входа" },
      { status: 401 }
    );
  }
}
