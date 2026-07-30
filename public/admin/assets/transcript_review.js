(function () {
  'use strict';

  const LAYER_LABELS = {
    published: 'Published',
    readable: 'Readable',
    production: 'Production',
    whisper: 'Whisper',
    legacy: 'Legacy cache',
  };

  const STAGE_LABELS = {
    legacy: 'Legacy cache only',
    transcribed: 'Transcribed',
    transcribing: 'Transcribing',
    evidence: 'Evidence persisted',
    publishable: 'Ready to publish',
    published: 'Published',
  };

  const formatMs = (ms) => {
    const totalSeconds = Math.max(0, Math.floor(Number(ms || 0) / 1000));
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    if (hours > 0) {
      return String(hours) + ':' + String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
    }
    return String(minutes) + ':' + String(seconds).padStart(2, '0');
  };

  const escapeHtml = (value) => String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

  const TranscriptReviewWorkspace = {
    elements: {},
    state: {
      recordingId: null,
      payload: null,
      activeLayer: null,
      activeBlockId: null,
      pollTimer: null,
      publishableRunId: null,
      timeUpdateBound: false,
    },

    init(elements) {
      this.elements = elements;
      return this;
    },

    stopPoll() {
      if (this.state.pollTimer !== null) {
        window.clearInterval(this.state.pollTimer);
        this.state.pollTimer = null;
      }
    },

    async load(recordingId, options = {}) {
      const allowPoll = options.allowPoll !== false;
      if (!recordingId) return;

      this.state.recordingId = recordingId;
      this.showLoading();

      const layer = this.state.activeLayer ? '&layer=' + encodeURIComponent(this.state.activeLayer) : '';
      const response = await fetch('/admin/api/cockpit_recorder_transcript_review.php?id=' + encodeURIComponent(recordingId) + layer, {
        credentials: 'same-origin',
      });
      const payload = await response.json();
      if (!response.ok || !payload.ok) {
        throw new Error(payload.error || 'Could not load transcript review.');
      }

      this.state.payload = payload;
      this.state.activeLayer = payload.active_layer || 'legacy';
      this.state.publishableRunId = payload.pipeline?.latest_publishable_processing_run_id || null;
      this.render(payload);

      const status = String(payload.transcription_status || '').toLowerCase();
      const inProgress = status === 'queued' || status === 'transcribing' || status === 'pending';
      if (inProgress && allowPoll && this.state.pollTimer === null) {
        this.state.pollTimer = window.setInterval(() => {
          this.load(recordingId, { allowPoll: true }).catch(() => this.stopPoll());
        }, 3000);
      } else if (!inProgress) {
        this.stopPoll();
      }

      return payload;
    },

    showLoading() {
      const { workspace, legacyBody, toolbar } = this.elements;
      if (toolbar) toolbar.innerHTML = '';
      if (workspace) workspace.hidden = true;
      if (legacyBody) {
        legacyBody.hidden = false;
        legacyBody.textContent = 'Loading transcript…';
      }
    },

    render(payload) {
      this.renderMeta(payload);
      this.renderControls(payload);
      this.renderAudio(payload);

      const status = String(payload.transcription_status || '').toLowerCase();
      const inProgress = status === 'queued' || status === 'transcribing' || status === 'pending';
      if (inProgress) {
        this.renderProgress(payload);
        return;
      }
      if (status === 'failed') {
        this.renderLegacy('Transcription failed. Use Re-Process From Audio to try again.');
        return;
      }

      if (payload.view_mode === 'structured' && Array.isArray(payload.blocks) && payload.blocks.length > 0
        && ['whisper', 'readable', 'published'].includes(String(payload.active_layer || ''))) {
        this.renderStructured(payload);
      } else {
        const layerText = payload.layers?.[payload.active_layer] || payload.legacy_text || '';
        this.renderLegacy(layerText || 'Transcript is not available yet.');
      }
    },

    renderMeta(payload) {
      const { title, meta } = this.elements;
      if (title) {
        title.textContent = payload.recording_uid ? ('Transcript · ' + payload.recording_uid) : 'Cockpit Audio Transcript';
      }
      if (!meta) return;

      const parts = [];
      if (payload.aircraft_registration) parts.push(payload.aircraft_registration);
      if (payload.transcription_status) parts.push(String(payload.transcription_status).toUpperCase());
      if (payload.transcription_progress != null) parts.push(String(payload.transcription_progress) + '%');
      if (payload.duration_seconds) parts.push(formatMs(payload.duration_seconds * 1000));

      let html = escapeHtml(parts.join(' · '));
      const stage = payload.pipeline?.stage || 'legacy';
      html += ' <span class="trv-badge trv-badge-stage">' + escapeHtml(STAGE_LABELS[stage] || stage) + '</span>';
      if (payload.published?.published_version_uuid) {
        html += '<div class="intake-muted" style="margin-top:4px">Published '
          + escapeHtml(String(payload.published.published_version_uuid).slice(0, 8)) + '…'
          + (payload.published.published_at ? (' · ' + escapeHtml(payload.published.published_at)) : '')
          + '</div>';
      }
      meta.innerHTML = html;
    },

    renderControls(payload) {
      const { publishButton, publishHint, note, reprocessButton, cleanupButton } = this.elements;
      const pipeline = payload.pipeline || {};
      const publishReady = !!pipeline.publish_ready;
      const publishable = !!pipeline.publishable;
      this.state.publishableRunId = publishable ? (pipeline.latest_publishable_processing_run_id || null) : null;

      if (publishButton) {
        publishButton.hidden = !publishReady;
        publishButton.disabled = !publishable;
        publishButton.textContent = payload.active_layer === 'published' && payload.published
          ? 'Publish New Evidence Version'
          : 'Publish Evidence';
      }

      if (publishHint) {
        if (!publishReady) {
          publishHint.hidden = true;
        } else if (!publishable) {
          publishHint.hidden = false;
          publishHint.classList.remove('trv-publish-hint-ready');
          publishHint.innerHTML = '<strong>Publish Evidence is unavailable.</strong> Run full evidence processing (Whisper + Pass 4 + Pass 5) first.';
        } else {
          publishHint.hidden = false;
          publishHint.classList.add('trv-publish-hint-ready');
          publishHint.textContent = 'Ready to publish from processing run #' + String(this.state.publishableRunId || '') + '.';
        }
      }

      if (note) note.hidden = payload.view_mode === 'structured';

      const inProgress = ['queued', 'transcribing', 'pending'].includes(String(payload.transcription_status || '').toLowerCase());
      if (reprocessButton) reprocessButton.disabled = inProgress;
      if (cleanupButton) cleanupButton.disabled = inProgress;
      if (publishButton) publishButton.disabled = inProgress || !publishable;
    },

    renderAudio(payload) {
      const { player } = this.elements;
      if (!player) return;
      if (payload.audio_url && !player.getAttribute('src')) {
        player.src = payload.audio_url;
      }
    },

    renderProgress(payload) {
      this.renderLegacy('Transcription in progress… ' + String(payload.transcription_progress || 0) + '%');
    },

    renderLegacy(text) {
      const { workspace, legacyBody } = this.elements;
      if (workspace) workspace.hidden = true;
      if (legacyBody) {
        legacyBody.hidden = false;
        legacyBody.textContent = text;
      }
    },

    renderStructured(payload) {
      const { workspace, legacyBody, toolbar, outline, transcript, sidebar } = this.elements;
      if (legacyBody) legacyBody.hidden = true;
      if (workspace) workspace.hidden = false;

      if (toolbar) {
        const layers = Object.keys(payload.layers || {});
        toolbar.innerHTML = ''
          + '<div class="trv-toolbar-row">'
          + '<label class="trv-layer-label">Layer<select class="trv-layer-select" data-trv-layer-select>'
          + layers.map((key) => '<option value="' + escapeHtml(key) + '"' + (key === payload.active_layer ? ' selected' : '') + '>' + escapeHtml(LAYER_LABELS[key] || key) + '</option>').join('')
          + '</select></label>'
          + this.renderPipelineBadges(payload.pipeline || {})
          + '</div>';

        const select = toolbar.querySelector('[data-trv-layer-select]');
        if (select) {
          select.addEventListener('change', async () => {
            this.state.activeLayer = select.value;
            if (this.state.recordingId) {
              try {
                await this.load(this.state.recordingId, { allowPoll: false });
              } catch (error) {
                this.renderLegacy(error instanceof Error ? error.message : 'Could not switch layer.');
              }
            }
          });
        }
      }

      if (outline) {
        const chapters = Array.isArray(payload.chapters) ? payload.chapters : [];
        if (chapters.length === 0) {
          outline.innerHTML = '<div class="trv-panel-title">Flight outline</div><p class="trv-muted">No chapters yet. Pass 5 will generate these after evidence processing.</p>';
        } else {
          outline.innerHTML = '<div class="trv-panel-title">Flight outline</div><ul class="trv-chapter-list">'
            + chapters.map((chapter, index) => {
              const title = chapter.title || ('Chapter ' + (index + 1));
              return '<li><button type="button" class="trv-chapter-btn" data-trv-chapter-start="' + Number(chapter.start_time_ms || 0) + '" data-trv-chapter-end="' + Number(chapter.end_time_ms || 0) + '">'
                + '<span class="trv-chapter-time">' + escapeHtml(formatMs(chapter.start_time_ms)) + '</span>'
                + '<span class="trv-chapter-title">' + escapeHtml(title) + '</span>'
                + (chapter.category ? '<span class="trv-chapter-cat">' + escapeHtml(chapter.category) + '</span>' : '')
                + '</button></li>';
            }).join('')
            + '</ul>';
          outline.querySelectorAll('[data-trv-chapter-start]').forEach((button) => {
            button.addEventListener('click', () => {
              const startMs = Number(button.getAttribute('data-trv-chapter-start') || 0);
              this.seekToMs(startMs);
              this.highlightBlockNear(startMs);
            });
          });
        }
      }

      if (transcript) {
        const blocks = (payload.blocks || []).filter((block) => !block.suppressed && String(block.text || '').trim() !== '');
        transcript.innerHTML = '<div class="trv-panel-title">Timestamped transcript</div>'
          + (blocks.length === 0
            ? '<p class="trv-muted">No display blocks available for this layer.</p>'
            : '<div class="trv-block-list">' + blocks.map((block) => {
              const segmentId = Array.isArray(block.speech_segment_ids) && block.speech_segment_ids.length > 0
                ? block.speech_segment_ids[0]
                : '';
              return '<article class="trv-block" data-trv-block-id="' + Number(block.id || 0) + '" data-trv-start="' + Number(block.start_time_ms || 0) + '" data-trv-end="' + Number(block.end_time_ms || 0) + '" data-trv-segment-id="' + escapeHtml(String(segmentId)) + '">'
                + '<button type="button" class="trv-block-time" data-trv-seek="' + Number(block.start_time_ms || 0) + '">' + escapeHtml(formatMs(block.start_time_ms)) + '</button>'
                + '<div class="trv-block-text">' + escapeHtml(block.text) + '</div>'
                + '<button type="button" class="trv-block-correct" title="Propose terminology correction">Correct</button>'
                + '</article>';
            }).join('') + '</div>');

        transcript.querySelectorAll('[data-trv-seek]').forEach((button) => {
          button.addEventListener('click', () => this.seekToMs(Number(button.getAttribute('data-trv-seek') || 0)));
        });

        transcript.querySelectorAll('.trv-block').forEach((blockEl) => {
          blockEl.querySelector('.trv-block-correct')?.addEventListener('click', () => {
            this.openCorrectionForm(blockEl);
          });
        });

        this.bindAudioSync();
      }

      if (sidebar) {
        sidebar.innerHTML = this.renderQualityPanel(payload.quality)
          + this.renderTerminologyPanel(payload);
        this.bindTerminologyActions(sidebar, payload);
      }
    },

    renderPipelineBadges(pipeline) {
      const badges = [];
      badges.push('<span class="trv-badge' + (pipeline.evidence_ready ? ' trv-badge-ok' : '') + '">Evidence</span>');
      badges.push('<span class="trv-badge' + (pipeline.pass4_ready ? ' trv-badge-ok' : '') + '">Pass 4</span>');
      badges.push('<span class="trv-badge' + (pipeline.pass5_ready ? ' trv-badge-ok' : '') + '">Pass 5</span>');
      badges.push('<span class="trv-badge' + (pipeline.publishable ? ' trv-badge-ok' : '') + '">Publishable</span>');
      if (pipeline.active_processing_run_id) {
        badges.push('<span class="trv-badge">Run #' + escapeHtml(String(pipeline.active_processing_run_id)) + '</span>');
      }
      return '<div class="trv-pipeline-badges">' + badges.join('') + '</div>';
    },

    renderQualityPanel(quality) {
      if (!quality) {
        return '<section class="trv-side-panel"><div class="trv-panel-title">Quality summary</div><p class="trv-muted">Quality metrics appear after Pass 4 runs.</p></section>';
      }

      const pass4a = quality.pass_4a || {};
      const preview = (quality.pass_4a_findings_preview || []).slice(0, 5).map((item) => {
        const label = item.reason || item.type || item.category || 'finding';
        return '<li>' + escapeHtml(label) + '</li>';
      }).join('');

      return ''
        + '<section class="trv-side-panel">'
        + '<div class="trv-panel-title">Quality summary</div>'
        + '<dl class="trv-kv">'
        + '<dt>Speech segments</dt><dd>' + Number(quality.speech_segment_count || 0) + '</dd>'
        + '<dt>Suppressed</dt><dd>' + Number(quality.suppressed_segment_count || 0) + '</dd>'
        + '<dt>Pass 4A flags</dt><dd>' + Number(quality.pass_4a_flagged_count || 0) + '</dd>'
        + '<dt>Pass 4B findings</dt><dd>' + Number(quality.pass_4b_finding_count || 0) + '</dd>'
        + '</dl>'
        + (pass4a.avg_confidence != null ? '<p class="trv-muted">Avg confidence: ' + escapeHtml(String(pass4a.avg_confidence)) + '</p>' : '')
        + (preview ? '<ul class="trv-findings">' + preview + '</ul>' : '')
        + '</section>';
    },

    renderTerminologyPanel(payload) {
      const corrections = Array.isArray(payload.terminology_corrections) ? payload.terminology_corrections : [];
      const list = corrections.length === 0
        ? '<p class="trv-muted">No terminology corrections yet.</p>'
        : '<ul class="trv-correction-list">' + corrections.map((row) => {
          return '<li class="trv-correction trv-correction-' + escapeHtml(row.status || 'proposed') + '">'
            + '<div class="trv-correction-raw">' + escapeHtml(row.raw_text) + '</div>'
            + '<div class="trv-correction-arrow">→</div>'
            + '<div class="trv-correction-fixed">' + escapeHtml(row.corrected_text) + '</div>'
            + '<div class="trv-correction-meta">' + escapeHtml(row.status || 'proposed')
            + (row.status === 'proposed'
              ? ' <button type="button" class="trv-correction-action" data-trv-correction-action="accept" data-trv-correction-uuid="' + escapeHtml(row.correction_uuid) + '">Accept</button>'
                + ' <button type="button" class="trv-correction-action trv-correction-reject" data-trv-correction-action="reject" data-trv-correction-uuid="' + escapeHtml(row.correction_uuid) + '">Reject</button>'
              : '')
            + '</div></li>';
        }).join('') + '</ul>';

      return ''
        + '<section class="trv-side-panel" id="trv-terminology-panel">'
        + '<div class="trv-panel-title">Terminology corrections</div>'
        + list
        + '<form class="trv-correction-form" id="trv-correction-form" hidden>'
        + '<input type="hidden" name="speech_segment_id" id="trv-correction-segment-id">'
        + '<input type="hidden" name="start_time_ms" id="trv-correction-start-ms">'
        + '<input type="hidden" name="end_time_ms" id="trv-correction-end-ms">'
        + '<label>Raw<input type="text" name="raw_text" id="trv-correction-raw" required></label>'
        + '<label>Corrected<input type="text" name="corrected_text" id="trv-correction-fixed" required></label>'
        + '<div class="trv-correction-form-actions">'
        + '<button type="submit" class="trv-btn-primary">Propose</button>'
        + '<button type="button" class="trv-btn-secondary" data-trv-correction-cancel>Cancel</button>'
        + '</div></form>'
        + '</section>';
    },

    bindTerminologyActions(sidebar, payload) {
      const form = sidebar.querySelector('#trv-correction-form');
      if (form) {
        form.querySelector('[data-trv-correction-cancel]')?.addEventListener('click', () => {
          form.hidden = true;
        });
        form.addEventListener('submit', async (event) => {
          event.preventDefault();
          if (!this.state.recordingId) return;
          const formData = new FormData(form);
          formData.append('action', 'propose');
          formData.append('recording_id', String(this.state.recordingId));
          try {
            const response = await fetch('/admin/api/cockpit_evidence_terminology_correction.php', {
              method: 'POST',
              body: formData,
              credentials: 'same-origin',
            });
            const result = await response.json();
            if (!response.ok || !result.ok) {
              throw new Error(result.error || 'Could not propose correction.');
            }
            form.hidden = true;
            await this.load(this.state.recordingId, { allowPoll: false });
          } catch (error) {
            window.alert(error instanceof Error ? error.message : 'Could not propose correction.');
          }
        });
      }

      sidebar.querySelectorAll('[data-trv-correction-action]').forEach((button) => {
        button.addEventListener('click', async () => {
          const action = button.getAttribute('data-trv-correction-action');
          const uuid = button.getAttribute('data-trv-correction-uuid');
          if (!action || !uuid) return;
          const formData = new FormData();
          formData.append('action', action);
          formData.append('correction_uuid', uuid);
          try {
            const response = await fetch('/admin/api/cockpit_evidence_terminology_correction.php', {
              method: 'POST',
              body: formData,
              credentials: 'same-origin',
            });
            const result = await response.json();
            if (!response.ok || !result.ok) {
              throw new Error(result.error || 'Could not update correction.');
            }
            if (this.state.recordingId) {
              await this.load(this.state.recordingId, { allowPoll: false });
            }
          } catch (error) {
            window.alert(error instanceof Error ? error.message : 'Could not update correction.');
          }
        });
      });
    },

    openCorrectionForm(blockEl) {
      const sidebar = this.elements.sidebar;
      if (!sidebar) return;
      const form = sidebar.querySelector('#trv-correction-form');
      if (!form) return;
      const text = blockEl.querySelector('.trv-block-text')?.textContent?.trim() || '';
      form.querySelector('#trv-correction-raw').value = text;
      form.querySelector('#trv-correction-fixed').value = '';
      form.querySelector('#trv-correction-segment-id').value = blockEl.getAttribute('data-trv-segment-id') || '';
      form.querySelector('#trv-correction-start-ms').value = blockEl.getAttribute('data-trv-start') || '';
      form.querySelector('#trv-correction-end-ms').value = blockEl.getAttribute('data-trv-end') || '';
      form.hidden = false;
      form.scrollIntoView({ block: 'nearest' });
      form.querySelector('#trv-correction-fixed')?.focus();
    },

    bindAudioSync() {
      const { player } = this.elements;
      if (!player || this.state.timeUpdateBound) return;
      this.state.timeUpdateBound = true;
      player.addEventListener('timeupdate', () => {
        this.highlightBlockNear(player.currentTime * 1000);
      });
    },

    seekToMs(ms) {
      const { player } = this.elements;
      if (!player) return;
      player.currentTime = Math.max(0, Number(ms || 0) / 1000);
      player.play().catch(() => {});
    },

    highlightBlockNear(ms) {
      const { transcript } = this.elements;
      if (!transcript) return;
      let closest = null;
      let closestDelta = Infinity;
      transcript.querySelectorAll('.trv-block').forEach((blockEl) => {
        const start = Number(blockEl.getAttribute('data-trv-start') || 0);
        const delta = Math.abs(start - ms);
        blockEl.classList.remove('is-active');
        if (delta < closestDelta) {
          closestDelta = delta;
          closest = blockEl;
        }
      });
      if (closest) {
        closest.classList.add('is-active');
        closest.scrollIntoView({ block: 'center', behavior: 'smooth' });
      }
    },

    close() {
      this.stopPoll();
      this.state.recordingId = null;
      this.state.payload = null;
      this.state.timeUpdateBound = false;
      const { player, workspace, legacyBody, toolbar } = this.elements;
      if (player) {
        player.pause();
        player.removeAttribute('src');
        player.load();
      }
      if (workspace) workspace.hidden = true;
      if (legacyBody) {
        legacyBody.hidden = false;
        legacyBody.textContent = 'Loading transcript…';
      }
      if (toolbar) toolbar.innerHTML = '';
    },

    getPublishableRunId() {
      return this.state.publishableRunId;
    },
  };

  window.TranscriptReviewWorkspace = TranscriptReviewWorkspace;
})();
