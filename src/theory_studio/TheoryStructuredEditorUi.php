<?php
declare(strict_types=1);

final class TheoryStructuredEditorUi
{
    /** @param array<string,mixed> $state */
    public static function editor(string $mode, array $state): string
    {
        $templateMode = $mode === 'template';
        $readonly = !empty($state['readonly']);
        $json = json_encode(
            $state,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
        );
        ob_start();
        ?>
        <script>
        (function () {
          function markTheoryEditorPage() { document.body.classList.add('tse-authoring-page'); }
          if (document.body) markTheoryEditorPage();
          else document.addEventListener('DOMContentLoaded', markTheoryEditorPage, { once: true });
        })();
        </script>
        <div class="tse-root" id="tseEditorRoot"
             data-mode="<?= self::h($mode) ?>"
             data-readonly="<?= $readonly ? '1' : '0' ?>">
          <?= self::applicationHeader($mode, $state, $readonly) ?>
          <?= self::toolRibbon($mode, $readonly) ?>

          <div class="tse-app-workspace">
            <?= self::navigator($mode, $state) ?>

            <main class="tse-canvas-workspace" id="tseCanvasWorkspace">
              <div class="tse-canvas-scroll" id="tseCanvasScroll">
                <div class="tse-canvas-frame" id="tseCanvasFrame">
                  <div class="tse-ruler-corner" aria-hidden="true"></div>
                  <div class="tse-ruler tse-ruler--horizontal" id="tseRulerX"
                       title="Drag down to create a horizontal guide"></div>
                  <div class="tse-ruler tse-ruler--vertical" id="tseRulerY"
                       title="Drag right to create a vertical guide"></div>
                  <div class="tse-stage-wrap" id="tseStageWrap">
                    <div class="tse-grid-layer" id="tseGridLayer" aria-hidden="true"></div>
                    <div class="tse-stage" id="tseStage" aria-label="1600 by 900 slide canvas"></div>
                    <div class="tse-smart-guides" id="tseSmartGuides" aria-hidden="true"></div>
                    <div class="tse-context-toolbar" id="tseContextToolbar" hidden></div>
                  </div>
                </div>
              </div>
            </main>

            <aside class="tse-inspector" aria-label="Inspector">
              <div class="tse-inspector-tabs" role="tablist">
                <button type="button" class="is-active" data-tse-inspector-tab="layout">Layout</button>
                <button type="button" data-tse-inspector-tab="content">Content</button>
                <button type="button" data-tse-inspector-tab="style">Style</button>
                <button type="button" data-tse-inspector-tab="advanced">Advanced</button>
              </div>
              <div class="tse-inspector-body" id="tseInspector"></div>
            </aside>
          </div>

          <footer class="tse-statusbar">
            <div class="tse-statusbar-left">
              <span>Template: <strong><?= self::h((string)($state['template_name'] ?? $state['name'] ?? 'Not selected')) ?></strong></span>
              <?php if (!empty($state['version_number'])): ?>
                <span class="tse-badge">v<?= (int)$state['version_number'] ?></span>
              <?php endif; ?>
            </div>
            <div class="tse-statusbar-center">
              <label><input type="checkbox" id="tseGuidesVisible" checked> Show Guides</label>
              <label><input type="checkbox" id="tseSnapGuides" checked> Snap to Guides</label>
              <label><input type="checkbox" id="tseShowGrid"> Grid</label>
              <label><input type="checkbox" id="tseSnapGrid"> Snap to Grid</label>
            </div>
            <div class="tse-statusbar-right">
              <?php if (!$templateMode && isset($state['content_revision'])): ?>
                <span>Content revision: v<span id="tseRevision"><?= (int)$state['content_revision'] ?></span></span>
              <?php endif; ?>
              <span id="tseLastSaved">Not saved this session</span>
            </div>
          </footer>

          <?= self::templateChooser($state) ?>
          <input type="file" id="tseMediaInput" hidden>
          <script type="application/json" id="tseInitialState"><?= $json ?: '{}' ?></script>
        </div>
        <?php
        return (string)ob_get_clean();
    }

