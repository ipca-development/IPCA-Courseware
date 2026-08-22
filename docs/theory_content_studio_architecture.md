# Theory Content Studio — Phase 1 Architecture

## Hard safety rule

The current production Theory Training system is an **immutable legacy runtime** from the perspective of Theory Content Studio Phase 1.

Studio is built **beside** it, not **through** it.

Phase 1 must not alter existing Live Programs, Courses, Lessons, Slides, enrichment, question banks, Maya blueprints, screenshot/media paths, ordering, `program_key`, `external_lesson_id`, `is_published`, `courses.revision`, cohort relationships, lesson deadlines, or student progress.

This is not a UI warning. There is no “edit Live with a banner” compromise.

Live SHOW COLUMNS could not be run in the implementation environment (DB env vars were not present). Column contracts below are reconstructed from production PHP writes and joins. Apply the migration only after a production `SHOW COLUMNS` / index inspection confirms the additive statements are still valid. If they are not, stop and document the blocker.

## Operational truth

Canonical student hierarchy remains:

`programs` → `courses` → `lessons` → `slides`

Cohorts attach to `programs.id`. Students resolve lessons through `cohort_lesson_deadlines.lesson_id`, not through revision tables.

Existing student-facing queries must not join `theory_program_revisions` and must not use `program_revision_id`.

`courses.is_published` is not a student visibility gate. Isolation of Studio Draft content does not rely on it.

## Content model

**Existing Live Programs (Private Pilot and Instrument Rating FAA) are screenshot/image-only.** `slides.image_path` is the visual source. Canonical text, narration, references, and video hotspots were derived later by OCR/enrichment. Studio may inspect this graph. It must not rewrite it.

**New Studio courses are authored as text, images, and video.** Structured fields will be canonical. Do not run OCR on native Studio slides. Do not require Kings `external_lesson_id` or `ks_images/...` paths.

Slide source categories (documented now; not stamped onto existing Live rows):

| Category | Meaning | Phase 1 |
|---|---|---|
| Legacy Screenshot | Imported page image remains the visual source | Read-only in Studio |
| Structured | Native template fields: text, image, video | UI shell only; **no `slides` INSERT** |
| Imported Reconstructed | Keynote/PowerPoint reconstruction | Phase 2 |

## Revision model

Keep `programs` as the stable curriculum identity (`id` + `program_key`).

Add `theory_program_revisions` as **metadata only**:

- One Live pointer per pre-existing program (`origin='legacy'`, `status='live'`).
- New Studio programs get a new `programs` row with `authoring_origin='studio'` and a Draft revision (`origin='studio'`, `status='draft'`).
- Do not create a Draft revision under an existing Live `program_id` in Phase 1.
- Do not backfill `courses.program_revision_id` on existing rows. That column is omitted in Phase 1 so no production query can start depending on it.
- Do not add `cohorts.program_revision_id`.
- Do not enable Publish → Live.

`program_key` is immutable after create. Validate `^[a-z][a-z0-9_]{1,63}$` and uniqueness.

## Native lessons and `external_lesson_id`

Legacy import identity remains `(course_id, external_lesson_id)`.

Studio Draft lessons must not mint fake Kings IDs onto Live lessons. The migration makes `lessons.external_lesson_id` nullable if it is currently NOT NULL. Existing non-null values are not updated. MySQL UNIQUE indexes still enforce uniqueness among non-null values and allow multiple NULLs. Studio INSERT uses NULL. If production inspection shows this ALTER would change uniqueness for existing non-null rows, do not execute it.

## Studio write protection

`TheoryContentStudioService` is the only Studio mutation path.

Before every mutation, ancestry is loaded from the database. Client-supplied status is ignored.

- Protected = `programs.authoring_origin <> 'studio'` (legacy/operational) **or** any cohort already references the program.
- Protected mutations return `LIVE_CONTENT_PROTECTED`.
- Attaching a Studio Draft program to a cohort returns `STUDIO_DRAFT_NOT_OPERATIONAL`.
- Slide create/reorder/import through Studio always fails in Phase 1 (`STRUCTURED_SLIDES_NOT_ENABLED`).
- Status transition to Live always fails (`PUBLISHING_DISABLED`).

Failed mutations must leave existing rows unchanged.

## Draft Content Isolation / Leakage Audit

Question for every path: if Studio inserts a new Draft Program/Course/Lesson into the shared tables today, can this path see or act on those rows?

### Student / player / progression — do not modify

