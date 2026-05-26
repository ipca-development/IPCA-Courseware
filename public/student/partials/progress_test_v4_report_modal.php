<?php
declare(strict_types=1);

$copilotBadgeImage = $copilotBadgeImage ?? '/assets/badges/06_mayas_copilot.png';
?>
<div class="ptv4-modal-backdrop" data-ptv4-report-modal hidden>
  <div class="ptv4-modal ptv4-report-modal" role="dialog" aria-modal="true" aria-label="Progress test report">
    <div class="ptv4-modal-head">
      <div class="ptv4-modal-title-wrap">
        <img src="/assets/avatars/maya.png" alt="" class="ptv4-modal-maya">
        <div>
          <div class="ptv4-modal-title">Progress Test Report</div>
          <div class="ptv4-modal-sub" data-ptv4-report-subtitle></div>
        </div>
      </div>
      <button class="ptv4-modal-close" type="button" data-ptv4-report-close aria-label="Close">&times;</button>
    </div>
    <div class="ptv4-modal-tabs">
      <button type="button" class="app-btn app-btn-primary is-active" data-ptv4-tab="score">Score Report</button>
      <button type="button" class="app-btn app-btn-secondary" data-ptv4-tab="feedback">Feedback</button>
    </div>
    <div class="ptv4-modal-body ptv4-report-modal-body">
      <div class="ptv4-tab-panel is-active" data-ptv4-tab-panel="score">
        <div class="ptv4-report-hero" data-ptv4-report-hero></div>
        <div class="ptv4-report-stats" data-ptv4-report-stats></div>
        <div class="ptv4-report-grid">
          <div class="ptv4-report-main">
            <div class="ptv4-report-section-head">
              <div class="ptv4-report-section-title">Question Results</div>
              <button class="app-btn app-btn-secondary ptv4-report-expand-all" type="button" data-ptv4-report-expand-all>Expand All</button>
            </div>
            <div class="ptv4-report-questions" data-ptv4-report-questions></div>
            <div class="ptv4-report-metrics" data-ptv4-report-metrics hidden></div>
          </div>
          <aside class="ptv4-report-side">
            <div class="ptv4-report-section-title">Achievements Unlocked</div>
            <div class="ptv4-report-badges" data-ptv4-report-badges></div>
          </aside>
        </div>
        <div class="ptv4-report-focus" data-ptv4-report-focus></div>
        <div class="ptv4-report-foot">
          <button class="app-btn app-btn-secondary" type="button" data-ptv4-report-close-bottom>Close Report</button>
        </div>
      </div>
      <div class="ptv4-tab-panel" data-ptv4-tab-panel="feedback">
        <div class="ptv4-feedback-hero">
          <img src="/assets/avatars/maya.png" alt="" class="ptv4-feedback-hero-maya">
          <div>
            <div class="ptv4-feedback-hero-title">Help Improve Maya</div>
            <div class="ptv4-feedback-hero-text">Your feedback helps me become a better instructor for future pilots. Thank you for helping improve the IPCA AI training system.</div>
          </div>
        </div>
        <form data-ptv4-feedback-form class="ptv4-feedback-form">
          <div class="ptv4-feedback-q" data-ptv4-fb-q="maya_clear"><div class="ptv4-feedback-q-label">Maya’s questions were clear.</div><div class="ptv4-feedback-q-options"></div></div>
          <div class="ptv4-feedback-q" data-ptv4-fb-q="audio_quality"><div class="ptv4-feedback-q-label">The audio quality was good.</div><div class="ptv4-feedback-q-options"></div></div>
          <div class="ptv4-feedback-q" data-ptv4-fb-q="felt_fair"><div class="ptv4-feedback-q-label">The test felt fair.</div><div class="ptv4-feedback-q-options"></div></div>
          <div class="ptv4-feedback-q" data-ptv4-fb-q="recording_worked"><div class="ptv4-feedback-q-label">The answer recording worked well.</div><div class="ptv4-feedback-q-options"></div></div>
          <div class="ptv4-feedback-q" data-ptv4-fb-q="feedback_helped"><div class="ptv4-feedback-q-label">Maya’s feedback helped me understand what to improve.</div><div class="ptv4-feedback-q-options"></div></div>
          <div class="ptv4-feedback-q" data-ptv4-fb-q="felt_motivating"><div class="ptv4-feedback-q-label">The Progress Test felt motivating.</div><div class="ptv4-feedback-q-options"></div></div>
          <div class="ptv4-feedback-q ptv4-feedback-q-issue" data-ptv4-fb-q="went_wrong"><div class="ptv4-feedback-q-label">Did anything go wrong?</div><div class="ptv4-feedback-q-options" data-ptv4-fb-issue></div></div>
          <label class="ptv4-feedback-free">Anything else you want to tell us?<textarea rows="3" data-ptv4-fb-free maxlength="500"></textarea><span class="ptv4-feedback-char-count" data-ptv4-fb-count>0 / 500</span></label>
          <div class="ptv4-feedback-reward" data-ptv4-feedback-reward>
            <img src="<?= h($copilotBadgeImage) ?>" alt="Maya's Copilot" class="ptv4-feedback-reward-badge-img">
            <div>As a thank you, you’ll earn the <strong>Maya's Copilot</strong> badge when you send your feedback.</div>
          </div>
          <div class="ptv4-feedback-actions">
            <button class="app-btn app-btn-primary" type="submit">Send Feedback</button>
          </div>
          <div class="ptv4-feedback-sent" data-ptv4-feedback-sent hidden>
            <div class="ptv4-feedback-sent-title">Thank you for helping improve the IPCA AI training system.</div>
            <div class="ptv4-feedback-sent-badge" data-ptv4-feedback-sent-badge hidden>
              <img src="<?= h($copilotBadgeImage) ?>" alt="Maya's Copilot" class="ptv4-feedback-reward-badge-img">
              <span>Maya's Copilot badge unlocked</span>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