    /** @param array<string,mixed> $state */
    private static function applicationHeader(string $mode, array $state, bool $readonly): string
    {
        $crumbs = is_array($state['breadcrumbs'] ?? null) ? $state['breadcrumbs'] : array();
        ob_start();
        ?>
        <header class="tse-app-header">
          <div class="tse-app-identity">
            <a class="tse-brand" href="/admin/theory_studio/programs.php" aria-label="IPCA Theory Studio">
              <span class="tse-brand-mark">IPCA</span>
            </a>
            <nav class="tse-breadcrumbs" aria-label="Editor breadcrumb">
              <?php foreach ($crumbs as $index => $crumb): ?>
                <?php if ($index > 0): ?><span class="tse-breadcrumb-separator">›</span><?php endif; ?>
                <?php if (!empty($crumb['href'])): ?>
                  <a href="<?= self::h((string)$crumb['href']) ?>"><?= self::h((string)($crumb['label'] ?? '')) ?></a>
                <?php else: ?>
                  <span><?= self::h((string)($crumb['label'] ?? '')) ?></span>
                <?php endif; ?>
              <?php endforeach; ?>
            </nav>
          </div>
          <div class="tse-app-actions">
            <span class="tse-save-state" id="tseStatus"><i></i><span>Ready</span></span>
            <button type="button" class="tse-header-btn" id="tsePreview">
              <?= self::icon('preview') ?><span>Preview</span>
            </button>
            <button type="button" class="tse-header-btn" id="tseFullscreen">
              <?= self::icon('fullscreen') ?><span>Fullscreen</span>
            </button>
            <label class="tse-zoom-control">
              <span class="sr-only">Zoom</span>
              <select id="tseZoom">
                <option value="fit">Fit</option>
                <option value="50">50%</option>
                <option value="75">75%</option>
                <option value="100">100%</option>
                <option value="125">125%</option>
                <option value="150">150%</option>
              </select>
            </label>
            <button type="button" class="tse-icon-btn" id="tseUndo" title="Undo"><?= self::icon('undo') ?></button>
            <button type="button" class="tse-icon-btn" id="tseRedo" title="Redo"><?= self::icon('redo') ?></button>
            <button type="button" class="tse-save-btn" data-tse-save<?= $readonly ? ' disabled' : '' ?>>
              Save
            </button>
          </div>
        </header>
        <?php
        return (string)ob_get_clean();
    }

    private static function toolRibbon(string $mode, bool $readonly): string
    {
        $templateMode = $mode === 'template';
        ob_start();
        ?>
        <div class="tse-ribbon" id="tseToolbar">
          <div class="tse-tool-group">
            <?= self::tool('select', 'Select', 'pointer', array('class' => 'is-active', 'data-tse-tool' => 'select')) ?>
            <?= self::tool('text', 'Text Box', 'text', array('data-tse-add' => 'text'), !$templateMode || $readonly) ?>
            <?= self::tool('image', 'Image', 'image', array('data-tse-add' => 'image'), !$templateMode || $readonly) ?>
            <?= self::tool('video', 'Video', 'video', array('data-tse-add' => 'video'), !$templateMode || $readonly) ?>
            <?= self::tool('table', 'Table', 'table', array('data-tse-table' => '1')) ?>
            <?= self::tool('shape', 'Shape', 'shape', array(), true) ?>
            <?= self::tool('callout', 'Callout', 'callout', array('data-tse-callout' => '1')) ?>
            <?= self::tool('line', 'Line', 'line', array(), true) ?>
          </div>
          <div class="tse-ribbon-separator"></div>
          <div class="tse-tool-group">
            <?= self::tool('forward', 'Bring Forward', 'forward', array('data-tse-layer' => 'forward')) ?>
            <?= self::tool('backward', 'Send Backward', 'backward', array('data-tse-layer' => 'backward')) ?>
            <?= self::tool('align', 'Align', 'align', array('data-tse-align-menu' => '1')) ?>
            <?= self::tool('distribute', 'Distribute', 'distribute', array('data-tse-distribute' => '1')) ?>
            <?= self::tool('lock', 'Lock', 'lock', array('data-tse-lock' => '1')) ?>
          </div>
          <div class="tse-ribbon-separator"></div>
          <div class="tse-tool-group tse-tool-group--format">
            <?= self::tool('bold', 'Bold', 'bold', array('data-cmd' => 'bold')) ?>
            <?= self::tool('italic', 'Italic', 'italic', array('data-cmd' => 'italic')) ?>
            <?= self::tool('underline', 'Underline', 'underline', array('data-cmd' => 'underline')) ?>
            <?= self::tool('list', 'List', 'list', array('data-cmd' => 'insertUnorderedList')) ?>
            <?= self::tool('link', 'Link', 'link', array('data-tse-link' => '1')) ?>
            <?= self::tool('more', 'More', 'more', array('data-tse-more' => '1')) ?>
          </div>
        </div>
        <?php
        return (string)ob_get_clean();
    }

