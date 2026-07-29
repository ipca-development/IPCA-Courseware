# IPCA Aviation Evidence Platform — Phase 1 Schema Draft

Date: 2026-07-29  
Status: **DRAFT — not frozen until Phase 0 runtime investigation completes**

This draft incorporates approved architectural corrections:

- Recorded evidence is primary; transcripts are interpretations  
- **Observations** (raw metrics) remain on originating typed records  
- **Interpretations** are versioned revisions; published outputs are immutable snapshots  
- **Evidence graph** connects typed entities; it does not replace relational fields  
- **Knowledge Packs** (IPCA Aviation Knowledge Engine) are versioned and bound per processing run  
- Correction history is separate from pack knowledge  

---

## Immutable evidence hierarchy

```
Recording
  → Source Audio (hash immutable)
    → Audio Chunk (hash immutable)
      → Provider Run (raw response immutable)
        → Provider Segment / Provider Word (immutable)
          → Canonical Speech Segment (audio-aligned, immutable bounds)
            → Interpretation Revision (append-only chain)
              → Display Block
                → Chapter
                  → Published Transcript Version (snapshot)
                    → Debrief Evidence Reference
```

**Never mutate after creation:** source audio, audio hashes, chunk hashes, provider raw JSON, provider segment text/timestamps, observation values, historical interpretation revisions, published version snapshots.

---

## Observation vs interpretation vs derived confidence

| Kind | Storage | Example |
|------|---------|---------|
| Observation | Typed columns / observation extension rows on source record | `no_speech_probability`, `avg_logprob`, `audio_rms_db`, `vad_coverage_pct` |
| Interpretation | `ipca_evidence_interpretation_revisions` | "airbrakes", "Pattern Work" chapter title |
| Derived confidence | On interpretation revision + graph edge metadata | 0.82 callsign confidence computed from observations + pack match |

Graph **references** observations; it does **not** replace them.

---

## Typed core tables

### Recording & source audio

**`ipca_cockpit_recordings`** (extend existing)

- Add: `source_audio_sha256`, `current_processing_run_id`, `published_transcript_version_id`, `transcript_cache_generated_at`
- `transcript_text` — **legacy cache only** (disposable, regenerated from published version)

### Processing runs

**`ipca_evidence_processing_runs`**

- `id`, `recording_id`, `parent_run_id`, `pass0_context_package_id`
- Per-pass version strings (`merge_algorithm_version`, `speech_quality_version`, `semantic_validation_version`, …)
- `status`, `created_at`, `completed_at`, `created_by`

**`ipca_recording_context_packages`** (Pass 0 output — immutable snapshot)

- `id`, `recording_id`, `context_json`, `context_hash`, `created_at`
- Bound pack list stored separately

**`ipca_knowledge_pack_run_bindings`**

- `processing_run_id`, `knowledge_pack_id`, `knowledge_pack_version_id`, `binding_reason`

### Audio chunks

**`ipca_evidence_audio_chunks`**

- `id`, `recording_id`, `processing_run_id`, `chunk_index`
- `start_time_ms`, `end_time_ms`, `audio_sha256`, `byte_length`, `sample_rate`, `channels`
- Acoustic observations: `rms_db`, `clipping_pct`, `vad_coverage_pct`, `silence_gap_ms` (Pass 1)

### Provider runs (immutable)

**`ipca_evidence_provider_runs`**

- `id`, `audio_chunk_id`, `idempotency_key` UNIQUE
- `provider`, `model`, `request_id`, `response_id`, `raw_response_json` LONGTEXT
- `audio_sha256`, `chunk_index`, `retry_count`, `worker_id`, `http_status`
- `source_audio_duration_ms`, `transcription_duration_ms`

### Provider segments & words (immutable)

**`ipca_evidence_provider_segments`**

- `id`, `provider_run_id`, `provider_segment_index`, `provider_segment_id` (nullable)
- `start_time_ms`, `end_time_ms`, `text`
- Observations: `avg_logprob`, `compression_ratio`, `no_speech_probability`, `temperature` (nullable)

**`ipca_evidence_provider_words`**

- `id`, `provider_segment_id`, `word_index`, `text`, `start_time_ms`, `end_time_ms`, `confidence`

### Canonical speech segments

**`ipca_evidence_speech_segments`**

- `id`, `recording_id`, `processing_run_id`
- `start_time_ms`, `end_time_ms`
- Links: `primary_provider_segment_id`, optional merged-from segment IDs JSON
- Observations duplicated or FK-linked for query performance at segment level

### Interpretation revisions (never overwrite)

**`ipca_evidence_interpretation_revisions`**

- `id`, `speech_segment_id`, `layer` ENUM(raw, merged, aviation, readable, translated, admin_review, …)
- `text`, `revision_number`, `supersedes_interpretation_id`
- `valid_from`, `invalidated_at`, `stale_status`, `recalculation_status`
- Derived: `calculated_confidence`, `confidence_algorithm_version`
- Audit: `created_by` (system/admin user), `created_at`, `reasoning_json`
- Supporting/contradicting node IDs in child table

**`ipca_evidence_interpretation_confidence_factors`**

- `interpretation_revision_id`, `factor_type` (support|weaken), `source_type`, `source_id`, `weight`, `description`

### Display & chapters

