# Controlled Publishing Canonical Source Layer

This note documents the implementation boundary between the new local canonical source tables and the later controlled publishing/editor layer.

## Phase 1 Tables

The canonical source layer lives in `ipca_courseware` and uses the `ipca_canonical_*` prefix:

- `ipca_canonical_sources` registers upstream systems such as `legacy_ipca_compliance`, EASA, AIM, eCFR, resource library, and internal lesson blueprints.
- `ipca_canonical_documents` represents source editions such as `OM 6.0`, `OMM 4.0`, `MCCF OM REV 6.0`, and `MCCF OMM REV 4.0`.
- `ipca_canonical_requirements` stores local canonical MCCF requirement rows keyed by `requirement_key`.
- `ipca_canonical_excerpts` stores local canonical manual excerpt rows keyed by `excerpt_key`.
- `ipca_canonical_requirement_excerpt_links` preserves MCCF-to-manual coverage links.
- `ipca_canonical_sync_runs` and `ipca_canonical_sync_row_map` audit every legacy sync execution and row-level action.

These tables are source/reference records. They are not book drafts, editor sections, student annotations, or rendered e-reader content.

## Legacy Source Validation

The first legacy sync expects:

- `ipca_compliance.mccf_requirements`: 254 rows, stable key `requirement_key`, primary row id `mccf_row_id`.
- `ipca_compliance.manual_excerpts`: 312 rows, stable key `excerpt_id`.
- `ipca_compliance.mccf_excerpt_links`: 292 rows, stable key `link_id`, relationship keys `requirement_key` and `excerpt_id`.

The sync must validate that every link resolves to both a local requirement and local excerpt before writing links. A count mismatch or missing relationship blocks the run unless the operator explicitly uses the repair/count override flag.

## Phase 2 Sync Rules

The sync script is dry-run first:

- Default execution reads legacy data, compares hashes, validates link integrity, reports actions, and writes sync audit rows.
- `--apply` is required to insert, update, or mark canonical source rows as `missing_from_source`.
- Meaningful fields are normalized and hashed per row so repeated syncs can distinguish unchanged rows from updated rows.
- Rows absent from later legacy syncs are not deleted; they are marked `missing_from_source`.
- Cross-database foreign keys are intentionally avoided. Provenance is captured through source keys, original table names, original PKs, and row maps.

## Later Publishing Layer

The controlled publishing layer should be added after canonical source sync is proven. It should link to canonical source rows, never copy them into book content tables as hidden duplicates.

Recommended linking model:

- A book version should lock its source baseline through a "Statement of Compliance Source" record that points to specific `ipca_canonical_documents` and sync run ids.
- Sections and structured blocks should store editorial content only. Compliance mappings should live in separate link tables.
- Block compliance mappings should support local canonical targets: `requirement_id`, `excerpt_id`, `source_document_id`, plus polymorphic targets for existing EASA/AIM/eCFR/resource-library rows.
- AI inspection findings should reference the book version, block id, canonical target ids, evidence snapshot, prompt/response metadata, and source sync run used during inspection.
- PDF export and e-reader rendering should resolve citations from canonical ids at render time, using locked book-version source baselines for reproducibility.

This preserves SSOT discipline: the editor creates controlled publishing content, while MCCF/manual/regulatory data remains canonical source data with provenance and sync history.
