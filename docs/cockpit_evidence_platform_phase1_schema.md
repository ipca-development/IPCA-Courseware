# IPCA Aviation Evidence Platform — Phase 1 Schema (FROZEN)

Date: 2026-07-29  
Status: **FROZEN** after Phase 0 completion  
Investigation: [`cockpit_transcript_investigation.md`](cockpit_transcript_investigation.md)  
Migration: [`scripts/sql/2026_07_30_aviation_evidence_platform.sql`](../scripts/sql/2026_07_30_aviation_evidence_platform.sql)

---

## Phase 0 decisions driving this schema

1. **gpt-4o-transcribe** returns only untimestamped `json` text — not canonical timeline source  
2. **whisper-1** + `verbose_json` returns segment timestamps and quality metrics — canonical timeline source  
3. Provider output is **stochastic** — multiple immutable provider runs per chunk required  
4. **Nullable** observation fields only — never require metrics a model does not return  
5. Full **raw_response_json** preserved for every run  
6. Historical vs fresh probe runs are **distinct** — no overwriting prior provider evidence  

---

## Canonical timeline configuration

| Setting | Value |
|---------|-------|
| `CW_EVIDENCE_CANONICAL_ASR_MODEL` | `whisper-1` (default) |
| `CW_EVIDENCE_SECONDARY_ASR_MODEL` | `gpt-4o-transcribe` (optional hypothesis) |
| Canonical speech timeline | Whisper segment timestamps |
| Readable layer | Derived interpretation (may fuse/compare hypotheses) |

Stored per processing run on `ipca_evidence_processing_runs.canonical_timeline_source`.

---

## Provider run idempotency model

| Scenario | Key / behavior |
|----------|----------------|
| Accidental retry within same run | Same `idempotency_key` → upsert/no-op |
| Deliberate re-probe same chunk | New row, new `provider_run_uuid`, `run_purpose=deliberate_reprobe` |
| Different model/provider | New row, distinct `request_config_hash` |
| Reprocess new prompt/version | New row, `run_purpose=reprocess_config_change` |

`idempotency_key` = hash(recording_id, processing_run_id, chunk_index, audio_sha256, run_purpose, request_config_hash)

---

## Table inventory

| Table | Role |
|-------|------|
| `ipca_provider_model_capabilities` | Supported response formats, timestamp flags per model |
| `ipca_evidence_processing_runs` | Pipeline run versioning |
| `ipca_recording_context_packages` | Pass 0 immutable context |
| `ipca_knowledge_packs` / `_versions` / `_entries` | IPCA Aviation Knowledge Engine |
| `ipca_knowledge_pack_run_bindings` | Pack versions used per run |
| `ipca_knowledge_correction_evidence` | Corrections separate from pack knowledge |
| `ipca_evidence_audio_chunks` | Immutable chunk audio metadata + Pass 1 observations |
| `ipca_evidence_provider_runs` | Immutable raw JSON + request config |
| `ipca_evidence_provider_segments` | Typed segments, nullable observations |
| `ipca_evidence_provider_words` | Optional words (nullable/unconfirmed) |
| `ipca_evidence_speech_segments` | Canonical audio-aligned segments |
| `ipca_evidence_interpretation_revisions` | Append-only interpretation layers |
| `ipca_evidence_interpretation_confidence_factors` | Explainable derived confidence |
| `ipca_evidence_graph_edges` | Typed entity relationships |
| `ipca_evidence_suppressions` | Traceable readable-layer suppressions |
| `ipca_evidence_published_transcript_versions` | Immutable publish snapshots |
| `ipca_evidence_display_blocks` | UI grouping |
| `ipca_evidence_chapters` | Flight outline |

Extended: `ipca_cockpit_recordings` (legacy cache fields, processing run FK)

---

## Observation vs interpretation

- **Observations** live on provider segments/runs/chunks (nullable columns)  
- **Interpretations** live in `ipca_evidence_interpretation_revisions`  
- **Published** snapshots reference exact interpretation revision IDs used  

---

## Legacy cache

`ipca_cockpit_recordings.transcript_text` remains disposable cache with:

- `published_transcript_version_id`
- `transcript_cache_generated_at`

No direct writes except cache regenerator.

---

## Validation gate (Phase 1 implementation complete when)

- [ ] Migration applies cleanly on production
- [ ] Provider run insert preserves full JSON from Phase 0 probe path
- [ ] Multiple provider runs can attach to same audio chunk
- [ ] Whisper segments populate nullable observation columns
- [ ] gpt-4o-transcribe run stores text-only with capability flags
- [ ] Idempotency key prevents duplicate retry append