    /** @param array<string,mixed> $state */
    private static function navigator(string $mode, array $state): string
    {
        $items = is_array($state['navigator_items'] ?? null) ? $state['navigator_items'] : array();
        ob_start();
        ?>
        <aside class="tse-navigator" aria-label="<?= $mode === 'slide' ? 'Slides' : 'Template versions' ?>">
          <div class="tse-navigator-head">
            <strong><?= $mode === 'slide' ? 'Slides' : 'Versions' ?></strong>
            <?php if ($mode === 'slide'): ?>
              <button type="button" class="tse-icon-btn" data-tse-template-chooser title="Add Slide"><?= self::icon('plus') ?></button>
            <?php endif; ?>
          </div>
          <div class="tse-navigator-list" id="tseNavigatorList"
               <?= $mode === 'slide' ? 'data-tse-slide-reorder="1"' : '' ?>>
            <?php foreach ($items as $item): ?>
              <div class="tse-nav-row<?= !empty($item['active']) ? ' is-active' : '' ?>"
                   data-id="<?= (int)($item['id'] ?? 0) ?>" draggable="<?= $mode === 'slide' ? 'true' : 'false' ?>">
                <span class="tse-nav-number"><?= self::h((string)($item['number'] ?? '')) ?></span>
                <a class="tse-nav-thumb" href="<?= self::h((string)($item['href'] ?? '#')) ?>">
                  <span class="tse-mini-slide">
                    <i class="tse-mini-master tse-mini-master--top"></i>
                    <span><?= self::h((string)($item['title'] ?? '')) ?></span>
                    <i class="tse-mini-master tse-mini-master--bottom"></i>
                  </span>
                </a>
                <?php if ($mode === 'slide'): ?>
                  <button type="button" class="tse-nav-menu" data-tse-slide-menu
                          aria-label="Slide options">•••</button>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
          <?php if ($mode === 'slide'): ?>
            <button type="button" class="tse-add-slide" data-tse-template-chooser>
              <?= self::icon('plus') ?> Add Slide
            </button>
          <?php endif; ?>
        </aside>
        <?php
        return (string)ob_get_clean();
    }

    /** @param array<string,mixed> $state */
    private static function templateChooser(array $state): string
    {
        $templates = is_array($state['template_choices'] ?? null) ? $state['template_choices'] : array();
        if ($templates === array()) {
            return '';
        }
        ob_start();
        ?>
        <dialog class="tse-template-dialog" id="tseTemplateChooser">
          <div class="tse-dialog-head">
            <div>
              <span class="tse-eyebrow">New structured slide</span>
              <h2>Choose a Template</h2>
            </div>
            <button type="button" class="tse-icon-btn" data-tse-dialog-close aria-label="Close">×</button>
          </div>
          <div class="tse-template-choices">
            <?php foreach ($templates as $template): ?>
              <form data-ts-form="create_structured_slide" class="tse-template-choice">
                <input type="hidden" name="lesson_id" value="<?= (int)($state['lesson_id'] ?? 0) ?>">
                <input type="hidden" name="template_version_id" value="<?= (int)($template['version_id'] ?? 0) ?>">
                <button type="submit">
                  <span class="tse-template-preview">
                    <?php foreach (($template['placeholders'] ?? array()) as $placeholder): ?>
                      <i class="is-<?= self::h((string)($placeholder['type'] ?? 'text')) ?>"
                         style="left:<?= (float)($placeholder['x'] ?? 0) / 16 ?>%;top:<?= (float)($placeholder['y'] ?? 0) / 9 ?>%;width:<?= (float)($placeholder['width'] ?? 0) / 16 ?>%;height:<?= (float)($placeholder['height'] ?? 0) / 9 ?>%;"></i>
                    <?php endforeach; ?>
                  </span>
                  <strong><?= self::h((string)($template['name'] ?? 'Template')) ?></strong>
                  <small>Version <?= (int)($template['version_number'] ?? 1) ?></small>
                </button>
              </form>
            <?php endforeach; ?>
          </div>
        </dialog>
        <?php
        return (string)ob_get_clean();
    }