**`ipca_evidence_display_blocks`**

- `id`, `recording_id`, `processing_run_id`, `start_time_ms`, `end_time_ms`, `speech_segment_ids_json`

**`ipca_evidence_chapters`**

- `id`, `recording_id`, `processing_run_id`, `title`, `category`, `start_time_ms`, `end_time_ms`
- `calculated_confidence`, `confidence_algorithm_version`, `manually_edited`
- Supporting segment/event IDs JSON

### Published snapshots (historically reproducible)

**`ipca_evidence_published_transcript_versions`**

- `id`, `recording_id`, `processing_run_id`, `published_at`, `published_by`
- `snapshot_json` or normalized snapshot tables
- `interpretation_revision_ids_json` — exact revision set used
- `knowledge_pack_version_ids_json` — exact pack versions used
- `evidence_graph_snapshot_id` — optional frozen graph state for audit

Old published versions remain inspectable; admin corrections create new revisions and **new** published versions — never mutate old snapshots.

### Suppressions & boundary audit

**`ipca_evidence_suppressions`**

- Retained segment ID, suppressed segment ID, reason, boundary/loop/semantic classification

---

## Evidence graph (relationships only)

**`ipca_evidence_graph_edges`**

- `id`, `from_type`, `from_id`, `to_type`, `to_id`, `edge_type`
- Edge types: `DERIVED_FROM`, `SUPPORTED_BY`, `CONTRADICTED_BY`, `NORMALIZES`, `SUPERSEDES`, `ALIGNS_WITH`, `OCCURS_DURING`, `CLASSIFIED_AS`, `GROUPED_IN`, `USED_BY`, `REVIEWED_BY`, `GENERATED_FROM`, `INVALIDATES`
- `metadata_json` (bounded confidence propagation notes)

No generic EAV for timestamps, text, or provider IDs — those live on typed tables.

### Correction cascade (not in-place mutation)

When admin creates a new interpretation revision:

1. Previous revision preserved (`invalidated_at` set if superseded)  
2. Dependent unpublished interpretations marked `stale_status=needs_recalculation`  
3. Background job runs domain-specific recalculators (callsign, chapter, debrief draft)  
4. New derived revisions created; **published snapshots unchanged**  
5. New publish action creates new `published_transcript_version`  

---

## IPCA Aviation Knowledge Engine

### Knowledge packs (reusable approved knowledge)

**`ipca_knowledge_packs`** — slug, title, pack_type, scope  
**`ipca_knowledge_pack_versions`** — version_number, status, published_at  
**`ipca_knowledge_pack_entries`** — canonical term, category, phonetic variants JSON, checklist flows, etc.

### Correction history (separate from packs)

**`ipca_knowledge_correction_evidence`**

- Raw/corrected text, timestamps, audio clip ref, scope selected, reviewer, audio reviewed flag  
- Status: proposed → promotion candidate → approved/rejected  
- Links to recording, speech segment, interpretation revision  

Promotion to pack creates **new pack version** — corrections are not copied blindly into packs.

### Pack precedence (category-dependent)

Precedence is resolved by **`KnowledgePackResolver`** using term category rules:

| Category | Typical precedence (high → low) |
|----------|----------------------------------|
| Callsign | recording aircraft assignment > aircraft pack > org |
| Airport name | airport pack > org > global phraseology |
| Checklist term | aircraft-type pack > aircraft pack > global |
| Instructor phrasing | instructor pack assists matching only; cannot rewrite other speakers |
| Regulatory phraseology | reference only; does not overwrite nonstandard spoken words |

Conflicts → competing candidates + conflict metadata + review queue below auto-apply threshold.

---

## Confidence calculation rules (bounded, explainable)

Each derived interpretation stores:

- `calculated_confidence` (0–1)  
- `confidence_algorithm_version`  
- Supporting / contradicting factor rows  
- `human_reviewed`, `human_review_weight`  
- `recalculation_timestamp`  

Domain-specific calculators (no opaque recursive multiplication):

- `SpeechIntelligibilityConfidence` — acoustic observations  
- `LanguageIdentificationConfidence` — provider language + segment consistency  
- `SourceClassificationConfidence` — radio vs cockpit heuristics  
- `CallsignNormalizationConfidence` — registration + phonetic + pack  
- `ChapterGenerationConfidence` — telemetry + transcript + phase  
- `DebriefEvidenceReliability` — min linked speech intelligibility × review status  

---

## Legacy cache migration

1. Identify all consumers (documented in Phase 0 report)  
2. Add `published_transcript_version_id` + `transcript_cache_generated_at` to recordings  
3. Regenerate `transcript_text` only from published readable snapshot  
4. Block direct writes to `transcript_text` except cache regenerator  
5. Removal criteria: all consumers read published version or evidence API; zero direct writes for 30 days  

---

## Validation gate (Phase 1 complete when)

- [ ] Phase 0 runtime trace appended to investigation doc  
- [ ] Provider segment/word columns match probe results  
- [ ] Immutable provider JSON insert tested on Phase 0 recording  
- [ ] Interpretation revision supersede chain tested  
- [ ] Published snapshot reproducibility tested  
- [ ] Knowledge pack version binding stored on processing run  
- [ ] Legacy cache regenerator provenance fields populated  

**Do not apply migration to production until all boxes checked.**
