import { NextResponse } from "next/server";

const MOODLE_URL = process.env.MOODLE_URL!;

export async function GET() {
  try {
    const res = await fetch(`${MOODLE_URL}/local/ustar/public_branding.php`, {
      cache: "no-store",
      headers: { Accept: "application/json" },
    });
    if (!res.ok) throw new Error(`branding upstream ${res.status}`);
    const data = await res.json();
    return NextResponse.json(data, {
      headers: { "Cache-Control": "public, max-age=30, stale-while-revalidate=120" },
    });
  } catch {
    return NextResponse.json({}, { status: 200, headers: { "Cache-Control": "no-store" } });
  }
}
