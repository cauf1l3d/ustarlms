# Studio SCORM compatibility implementation plan

Execution is inline by the current agent; no subagents, SSH, provider sends or merge.
User requests editing existing route SCORM and the attached exported archive.

Goal: import static HTML SCORM into the existing editable course model and let authors
open archived learning sources as separate drafts without altering original attempts.

Architecture: keep course_studio/material/FileAPI, existing author guards, source token,
hierarchy lock, shared draft and publication writers. No schema or second content store.
Existing embedded USTAR projects retain exact roundtrip. Legacy source scripts never run
in the editor; its static content becomes ordinary editable lesson screens.

Evidence: user outer ZIP contains one SCORM ZIP; inner SHA256
9b51de6e6408ffd96e7e4f38c5542ad14cd35d17364e229b6f13c6da2cb33e73,
20 static .page sections and82 image occurrences. Current importer accepts only root
editor.json/data.js or scorm/*.zip. supported()/record() also reject archived content.
The original user archive stays outside Git; CI uses synthetic non-private fixtures.

- [x] Add failing JS regression for root/nested/folder SCORM, manifest-ordered HTML,
  section and image preservation, editable export roundtrip, inert script handling,
  unsafe archive/resource paths, missing images and bounded recursion/expansion.
- [x] Implement bounded static HTML import in existing model.js, preserving safe
  declarative formatting and local assets. Retain embedded USTAR import behaviour.
- [x] Add native regressions for archived author copy, learner refusal, Moodle SCORM
  package source and unchanged original publication/attempts; implement source bridge.
- [x] Bust browser asset cache from model content; update actual editor help and guide.
- [ ] Verify supplied archive locally (20 screens/82 images, text/asset preservation,
  validation, re-export), full applicable JS/PHP/native/browser CI and self-review.
- [ ] Publish exact application and separate guarded source-only successor on installed
  app4715383b/tool59f, preserving all prior journals/worker/schema/business data.
- [ ] Verify CI archive and saved readback, document owner commands and current context.

Review focus: no empty apparent import of script-only proprietary packages; no external
fetch/execution; multiple SCO ordering and duplicate references; resource paths relative
to the HTML file; archived source remains archived and original attempts are untouched.
