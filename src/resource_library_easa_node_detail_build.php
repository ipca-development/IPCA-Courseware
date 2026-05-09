<?php
declare(strict_types=1);

/**
 * Build the same node_detail payload as GET ?action=node_detail (single source of truth for detail rows).
 *
 * @return array{ok: true, node: array<string, mixed>}|array{ok: false, error: string, http: int}
 */
function rl_easa_api_node_detail_build(PDO $pdo, int $batchId, string $requestedNodeUid): array
{
    $syntheticBlockIndex = null;
    $synParsed = easa_erules_tree_parse_synthetic_block_node_uid($requestedNodeUid);
    if ($synParsed !== null) {
        $detailLoadUid = $synParsed['parent'];
        $syntheticBlockIndex = $synParsed['block_index'];
    } else {
        $detailLoadUid = easa_erules_staging_anonymous_supplement_bundle_primary_topic_uid($pdo, $batchId, $requestedNodeUid)
            ?? $requestedNodeUid;
    }
    $row = null;
    try {
        $detailCols = [
            'batch_id', 'node_uid', 'parent_node_uid', 'node_type', 'depth', 'sort_order',
            'source_erules_id', 'title', 'source_title', 'breadcrumb', 'path',
            'plain_text',
        ];
        if (easa_erules_staging_has_canonical_column($pdo)) {
            $detailCols[] = 'canonical_text';
        }
        if (easa_erules_staging_has_structured_blocks_column($pdo)) {
            $detailCols[] = 'structured_blocks_json';
        }
        $detailCols[] = 'xml_fragment';
        $detailCols[] = 'metadata_json';
        $detailSql = '
                SELECT ' . implode(', ', $detailCols) . '
                FROM easa_erules_import_nodes_staging
                WHERE batch_id = ? AND node_uid = ?
                LIMIT 1
            ';
        $st = $pdo->prepare($detailSql);
        $st->execute([$batchId, $detailLoadUid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'http' => 503];
    }
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'Node not found', 'http' => 404];
    }
    if ($detailLoadUid !== $requestedNodeUid) {
        $row['requested_node_uid'] = $requestedNodeUid;
        $row['effective_node_uid'] = $detailLoadUid;
    }
    $structuredBlocksDecoded = null;
    if (easa_erules_staging_has_structured_blocks_column($pdo)) {
        $sbRaw = isset($row['structured_blocks_json']) ? trim((string) $row['structured_blocks_json']) : '';
        if ($sbRaw !== '') {
            $dec = json_decode($sbRaw, true);
            if (is_array($dec) && $dec !== []) {
                $structuredBlocksDecoded = $dec;
            }
        }
        unset($row['structured_blocks_json']);
    }
    if ($structuredBlocksDecoded === null) {
        $fragForBlocks = trim((string) ($row['xml_fragment'] ?? ''));
        if ($fragForBlocks !== '' && strlen($fragForBlocks) < 600000) {
            $gen = easa_erules_structured_blocks_json_from_outer_xml($fragForBlocks);
            $dec2 = json_decode($gen, true);
            if (is_array($dec2) && $dec2 !== []) {
                $structuredBlocksDecoded = $dec2;
            }
        }
    }
    /** Mirror tree enrichment: appendix AMC/GM wrappers sometimes store labels only on children. */
    $fcLabelSlice = easa_erules_staging_first_direct_child_label_fallback($pdo, $batchId, $detailLoadUid);
    $treeLabelRow = $row;
    if ($fcLabelSlice !== null) {
        $treeLabelRow['first_child_title'] = trim((string) ($fcLabelSlice['title'] ?? ''));
        $treeLabelRow['first_child_source_title'] = trim((string) ($fcLabelSlice['source_title'] ?? ''));
    }

    $ntLcSupp = strtolower(trim((string) ($row['node_type'] ?? '')));
    $designatorForSupplement = easa_erules_node_detail_amc_gm_designator_key($row, $fcLabelSlice);
    $supplementFenceAppendixNums = [];
    $sbPriorToFenceLiftEmpty = ($structuredBlocksDecoded === null || $structuredBlocksDecoded === []);
    if (
        $sbPriorToFenceLiftEmpty
        && $designatorForSupplement !== null
        && in_array($ntLcSupp, ['toc', 'heading'], true)
    ) {
        $supplementFenceAppendixNums = easa_erules_supplement_navigation_appendix_lock_ordinals($row, $fcLabelSlice);
        $liftSb = easa_erules_node_detail_resolve_structured_blocks_under_supplement_fence(
            $pdo,
            $batchId,
            $detailLoadUid,
            $designatorForSupplement,
            $supplementFenceAppendixNums
        );
        if (is_array($liftSb) && $liftSb !== []) {
            $structuredBlocksDecoded = $liftSb;
        }
    }
    if ($syntheticBlockIndex !== null) {
        if (!is_array($structuredBlocksDecoded) || $structuredBlocksDecoded === []) {
            $structuredBlocksDecoded = easa_erules_node_structured_blocks_or_subject_aggregate($pdo, $batchId, $detailLoadUid);
        }
        if (!is_array($structuredBlocksDecoded) || $structuredBlocksDecoded === []) {
            return ['ok' => false, 'error' => 'No structured blocks for this synthetic section', 'http' => 404];
        }
        $sliced = easa_erules_structured_blocks_slice_for_synthetic_detail($structuredBlocksDecoded, $syntheticBlockIndex);
        if ($sliced === []) {
            return ['ok' => false, 'error' => 'Synthetic block index out of range', 'http' => 404];
        }
        $structuredBlocksDecoded = $sliced;
        $row['synthetic'] = true;
        $row['synthetic_block_start_index'] = $syntheticBlockIndex;
        $row['synthetic_detail_node_uid'] = $requestedNodeUid;
    }
    $row['structured_blocks'] = $structuredBlocksDecoded;

    $sbHasStructuredContent = $structuredBlocksDecoded !== null && $structuredBlocksDecoded !== [];
    $canonicalRaw = trim((string) ($row['canonical_text'] ?? ''));
    $plainRaw = (string) ($row['plain_text'] ?? '');
    $plainTrim = trim($plainRaw);
    $composed = '';
    $suppressFullDescendantAggregate = false;
    if ($plainTrim === '') {
        if (!$sbHasStructuredContent) {
            $ntLc = strtolower(trim((string) ($row['node_type'] ?? '')));
            $designatorForBody = $designatorForSupplement;
            if ($designatorForBody !== null && in_array($ntLc, ['toc', 'heading'], true)) {
                $suppressFullDescendantAggregate = true;
                $composed = trim(
                    easa_erules_aggregate_descendant_plain_text_for_designator(
                        $pdo,
                        $batchId,
                        $detailLoadUid,
                        $designatorForBody,
                        0,
                        $supplementFenceAppendixNums
                    )
                );
            }
            if ($composed === '' && !$suppressFullDescendantAggregate) {
                $composed = easa_erules_aggregate_descendant_plain_text($pdo, $batchId, $detailLoadUid, 0);
            }
        }
    }
    $stepPlain = $plainTrim !== '' ? $plainRaw : $composed;
    $stepPlainTrim = trim($stepPlain);
    /** Canonical rendering path only — wrapper xml_fragment/source.xml must not mask lifted structured descendant bodies. */
    $preferStructuredCanonical = $sbHasStructuredContent && $canonicalRaw === '';

    $fromFrag = '';
    if (!$preferStructuredCanonical && $canonicalRaw === '' && $stepPlainTrim === '') {
        $fragRaw = trim((string) ($row['xml_fragment'] ?? ''));
        if ($fragRaw !== '') {
            $fromFrag = easa_erules_plain_text_from_stored_xml_fragment($fragRaw);
        }
    }
    $srcEid = trim((string) ($row['source_erules_id'] ?? ''));
    $fromXml = '';
    if (!$preferStructuredCanonical && $canonicalRaw === '' && $stepPlainTrim === '' && trim($fromFrag) === '' && $srcEid !== '') {
        $xmlAbs = easa_erules_batch_source_xml_absolute_path($pdo, $batchId);
        if ($xmlAbs !== null) {
            $fromXml = easa_erules_extract_plain_text_from_source_xml_by_erules_id($xmlAbs, $srcEid);
        }
    }

    $effectivePlain = '';
    if ($canonicalRaw !== '') {
        $effectivePlain = $canonicalRaw;
        $row['plain_text_effective_source'] = 'canonical';
    } elseif ($preferStructuredCanonical) {
        $effectivePlain = '';
        $row['plain_text_effective_source'] = 'structured_blocks';
    } elseif ($stepPlainTrim !== '') {
        $effectivePlain = $stepPlain;
        $row['plain_text_effective_source'] = $plainTrim !== '' ? 'node' : 'descendants';
    } elseif (trim($fromFrag) !== '') {
        $effectivePlain = $fromFrag;
        $row['plain_text_effective_source'] = 'xml_fragment';
    } elseif (trim($fromXml) !== '') {
        $effectivePlain = $fromXml;
        $row['plain_text_effective_source'] = 'source_xml_erules';
    } elseif ($suppressFullDescendantAggregate && $designatorForSupplement !== null) {
        $effectivePlain =
            'This AMC/GM row is only a bounded navigation wrapper — no descendant text survived the appendix '
            . 'fence (neighbouring supplement material was deliberately excluded). If expected content is '
            . 'missing, inspect the numbered topic descendant for stored canonical or structured_blocks.';
        $row['plain_text_effective_source'] = 'supplement_wrapper_no_bounded_plain';
    } else {
        $row['plain_text_effective_source'] = 'none';
    }
    $row['plain_text_composed_from_descendants'] = $canonicalRaw === '' && $plainTrim === '' && trim($composed) !== '';
    $maxPlain = 400000;
    $truncated = strlen($effectivePlain) > $maxPlain;
    if ($truncated) {
        $row['plain_text'] = substr($effectivePlain, 0, $maxPlain) . "\n\n… [truncated for API; full text is in the staging rows]";
    } else {
        $row['plain_text'] = $effectivePlain;
    }
    $row['plain_text_truncated'] = $truncated;
    $bandPieces = [
        trim((string) ($row['title'] ?? '')),
        trim((string) ($row['source_title'] ?? '')),
        trim((string) ($row['source_erules_id'] ?? '')),
    ];
    if ($fcLabelSlice !== null) {
        $bandPieces[] = trim((string) ($fcLabelSlice['title'] ?? ''));
        $bandPieces[] = trim((string) ($fcLabelSlice['source_title'] ?? ''));
        $eec = trim((string) ($fcLabelSlice['source_erules_id'] ?? ''));
        if ($eec !== '') {
            $bandPieces[] = $eec;
        }
    }
    $bandProbeBlob = implode("\n", array_filter($bandPieces, static fn(string $x): bool => $x !== ''));
    $row['rule_band'] = easa_erules_classify_display_band(
        $row['node_type'] ?? null,
        $bandProbeBlob !== '' ? $bandProbeBlob : null,
        null,
        null
    );
    $row['title_display'] = easa_erules_sanitize_display_text(easa_erules_short_tree_label($treeLabelRow));
    if ($syntheticBlockIndex !== null && is_array($structuredBlocksDecoded) && $structuredBlocksDecoded !== []) {
        $hd0 = $structuredBlocksDecoded[0];
        if (is_array($hd0) && ($hd0['type'] ?? '') === 'heading') {
            $td = trim((string) ($hd0['text'] ?? ''));
            if ($td !== '') {
                $partsLn = preg_split('/\R+/u', $td);
                $line0 = (is_array($partsLn) && isset($partsLn[0])) ? trim((string) $partsLn[0]) : $td;
                $row['title_display'] = easa_erules_sanitize_display_text($line0);
            }
        }
    }
    $sanitizedBody = easa_erules_sanitize_rule_body_text($truncated ? (string) $row['plain_text'] : $effectivePlain);
    $row['plain_text_display'] = $sanitizedBody;
    $sbPresent = is_array($row['structured_blocks'] ?? null) && ($row['structured_blocks'] ?? []) !== [];
    if ($row['plain_text_display'] === '' && $row['plain_text_effective_source'] === 'none' && !$sbPresent) {
        $ntLow = strtolower(trim((string) ($row['node_type'] ?? '')));
        $noErules = trim((string) ($row['source_erules_id'] ?? '')) === '';
        if (in_array($ntLow, ['heading', 'toc'], true) && $noErules) {
            $row['plain_text_display'] = 'No body text on this navigation heading — expand branches below if topics appear in the tree.';
            $row['body_reading'] = '';
        } else {
            $row['plain_text_display'] = 'No rule text could be resolved: canonical_text and plain_text are empty, no text could be extracted from xml_fragment, no child rows contributed text, and source.xml could not be matched by ERulesId (or the file is missing). Expected file: storage/easa_erules/batches/' . (int) $batchId . '/source.xml — verify batch storage_relpath matches this batch id.';
            $row['body_reading'] = '';
        }
    } else {
        $sourceForReading = $sanitizedBody;
        if (($row['plain_text_effective_source'] ?? '') === 'canonical' && $plainTrim !== '') {
            $sanPlainLocal = easa_erules_sanitize_rule_body_text($plainRaw);
            if ($sanPlainLocal !== '') {
                $nCanon = substr_count($sanitizedBody, "\n");
                $nPlain = substr_count($sanPlainLocal, "\n");
                if ($nPlain > $nCanon || ($nCanon === 0 && $nPlain >= 2)) {
                    $sourceForReading = $sanPlainLocal;
                }
            }
        }
        $row['body_reading'] = easa_erules_format_body_for_reading($sourceForReading);
    }
    $rowUidForRedirect = trim((string) ($row['node_uid'] ?? ''));
    if ($rowUidForRedirect !== '' && function_exists('easa_erules_tree_redirected_parent_uid_for_node')) {
        $redirectedParentUid = easa_erules_tree_redirected_parent_uid_for_node($pdo, $batchId, $rowUidForRedirect);
        if ($redirectedParentUid !== null) {
            $origParent = $row['parent_node_uid'] ?? null;
            if ((string) $origParent !== $redirectedParentUid) {
                $row['parent_node_uid_original'] = $origParent;
                $row['parent_node_uid'] = $redirectedParentUid;
                $row['parent_redirected'] = true;
            }
        }
    }

    return ['ok' => true, 'node' => $row];
}
