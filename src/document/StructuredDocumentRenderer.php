<?php
declare(strict_types=1);

require_once __DIR__ . '/StructuredDocumentPayload.php';

/**
 * Shared structured document renderer used by form templates and future document editors.
 */
final class StructuredDocumentRenderer
{
    public const MODE_READ = 'read';
    public const MODE_EDIT = 'edit';

    /**
     * @param array<string,mixed> $document
     */
    public function renderDocument(array $document, string $mode = self::MODE_READ): string
    {
        $doc = StructuredDocumentPayload::normalizeDocument($document);
        $title = trim((string)($doc['title'] ?? ''));
        $body = $this->renderBlocks($doc['blocks'] ?? array(), $mode);
        if ($body === '') {
            $body = '<div class="sdoc-empty">Add a block to start building this template.</div>';
        }

        return '<div class="sdoc-sheet">'
            . '<div class="sdoc-sheet-body">'
            . ($title !== '' ? '<h1 class="sdoc-document-title">' . $this->h($title) . '</h1>' : '')
            . $body
            . '</div></div>';
    }

    /**
     * @param list<array<string,mixed>> $blocks
     */
    public function renderBlocks(array $blocks, string $mode = self::MODE_READ): string
    {
        $html = '';
        foreach ($blocks as $block) {
            if (is_array($block)) {
                $html .= $this->renderBlock($block, $mode);
            }
        }
        return $html;
    }

    /**
     * @param array<string,mixed> $block
     */
    public function renderBlock(array $block, string $mode = self::MODE_READ): string
    {
        $block = StructuredDocumentPayload::normalizeBlock($block);
        $type = (string)$block['block_type'];
        $key = (string)$block['block_key'];
        $payload = is_array($block['payload'] ?? null) ? $block['payload'] : array();
        $editable = $mode === self::MODE_EDIT;

        $chrome = $editable
            ? '<div class="sdoc-block-chrome" contenteditable="false">'
                . '<button type="button" class="sdoc-block-btn" data-sdoc-action="add-after">+</button>'
                . '<button type="button" class="sdoc-block-btn" data-sdoc-action="move-up">Up</button>'
                . '<button type="button" class="sdoc-block-btn" data-sdoc-action="move-down">Down</button>'
                . '<button type="button" class="sdoc-block-btn sdoc-block-btn--danger" data-sdoc-action="delete">Delete</button>'
                . '</div>'
            : '';

        $inner = match ($type) {
            'heading' => $this->renderHeading($payload, $editable),
            'table' => $this->renderTable($payload, $editable),
            'checkbox', 'field', 'date', 'signature', 'initial' => $this->renderField($type, $payload, $editable),
            default => $this->renderParagraph($payload, $editable),
        };

        return '<article class="sdoc-block sdoc-block--' . $this->h($type) . '"'
            . ' data-block-key="' . $this->h($key) . '"'
            . ' data-block-type="' . $this->h($type) . '">'
            . $chrome
            . $inner
            . '</article>';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHeading(array $payload, bool $editable): string
    {
        $level = max(1, min(6, (int)($payload['level'] ?? 2)));
        $text = trim((string)($payload['text'] ?? 'Heading'));
        return '<h' . $level . ' class="sdoc-heading" data-sdoc-prop="text"'
            . ($editable ? ' contenteditable="true"' : '')
            . '>' . $this->h($text) . '</h' . $level . '>';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderParagraph(array $payload, bool $editable): string
    {
        $html = (string)($payload['html'] ?? '');
        if ($html === '') {
            $html = 'Text block';
        }
        return '<div class="sdoc-paragraph" data-sdoc-prop="html"'
            . ($editable ? ' contenteditable="true"' : '')
            . '>' . $html . '</div>';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderTable(array $payload, bool $editable): string
    {
        $headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : array('Column 1', 'Column 2');
        $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : array(array('', ''));
        $title = trim((string)($payload['title'] ?? ''));

        $html = '<div class="sdoc-table-wrap" contenteditable="false">';
        if ($title !== '') {
            $html .= '<div class="sdoc-table-title" data-sdoc-prop="table_title"'
                . ($editable ? ' contenteditable="true"' : '')
                . '>' . $this->h($title) . '</div>';
        }
        $html .= '<table class="sdoc-table"><thead><tr>';
        foreach ($headers as $idx => $header) {
            $html .= '<th data-sdoc-table-header="' . (int)$idx . '"'
                . ($editable ? ' contenteditable="true"' : '')
                . '>' . $this->h((string)$header) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $rIdx => $row) {
            if (!is_array($row)) {
                continue;
            }
            $html .= '<tr>';
            foreach ($headers as $cIdx => $_header) {
                $value = (string)($row[$cIdx] ?? '');
                $html .= '<td data-sdoc-table-row="' . (int)$rIdx . '" data-sdoc-table-col="' . (int)$cIdx . '"'
                    . ($editable ? ' contenteditable="true"' : '')
                    . '>' . $this->h($value) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
        return $html;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderField(string $blockType, array $payload, bool $editable): string
    {
        $fieldKey = (string)($payload['field_key'] ?? '');
        $label = (string)($payload['label'] ?? 'Field');
        $fieldType = (string)($payload['field_type'] ?? $blockType);
        $role = (string)($payload['assigned_role'] ?? 'instructor');
        $variable = (string)($payload['variable_key'] ?? '');
        $required = !empty($payload['required']);
        $placeholder = (string)($payload['placeholder'] ?? '');

        $control = match ($fieldType) {
            'checkbox' => '<span class="sdoc-checkbox-box" aria-hidden="true"></span>',
            'date' => '<span class="sdoc-input-line">' . $this->h($placeholder !== '' ? $placeholder : 'Date') . '</span>',
            'signature' => '<span class="sdoc-signature-box">Signature</span>',
            'initial' => '<span class="sdoc-initial-box">Initial</span>',
            default => '<span class="sdoc-input-line">' . $this->h($placeholder !== '' ? $placeholder : 'Text') . '</span>',
        };

        return '<div class="sdoc-field" contenteditable="false"'
            . ' data-field-key="' . $this->h($fieldKey) . '"'
            . ' data-field-type="' . $this->h($fieldType) . '"'
            . ' data-assigned-role="' . $this->h($role) . '"'
            . ' data-variable-key="' . $this->h($variable) . '"'
            . ' data-required="' . ($required ? '1' : '0') . '">'
            . '<label class="sdoc-field-label">' . $this->h($label) . ($required ? ' <span class="sdoc-required">*</span>' : '') . '</label>'
            . $control
            . ($editable ? '<button type="button" class="sdoc-field-edit" data-sdoc-action="select-field">Field settings</button>' : '')
            . '</div>';
    }

    private function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
