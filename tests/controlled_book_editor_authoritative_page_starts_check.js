"use strict";

const assert = require("assert");
const fs = require("fs");
const path = require("path");
const vm = require("vm");

const root = path.resolve(__dirname, "..");
const editor = fs.readFileSync(
  path.join(root, "public/assets/controlled_book_editor.js"),
  "utf8"
);
const start = editor.indexOf("function tmGenAuthoritativeEditorPageStartsEnabled(");
const end = editor.indexOf("function measurePrintFurnitureGeometry(", start);
assert(start >= 0 && end > start, "Unable to extract authoritative page-start helpers.");

const context = {
  state: {
    versionInfo: { manual_code: "TM_GEN" },
    authoritativeEditorPageStartsEnabled: true,
    authoritativeEditorPageStarts: [],
  },
  Promise,
};
vm.createContext(context);
vm.runInContext(editor.slice(start, end), context);

const result = {
  freshness: { is_current: true },
  pages: [
    {
      page_number: 10,
      metadata: {
        coverage: [
          { section_id: 8, source_fragment_id: "section-8/last/root", range_start: 0 },
          { section_id: 9, source_fragment_id: "section-9/first/root", range_start: 0 },
        ],
      },
    },
    {
      page_number: 11,
      metadata: {
        coverage: [
          { section_id: 9, source_fragment_id: "section-9/comment-form/root", range_start: 0 },
        ],
      },
    },
    {
      page_number: 12,
      metadata: {
        coverage: [
          { section_id: 9, source_fragment_id: "section-9/split-paragraph/root", range_start: 12 },
        ],
      },
    },
    {
      page_number: 13,
      metadata: {
        coverage: [
          { section_id: 9, source_fragment_id: "section-9/list/list-item-3", range_start: 0 },
        ],
      },
    },
    {
      page_number: 14,
      metadata: {
        coverage: [
          { section_id: 9, source_fragment_id: "section-9/final-paragraph/root", range_start: 0 },
        ],
      },
    },
  ],
};

assert.deepStrictEqual(
  JSON.parse(JSON.stringify(context.authoritativeEditorPageStartsFromResult(result, 9))),
  [
    { pageNumber: 11, sourceFragmentId: "section-9/comment-form/root" },
    { pageNumber: 14, sourceFragmentId: "section-9/final-paragraph/root" },
  ],
  "Only safe whole-block starts after the section's first page should be projected."
);

context.state.authoritativeEditorPageStarts = [
  { pageNumber: 11, sourceFragmentId: "section-9/comment-form/root" },
];
const blocks = ["first", "comment-form", "comment"].map((anchor) => ({
  getAttribute(name) {
    return name === "data-stable-anchor" ? anchor : null;
  },
}));
const anchors = context.authoritativeEditorPageStartAnchors({
  querySelectorAll() {
    return blocks;
  },
});
assert.deepStrictEqual(
  JSON.parse(JSON.stringify(anchors)),
  { "comment-form": true },
  "The authoritative fragment must resolve to the exact editable block anchor."
);

result.freshness.is_current = false;
assert.deepStrictEqual(
  JSON.parse(JSON.stringify(context.authoritativeEditorPageStartsFromResult(result, 9))),
  [],
  "Stale authoritative maps must never alter the editable layout."
);

context.state.versionInfo.manual_code = "OM";
assert.deepStrictEqual(
  JSON.parse(JSON.stringify(context.authoritativeEditorPageStartAnchors({
    querySelectorAll() {
      return blocks;
    },
  }))),
  {},
  "The projection must not leak outside TM_GEN."
);

process.stdout.write("Controlled book editor authoritative page starts check: PASS\n");
