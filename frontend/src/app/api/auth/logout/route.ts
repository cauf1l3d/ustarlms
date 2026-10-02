import { NextResponse } from "next/server";
import { destroySession } from "@/lib/session";
import { assertSameOrigin, RequestError } from "@/lib/request-security";

export async function POST(req: Request) {
  try {
    assertSameOrigin(req);
    await destroySession();
    return NextResponse.json({ ok: true });
  } catch (e) {
    return NextResponse.json({ error: "Не удалось завершить сеанс" }, { status: e instanceof RequestError ? e.status : 500 });
  }
}
