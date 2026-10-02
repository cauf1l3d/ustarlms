# Registered USTAR external functions

Source: `e1d57f5bc28f6964afd20aeff7620345b34a80fe`. Git source only; not a live database snapshot.


Source: `moodle/local/ustar/db/services.php`. Service: `ustar_workspace`. Registration does not prove coverage of current native pages or production enablement.

| Function | Type | Class | Capability declaration |
|---|---|---|---|
| `local_ustar_get_workspace` | read | `local_ustar\external\get_workspace` | `local/ustar:use` |
| `local_ustar_get_dashboard` | read | `local_ustar\external\get_dashboard` | `local/ustar:use` |
| `local_ustar_get_skills` | read | `local_ustar\external\get_skills` | `local/ustar:use` |
| `local_ustar_get_matrix` | read | `local_ustar\external\get_matrix` | `local/ustar:use` |
| `local_ustar_get_ladder` | read | `local_ustar\external\get_ladder` | `local/ustar:use` |
| `local_ustar_get_team` | read | `local_ustar\external\get_team` | `local/ustar:use` |
| `local_ustar_get_games` | read | `local_ustar\external\get_games` | `local/ustar:use` |
| `local_ustar_get_game_question` | read | `local_ustar\external\get_game_question` | `local/ustar:use` |
| `local_ustar_submit_game_answer` | write | `local_ustar\external\submit_game_answer` | `local/ustar:use` |
| `local_ustar_save_prefs` | write | `local_ustar\external\save_prefs` | `local/ustar:use` |
| `local_ustar_save_goal` | write | `local_ustar\external\save_goal` | `local/ustar:use` |
| `local_ustar_get_checklists` | read | `local_ustar\external\get_checklists` | `local/ustar:use` |
| `local_ustar_submit_checklist` | write | `local_ustar\external\submit_checklist` | `local/ustar:use` |
| `local_ustar_hr_get_workspace` | read | `local_ustar\external\hr_get_workspace` | `local/ustar:hr` |
| `local_ustar_hr_bulk_assign_positions` | write | `local_ustar\external\hr_bulk_assign_positions` | `local/ustar:hrmanage` |
| `local_ustar_hr_get_dashboard` | read | `local_ustar\external\hr_get_dashboard` | `local/ustar:hr` |
| `local_ustar_hr_get_people` | read | `local_ustar\external\hr_get_people` | `local/ustar:hr` |
| `local_ustar_hr_get_person` | read | `local_ustar\external\hr_get_person` | `local/ustar:hr` |
| `local_ustar_hr_save_person` | write | `local_ustar\external\hr_save_person` | `local/ustar:hrmanage` |
| `local_ustar_hr_save_review` | write | `local_ustar\external\hr_save_review` | `local/ustar:hrmanage` |
| `local_ustar_hr_import_people` | write | `local_ustar\external\hr_import_people` | `local/ustar:hrmanage` |
| `local_ustar_hr_get_checklists` | read | `local_ustar\external\hr_get_checklists` | `local/ustar:hrmanage` |
| `local_ustar_hr_save_checklists` | write | `local_ustar\external\hr_save_checklists` | `local/ustar:hrmanage` |
| `local_ustar_hr_save_learning` | write | `local_ustar\external\hr_save_learning` | `local/ustar:hrmanage` |
| `local_ustar_executive_get_dashboard` | read | `local_ustar\external\executive_get_dashboard` | `local/ustar:executive` |
| `local_ustar_admin_get_structure` | read | `local_ustar\external\admin_get_structure` | `local/ustar:admin` |
| `local_ustar_admin_save_structure` | write | `local_ustar\external\admin_save_structure` | `local/ustar:admin` |
| `local_ustar_admin_upload_brand_asset` | write | `local_ustar\external\admin_upload_brand_asset` | `local/ustar:admin` |
| `local_ustar_admin_get_games` | read | `local_ustar\external\admin_get_games` | `local/ustar:admin` |
| `local_ustar_admin_save_game` | write | `local_ustar\external\admin_save_game` | `local/ustar:admin` |
