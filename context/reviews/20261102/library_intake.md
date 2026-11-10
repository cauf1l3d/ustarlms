# Library intake review —2026-11-02

Scope: all three supplied ZIPs contain the same40-file tar (SHA256752883b26619e71b51e1be3835b7bdf881f2e67a9f88eb79e486cef3921ca97d). Outer source copies match the inner tar. No supplied script was executed. Baseline owner accepted app2150affe/1100 and confirmed native Quiz configuration now works.

Confirmed blockers from source inspection:

1. db/upgrade.php.fragment uses2026110100, already installed for route access. The four new tables would not be created by that step.
2. library_access::resolve_many returns allow immediately for a grant, bypassing employment, publication and subject ACL; resolve and resolve_many disagree.
3. resolve treats zero-price gated offers as free before checking gatepointid.
4. library_grant::assign relies on caller org-scope checking; library.php does not perform it. Public issue also accepts arbitrary sources/ledger IDs without actor validation.
5. library_store has rollback(exception) branches followed by unreachable result returns. Moodle rollback throws, so normal business refusals do not follow its advertised return contract.
6. library.php never handles its offer open parameter; shop links return to the same page. The My materials tab sends every content type to material_player.php, which only supports Studio blueprints, and drops feed saves and purchased/assigned material rows.
7. Catalog child URLs always use parent=, including leaf products; no product detail body is rendered. Its canopen result is ignored.
8. library_check.php starts an attempt on GET, including view-as. The domain start/submit lack offer access checks and locking; submit does not re-check eligibility/publication. Grading uses the current definition threshold rather than the attempt snapshot.
9. library_quick_sync references undefined library_assessment::TARGET_QUICK; input/definition schema fragments are inconsistent. The claimed tests were not executed in the source environment.
10. Native/body/file routes do not enforce new paid grants: a storefront lock alone does not protect already accessible content. No unified rail or complete redirects are integrated.
11. Supplied deploy paths omit public/, use recursive chown33 on protected code, require manual schema fragments and reuse an installed version. These commands cannot be used on this server.

Disposition: integrate with corrections in a successor release; keep original attachments and frozen operators unchanged. The owner requests independent review and forbids subagents/SSH/provider sends; remote CodeRabbit upload is outside that scope. Local code review and native CI are the validation route, never described as CodeRabbit results.
