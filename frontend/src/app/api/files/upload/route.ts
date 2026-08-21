import { NextRequest, NextResponse } from "next/server";
import { getSessionToken } from "@/lib/session";
import { moodleCall, moodleUploadUrl } from "@/lib/moodle";

// Personal file storage = Moodle private files area.
// 1) upload to draft area  2) move draft into private files.
export async function POST(req: NextRequest) {
  const token = getSessionToken();
  if (!token) return NextResponse.json({ error: "unauthorized" }, { status: 401 });

  const form = await req.formData();
  const file = form.get("file") as File | null;
  if (!file) return NextResponse.json({ error: "no file" }, { status: 400 });
  if (file.size > 50 * 1024 * 1024) {
    return NextResponse.json({ error: "Файл больше 50 МБ" }, { status: 413 });
  }

  const upstream = new FormData();
  upstream.append("token", token);
  upstream.append("filearea", "draft");
  upstream.append("itemid", "0");
  upstream.append("file_1", file, file.name);

  const upRes = await fetch(moodleUploadUrl(), { method: "POST", body: upstream });
  const uploaded = await upRes.json();
  if (!Array.isArray(uploaded) || !uploaded[0]?.itemid) {
    return NextResponse.json({ error: uploaded?.error || "upload failed" }, { status: 502 });
  }

  await moodleCall(token, "core_user_add_user_private_files", {
    draftid: uploaded[0].itemid,
  });

  return NextResponse.json({ ok: true, name: file.name });
}
