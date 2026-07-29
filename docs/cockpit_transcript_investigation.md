# Cockpit Transcript Phase 0 Investigation — Final Report

Date: 2026-07-29  
Status: **Complete**  
Platform: IPCA Aviation Evidence Platform  
Recording: **552** (`CD71C6F8-2329-45CC-B48F-A371BFAFB58D`, chunk 0)

Structured artifact: [`cockpit_transcript_investigation.json`](cockpit_transcript_investigation.json)

---

## Evidence files (App Platform probe 2026-07-29 22:35:11 UTC)

| File | Purpose |
|------|---------|
| [`storage/cockpit_recorder/phase0_evidence/recording_552_chunk_0_20260729_223511_report.json`](../storage/cockpit_recorder/phase0_evidence/recording_552_chunk_0_20260729_223511_report.json) | Full probe report |
| [`storage/cockpit_recorder/phase0_evidence/recording_552_chunk_0_20260729_223511_provider_raw.json`](../storage/cockpit_recorder/phase0_evidence/recording_552_chunk_0_20260729_223511_provider_raw.json) | Primary raw provider JSON |
| [`storage/cockpit_recorder/phase0_evidence/recording_552_chunk_0_20260729_223511_production_json_raw.json`](../storage/cockpit_recorder/phase0_evidence/recording_552_chunk_0_20260729_223511_production_json_raw.json) | gpt-4o-transcribe `json` response |
| [`storage/cockpit_recorder/phase0_evidence/recording_552_chunk_0_20260729_223511_production_verbose_json_raw.json`](../storage/cockpit_recorder/phase0_evidence/recording_552_chunk_0_20260729_223511_production_verbose_json_raw.json) | gpt-4o-transcribe `verbose_json` rejection |
| [`storage/cockpit_recorder/phase0_evidence/recording_552_chunk_0_20260729_223511_whisper1_verbose_json_raw.json`](../storage/cockpit_recorder/phase0_evidence/recording_552_chunk_0_20260729_223511_whisper1_verbose_json_raw.json) | whisper-1 `verbose_json` response |

---

## 1. Historical Snowball loop

### Observations (surviving evidence)

| Metric | Value |
|--------|-------|
| Snowball traffic (stored chunk 0) | 45 |
| Night 3 Yankee (stored chunk 0) | 43 |
| Structure | A/B repetition |
| Scope | chunk 0 only |
| Merge simulation | Did not increase count |
| Global cleanup | Reduced visible count (masked evidence) |

### Conclusion

The historical Snowball/Night 3 Yankee repetition loop was already present in the stored per-chunk transcription before transcript merge and display cleanup. The original immutable provider response for that historical run was not retained, so its exact first provider-level appearance cannot be directly proven.

**Do not claim** the untouched historical provider response has been directly inspected.

---

## 2. Fresh production-model probe (2026-07-29)

A fresh transcription of the same chunk using the production request and **gpt-4o-transcribe** produced a **different** repetition loop in the untouched raw provider response.

The new raw provider response repeatedly generated variants of:

- “PFD check”
- “Turn gas on”

Including rearranged fragments:

- “PFD check. Turn gas on.”
- “PFD check. PFD check. Turn gas on.”
- “Turn gas on. PFD check.”
- repeated single fragments

### Conclusions (proven)

- gpt-4o-transcribe **can** generate repetition loops directly in raw provider output for this audio
- The existing prompt instruction not to repeat **does not** prevent looping
- Transcription output is **stochastic**
- Retranscription does **not** recreate historical output reliably
- Every provider run must be preserved as **immutable evidence**
- Loop detection is **mandatory before publication**

**Do not state** that the fresh probe reproduced the Snowball loop. It did not.

---

## 3. Three-way comparison (corrected)

Two separate provider runs must not be compared as layers of the same run.

### Fresh probe run (2026-07-29)

| Layer | Snowball count |
|-------|----------------|
| Raw provider text | 0 |
| Post-`cleanTranscriptText()` | 0 |
| Loop present | PFD check / Turn gas on (phrase-cycle) |

### Historical transcription run (original production)

| Layer | Snowball count |
|-------|----------------|
| Stored chunk table | 45 |
| Original immutable provider response | **Unavailable** |
| First layer with 40+ Snowball in surviving evidence | `stored_chunk_table` |

