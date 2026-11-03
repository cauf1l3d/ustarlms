# Marketplace department access and source files

Execution: inline, no subagents/SSH/provider sends; owner explicitly requested department-controlled visibility.

Goal: hide Marketplace for ordinary staff unless an administrator enables their canonical department; remove employee source-document downloads.

Architecture: existing plugin config stores a department allowlist; structure/content user scope remains canonical. Library access decides tab/direct-offer/purchase rights. Existing grants and mandatory-route access remain independent. Catalog source files are editor assets checked at presenter and pluginfile boundaries; product images remain readable under existing ACL. No schema or version migration.

Owner choice: «полностью скрыт с возможностью открывать по отделам с админки» (03Nov). Defaults: no enabled departments; site/Academy admin can configure; exact departments only, no implicit subtree. Sales setting remains separate. Existing offers/grants/attempts/coins are not rewritten. Future seed creates reviewable draft/assigned offers, not free published entries.

- [x] Test closed default, exact allowed/foreign/missing/deleted department, user transfer, admin and view-as, settings actor/revision/unknown ID, hidden storefront and purchase, owned grants.
- [x] Implement access/config writer, management department selection, hidden tabs and direct-entry guards; default seeds to draft/assigned.
- [x] Test and remove product-source download UI and reject direct pluginfile URLs for non-editors; retain images and editor source access.
- [ ] Run PHP contracts/native CI/browser, context checks. Build immutable source-only successor on installed f89dde49/0200 with existing lock/recovery mechanics and cache purge, no schema/business-data mutation.
- [ ] Verify CI artifact SHA/CRC/byte identity and saved copy; document precise owner commands and acceptance. Keep PR114 open.