| Path | Selects | Draft visible? | Safe? | Mitigation |
|---|---|---|---|---|
| `public/student/course.php` | Cohort + `cohort_lesson_deadlines` | No, unless a deadline is created | Yes if cohort assignment is blocked | Unchanged |
| `public/student/courses.php` | `cohort_students` → cohorts | No | Yes | Unchanged |
| `public/student/dashboard.php` | Cohort joins | No | Yes | Unchanged |
| `src/navigation.php` | Cohort programs | No | Yes | Unchanged |
| `public/player/slide.php` | Slide by id; student requires a deadline for that lesson | Direct URL without deadline → 403 | Yes | Unchanged |
| `src/courseware_progression_v2.php` | Deadlines | No | Yes | Unchanged |
| `src/time_based_progression_cron.php` | Deadlines | No | Yes | Unchanged |
| `public/api/` | No theory program/course enumerations found | No | Yes | Unchanged |

### Operational selectors — isolate before enabling Studio create

| Path | Selects | Draft visible without filter? | Safe? | Mitigation |
|---|---|---|---|---|
| `public/admin/cohorts.php` | All `programs`; create_cohort attaches all courses | Yes | No | `authoring_origin='operational'` list + POST reject |
| `public/admin/cohort.php` | All `programs`; courses by `program_id` | Yes | No | Same |
| `src/schedule.php` fallback | All courses for a cohort’s `program_id` | Only after assignment | No if assignment leaks | Cohort gate is primary; fallback unchanged |
| `public/admin/written_test.php` | All courses for related_course_id | Yes | No | Exclude studio-origin programs |
| `src/communication/CommunicationTrainingVideoService.php` | All programs for grant targets | Yes | No | Exclude studio-origin programs |
| `public/admin/bulk_enrich.php` | All programs | Yes | No as a batch job | Exclude studio-origin programs |
| `public/admin/lesson_summary_blueprints.php` | All programs | Yes | No | Exclude studio-origin programs |
| `src/progress_test_bank.php` | By explicit program/course/lesson id | If that id is passed | No auto enumeration | Selector filter; Studio question UI is read-only and does not call `pt_bank_get_or_create_bank` |
| `public/admin/import_lab.php` | Lookup by typed `program_key` | Only if that key is used | No if used | Reject studio program keys |
| `public/admin/dashboard.php` | `COUNT(*) FROM courses` | Count includes drafts | KPI drift only | Documented; not used for assignment |
| `public/admin/courses.php` / `lessons.php` / `slides.php` | All rows | Yes | Maintenance/debug | Documented; not used for assignment |

Isolation column: `programs.authoring_origin ENUM('operational','studio') NOT NULL DEFAULT 'operational'`. Existing rows receive `operational` via the column default. Queries that do not select the column are unchanged. Operational selectors add a predicate that is a no-op on current Live rows.

## Structured slides

Do not INSERT structured placeholder rows in Phase 1. OCR (`public/admin/api/ocr_slide.php`), bulk enrich, video manifests, and the screenshot player assume `image_path` and often `external_lesson_id`. `+ Add Slide` is visible and disabled.

Phase 2 separates three authorities:

1. **Theory Template Version** — immutable 1600×900 geometry, semantic Text/Image/Video placeholders, reading order, presentation defaults, and author permissions.
2. **Structured Slide instance** — exact Template Version reference, machine-readable Course outline node, localized Text values, and language-neutral managed media values.
3. **Canonical projection** — deterministic `slide_content` EN/ES rows derived from Text placeholders in Template reading order. `slide_content` is not the authority for visual geometry.

Templates have stable identities and append-only versions. Editing a Template creates a newer version; existing Slides remain pinned to the version they were authored with. Slide authors edit values, not structural geometry, unless an individual placeholder explicitly permits it. Template authors use a fixed-canvas geometry mode. Both modes may reuse Manual/Annex toolbar, rich-text, table, callout, link, media, and undo/redo components through Theory-specific adapters; they do not share the Manual/Annex persistence model.

Native structured media references managed Spaces assets. Structured JSON never contains blobs, base64, or arbitrary client-supplied storage paths. OCR and legacy screenshot mutation APIs reject `source_category='structured'`.

## Phase 2

Create Draft from Live (copy-on-write, new IDs). Atomic publish. Cohort attachment to a published revision. Version-safe structured Slide and Template Editors as defined above. Keynote/PowerPoint reconstruction.

## Completion answers (expected)

| Question | Expected |
|---|---|
| Existing production rows changed by Studio/migration (other than additive metadata columns/tables)? | No |
| Existing student-facing queries changed? | No |
| Existing course/lesson/slide IDs changed? | No |
| Cohort relationships changed? | No |
| Ordering changed? | No |
| Existing media paths changed? | No |
| Enrichment records changed? | No |
| Newly created Studio Draft Program visible to a student? | No |
| Assignable to a cohort through an existing operational workflow? | No |
| Courses picked up automatically by legacy processing? | No |
| Lessons receive deadlines automatically? | No |
| Structured Draft Slide processed by OCR/screenshot tooling? | No (no such rows) |
| Mobile/API consumer encounters Draft content? | No |
