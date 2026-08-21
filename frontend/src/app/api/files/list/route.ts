import { NextResponse } from "next/server";
import { getSessionToken } from "@/lib/session";
import { moodleCall } from "@/lib/moodle";

export async function GET() {
  const token = getSessionToken();
  if (!token) return NextResponse.json({ error: "unauthorized" }, { status: 401 });
  try {
    const info = await moodleCall(token, "core_user_get_private_files_info");
    return NextResponse.json(info);
  } catch (e) {
    return NextResponse.json(
      { error: e instanceof Error ? e.message : "error" }, { status: 502 });
  }
}