    /** @param array<string,string> $attributes */
    private static function tool(
        string $key,
        string $label,
        string $icon,
        array $attributes = array(),
        bool $disabled = false
    ): string {
        $attrs = '';
        foreach ($attributes as $name => $value) {
            $attrs .= ' ' . self::h($name) . '="' . self::h($value) . '"';
        }
        return '<button type="button" class="tse-tool" title="' . self::h($label) . '"'
            . $attrs . ($disabled ? ' disabled' : '') . '>'
            . self::icon($icon) . '<span>' . self::h($label) . '</span></button>';
    }

    private static function icon(string $name): string
    {
        $paths = array(
            'pointer' => '<path d="M6 3l9 8-5 .8 2.4 5.2-2.2 1-2.3-5.1L5 16z"/>',
            'text' => '<path d="M5 5h14M12 5v14M8 19h8"/>',
            'image' => '<rect x="4" y="5" width="16" height="14" rx="2"/><circle cx="9" cy="10" r="1.5"/><path d="M5 17l4-4 3 3 2-2 5 3"/>',
            'video' => '<rect x="3" y="6" width="13" height="12" rx="2"/><path d="M16 10l5-3v10l-5-3z"/>',
            'table' => '<rect x="4" y="5" width="16" height="14" rx="1"/><path d="M4 10h16M10 5v14"/>',
            'shape' => '<rect x="5" y="5" width="14" height="14" rx="3"/>',
            'callout' => '<path d="M4 5h16v11H10l-4 3v-3H4z"/>',
            'line' => '<path d="M5 18L19 6"/>',
            'forward' => '<rect x="8" y="8" width="10" height="10"/><path d="M6 14V6h8"/>',
            'backward' => '<rect x="6" y="6" width="10" height="10"/><path d="M18 10v8h-8"/>',
            'align' => '<path d="M4 6h16M7 10h10M5 14h14M8 18h8"/>',
            'distribute' => '<path d="M4 5v14M20 5v14M8 8h3v8H8zM14 8h3v8h-3z"/>',
            'lock' => '<rect x="6" y="10" width="12" height="10" rx="2"/><path d="M9 10V7a3 3 0 016 0v3"/>',
            'bold' => '<path d="M8 5h5a4 4 0 010 8H8zM8 13h6a3 3 0 010 6H8z"/>',
            'italic' => '<path d="M10 5h7M7 19h7M14 5L10 19"/>',
            'underline' => '<path d="M7 5v7a5 5 0 0010 0V5M5 20h14"/>',
            'list' => '<path d="M9 7h11M9 12h11M9 17h11"/><circle cx="5" cy="7" r="1"/><circle cx="5" cy="12" r="1"/><circle cx="5" cy="17" r="1"/>',
            'link' => '<path d="M10 13a4 4 0 010-6l2-2a4 4 0 016 6l-1 1M14 11a4 4 0 010 6l-2 2a4 4 0 01-6-6l1-1"/>',
            'more' => '<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
            'preview' => '<path d="M3 12s3-6 9-6 9 6 9 6-3 6-9 6-9-6-9-6z"/><circle cx="12" cy="12" r="2.5"/>',
            'fullscreen' => '<path d="M8 4H4v4M16 4h4v4M20 16v4h-4M8 20H4v-4"/>',
            'undo' => '<path d="M9 7L5 11l4 4M6 11h7a5 5 0 010 10"/>',
            'redo' => '<path d="M15 7l4 4-4 4M18 11h-7a5 5 0 000 10"/>',
            'plus' => '<path d="M12 5v14M5 12h14"/>',
        );
        return '<svg viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? $paths['more']) . '</svg>';
    }

    private static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
