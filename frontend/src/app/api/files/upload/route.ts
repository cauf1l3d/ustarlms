import { NextRequest, NextResponse } from "next/server";
import { getSessionToken } from "@/lib/session";
import { moodleCall, moodleUploadUrl } from "@/lib/moodle";
import { assertSameOrigin, readBoundedBody, RequestError } from "@/lib/request-security";

// Personal file storage = Moodle private files area.
// 1) upload to draft area  2) move draft into private files.
export async function POST(req: NextRequest) {
  try {
  assertSameOrigin(req);
  const token = await getSessionToken();
  if (!token) return NextResponse.json({ error: "unauthorized" }, { status: 401 });

  const contentType = req.headers.get("content-type") || "";
  if (!contentType.startsWith("multipart/form-data;")) throw new RequestError(415, "Ожидается файл");
  const body = await readBoundedBody(req, 51 * 1024 * 1024);
  const form = await new Response(body, { headers: { "content-type": contentType } }).formData();
  const file = form.get("file");
  if (!(file instanceof File)) return NextResponse.json({ error: "no file" }, { status: 400 });
  if (file.size > 50 * 1024 * 1024) {
    return NextResponse.json({ error: "Файл больше 50 МБ" }, { status: 413 });
  }

  const upstream = new FormData();
  upstream.append("token", token);
  upstream.append("filearea", "draft");
  upstream.append("itemid", "0");
  upstream.append("file_1", file, file.name);

  const upRes = await fetch(moodleUploadUrl(), { method: "POST", body: upstream, signal: AbortSignal.timeout(60000) });
  if (!upRes.ok) throw new Error("Сервис загрузки недоступен");
  const uploaded = await upRes.json();
  if (!Array.isArray(uploaded) || !uploaded[0]?.itemid) {
    return NextResponse.json({ error: "Не удалось загрузить файл" }, { status: 502 });
  }

  await moodleCall(token, "core_user_add_user_private_files", {
    draftid: uploaded[0].itemid,
  });

  return NextResponse.json({ ok: true, name: file.name });
  } catch (e) {
    return NextResponse.json({ error: e instanceof RequestError ? e.message : "Не удалось загрузить файл" }, { status: e instanceof RequestError ? e.status : 502 });
  }
}
