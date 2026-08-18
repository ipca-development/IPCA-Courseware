# SMS assurance analytics and AI governance

## Assurance measures

Safety performance indicators (SPIs) are defined as a whitelisted event numerator divided by an immutable exposure snapshot. Supported denominators are flight hours, flight cycles, sectors, training hours, and operations. Each stored value retains its numerator and denominator so reviewers can reproduce the rate and identify missing exposure.

The summary API may show raw status totals for context, but its assurance output is the rate per 1,000 units of each available exposure. A missing or zero denominator is never converted into a rate.

Exposure snapshots record:

- period and unit;
- positive exposure value;
- source system and reference plus a source digest;
- de-identified dimensions and provenance;
- the user and time that recorded the snapshot.

Snapshots are append-only evidence. Corrected source data should produce a new snapshot; deployments should not update historical evidence in place.

## Controlled cross-domain analysis

Cross-domain analysis cannot query or join operational source tables directly. It uses:

1. a de-identified point-in-time snapshot containing finite numeric metrics;
2. a digest of the external subject reference, not the reference itself;
3. an explicit link to a persisted SMS subject;
4. a relationship rationale and human approver.

Correlation screening currently compares a selected external metric with the latest linked hazard risk score. It suppresses samples below five linked pairs and labels every result as exploratory and non-causal. Correlation candidates cannot accept risk or change a hazard, report, investigation, or action.

## AI assistance boundary

AI is optional per organization and disabled by default. The only allowed use cases are:

- taxonomy suggestions;
- duplicate candidates;
- summaries;
- trend candidates;
- missing-field prompts.

Provider-bound input is deterministically de-identified. Identity-bearing keys are removed and common email, telephone, and network-address patterns are redacted. Reporter-vault material is never loaded by the service. The caller receives the exact de-identified payload and SHA-256 digest to send to its configured provider; the governance service itself does not make a provider call.

Every run records organization, subject, requester, use case, provider, model, prompt-template version, input digest, de-identification version, and provenance. Completion records the output, output digest, and provider provenance. The output remains `awaiting_review` until a user with `ai.review` records a decision bound to that output digest.

AI output is advisory only. It cannot decide or recommend fields representing:

- blame, culpability, or a just-culture determination;
- reportability;
- risk acceptance;
- action approval;
- report or action closure.

Attempting to submit those decision fields causes the output to be rejected. Accepting an output as advisory does not apply it to any SMS record; a human must separately perform any authorized workflow operation through its domain service.

## API surface

Authenticated endpoints:

- `GET|POST /api/safety/analytics.php` for normalized summaries, exposure snapshots, SPI definition/computation, trend candidates, and controlled correlation evidence.
- `GET|POST /api/safety/ai-assistance.php` for preparing a run, recording provider output, viewing provenance, and human review.

Both endpoints use organization-scoped role checks and no-store responses. `safety_manager` can manage assurance evidence and AI review. `safety_analyst` can read assurance results and prepare de-identified AI assistance, but cannot approve links, record exposure, compute official SPI values, or review AI output.