**Do not** assert direct equality or transformation between fresh raw text and stored historical chunk. They belong to different provider runs.

---

## 4. Provider capability — gpt-4o-transcribe (production request)

### Supported

- `response_format=json`
- Text output
- Prompt context
- Forced language parameter
- Token usage metadata
- OpenAI request ID (response header)

### Not returned

- Detected language
- Audio duration
- Task type
- Provider segments
- Segment timestamps
- Word timestamps
- no-speech probability
- Average log probability
- Compression ratio
- Word confidence

### Rejected

- `response_format=verbose_json`
- Timestamp granularities with verbose_json

HTTP 400:

> response_format 'verbose_json' is not compatible with model 'gpt-4o-transcribe-api-ev3'. Use 'json' or 'text' instead.

**Schema rule:** Do not require unsupported fields for gpt-4o-transcribe.

---

## 5. Provider capability — whisper-1 (verbose_json)

### Returned

- Text, language, duration, task
- 71 segments with segment IDs, seek values, start/end timestamps
- temperature, avg_logprob, compression_ratio, no_speech_prob
- Duration-based usage metadata

### Word timestamps

Word timestamp count was **zero** despite requesting word and segment granularities.

Record word timestamps as **unconfirmed / unavailable** for the tested request. Do not assume they exist.

---

## 6. Canonical timeline model (frozen architecture)

| Role | Initial configuration |
|------|----------------------|
| Timestamped evidence model | **whisper-1** |
| Optional secondary text hypothesis | **gpt-4o-transcribe** |
| Canonical speech timeline | Whisper segment timestamps |
| Readable text | Derived interpretation (may compare both hypotheses) |
| Every provider run | Immutable raw JSON retained |

Do **not** use gpt-4o-transcribe as canonical timestamp source while it returns only one untimestamped text field.

---

## 7. Pass 4B repetition detector design

Must detect (not Snowball-specific):

- Exact repeated phrases
- Repeated n-grams
- A/A loops, A/B loops, phrase-cycle loops
- Low lexical diversity, repeated-token dominance
- Abnormal compression ratio
- Excessive output during low-speech intervals
- Repetition concentrated near chunk end

Priority evidence order:

1. Repeated provider segment/word timestamps (when available)
2. Text during VAD-classified silence
3. Very low lexical diversity
4. Abnormal compression ratio
5. Repeated A/B n-gram cycles
6. Word count vs speech duration mismatch
7. Low average log probability
8. High no-speech probability

---

## 8. Speech-quality alignment (Pass 4A + 4B)

Use Whisper segment timestamps and quality metrics to determine:

- Whether loops overlap silence
- Whether no-speech probability rises before/during repeated regions
- Whether avg logprob falls
- Whether compression ratio becomes abnormal
- Whether final segments contain repeated text unsupported by speech

Suppress from readable layer only via traceable interpretation/suppression records. **Preserve raw provider response.**

---

## 9. Encyclopedia hallucinations

**Conclusion:**

The source of the encyclopedia-style passages cannot be proven because the previous processing pipeline did not preserve immutable raw provider responses. No exact representative phrases remain in current recording transcripts, chunk rows, or transcript snapshots.

Do **not** attribute those passages definitively to OpenAI, translation, or cleanup.

---

## 10. Phase 0 completion

| Criterion | Status |
|-----------|--------|
| Raw provider response captured (fresh probe) | Complete |
| Historical loop documented with surviving evidence limits | Complete |
| Provider capabilities documented per model | Complete |
| Encyclopedia provenance formalized | Complete |
| Observations vs inferences separated | Complete |
| Phase 1 schema frozen | Complete — see [`cockpit_evidence_platform_phase1_schema.md`](cockpit_evidence_platform_phase1_schema.md) |

---

## Legacy pipeline gaps (code + schema)

See Section 35 checklist in prior revisions. Phase 1 migration addresses immutable provider runs, segment storage, idempotency, and published snapshots.

Migration: [`scripts/sql/2026_07_30_aviation_evidence_platform.sql`](../scripts/sql/2026_07_30_aviation_evidence_platform.sql)
