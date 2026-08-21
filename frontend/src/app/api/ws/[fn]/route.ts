import { NextRequest, NextResponse } from "next/server";
import { getSessionToken } from "@/lib/session";
import { ustarCall } from "@/lib/moodle";

// Whitelist: the browser may call ONLY these Moodle functions.
// Role scoping is enforced inside Moodle (capabilities), so even a
// forged request cannot see another department's data.
const ALLOWED: Record<string, string> = {
  workspace:       "local_ustar_get_workspace",
  dashboard:       "local_ustar_get_dashboard",
  skills:          "local_ustar_get_skills",
  matrix:          "local_ustar_get_matrix",
  ladder:          "local_ustar_get_ladder",
  team:            "local_ustar_get_team",
  games:           "local_ustar_get_games",
  checklists:      "local_ustar_get_checklists",
  checklist_submit:"local_ustar_submit_checklist",
  game_question:   "local_ustar_get_game_question",
  game_answer:     "local_ustar_submit_game_answer",
  save_prefs:      "local_ustar_save_prefs",
  save_goal:       "local_ustar_save_goal",
  hr_workspace:    "local_ustar_hr_get_workspace",
  hr_bulk_assign:  "local_ustar_hr_bulk_assign_positions",
  hr_dashboard:    "local_ustar_hr_get_dashboard",
  hr_people:       "local_ustar_hr_get_people",
  hr_person:       "local_ustar_hr_get_person",
  hr_save_person:  "local_ustar_hr_save_person",
  hr_save_review:  "local_ustar_hr_save_review",
  hr_import_people: "local_ustar_hr_import_people",
  hr_checklists:   "local_ustar_hr_get_checklists",
  hr_save_checklists:"local_ustar_hr_save_checklists",
  hr_save_learning:"local_ustar_hr_save_learning",
  executive:       "local_ustar_executive_get_dashboard",
  admin_structure: "local_ustar_admin_get_structure",
  admin_save:      "local_ustar_admin_save_structure",
  admin_upload_brand: "local_ustar_admin_upload_brand_asset",
  admin_games:     "local_ustar_admin_get_games",
  admin_save_game: "local_ustar_admin_save_game",
  files_info:      "core_user_get_private_files_info",
};

export async function POST(
  req: NextRequest,
  { params }: { params: { fn: string } }
) {
  const token = getSessionToken();
  if (!token) {
    return NextResponse.json({ error: "unauthorized" }, { status: 401 });
  }
  const wsfn = ALLOWED[params.fn];
  if (!wsfn) {
    return NextResponse.json({ error: "forbidden function" }, { status: 403 });
  }
  let body: Record<string, any> = {};
  try {
    body = await req.json();
  } catch {}
  try {
    const data = await ustarCall(token, wsfn, body);
    return NextResponse.json(data);
  } catch (e) {
    return NextResponse.json(
      { error: e instanceof Error ? e.message : "Moodle API error" },
      { status: 502 }
    );
  }
}
