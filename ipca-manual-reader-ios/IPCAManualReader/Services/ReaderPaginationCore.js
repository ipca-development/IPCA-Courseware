(function () {
  "use strict";

  const input = window.IPCAPaginationInput;
  if (!input || !input.source || !input.layout) {
    throw new Error("Missing pagination input.");
  }

  const NORMALIZER_VERSION = "reader-normalizer-v1";
  const ENGINE_VERSION = "live-authoritative-flow-v1";
  const VALIDATOR_VERSION = "pagination-validator-v2";
  const VALIDATION_TOLERANCE = 0.75;
  const source = input.source;
  const layout = input.layout;
  const officialPageByAnchor = input.officialPageByAnchor || {};
  const officialPageBySection = input.officialPageBySection || {};
  const officialPageTotal = Number(input.officialPageTotal || 0) || null;
  const host = document.getElementById("pagination-measure-host");
  const diagnostics = [];
  let sourceOrder = 0;

  const escapeHTML = (value) => String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");

  function diagnostic(code, severity, message, fragment, pageNumber) {
    diagnostics.push({
      code,
      severity,
      page_number: pageNumber || null,
      source_fragment_id: fragment ? fragment.id : null,
      message
    });
  }

  function tokenized(html, pageNumber, total) {
    return String(html || "")
      .replaceAll("{{page}}", String(pageNumber))
      .replaceAll("{page}", String(pageNumber))
      .replaceAll("{{page_total}}", String(total))
      .replaceAll("{page_total}", String(total))
      .replaceAll("Page: —", `Page: ${pageNumber}`)
      .replaceAll("Page:&nbsp;—", `Page: ${pageNumber}`);
  }

  function officialPageFor(fragmentValue) {
    if (!fragmentValue) return null;
    const anchorPage = Number(officialPageByAnchor[fragmentValue.anchor] || 0);
    if (anchorPage > 0) return anchorPage;
    const stableAnchor = String(fragmentValue.section.stable_anchor || "");
    const sectionAnchorPage = Number(officialPageByAnchor[stableAnchor] || 0);
    if (sectionAnchorPage > 0) return sectionAnchorPage;
    const sectionID = String(fragmentValue.section.section_id || "");
    const sectionPage = Number(officialPageBySection[sectionID] || 0);
    return sectionPage > 0 ? sectionPage : null;
  }

  function stableID(section, unit, suffix) {
    const sectionIdentity = String(section.stable_anchor || section.section_id || "section");
    const unitIdentity = String(unit.unit_key || `unit-${sourceOrder}`);
    return `${sectionIdentity}/${unitIdentity}/${suffix}`;
  }

  function semanticType(unit, root) {
    const declared = String(unit.block_type || "").toLowerCase();
    if (declared === "shell") return "shell";
    if (declared === "toc") return "toc";
    if (declared === "lep") return "lep";
    if (root.querySelector("table.cpb-lep-table, [data-lep-parts-table], tr.cpb-lep-part-row")) return "lep";
    if (declared === "heading" || root.matches("h1,h2,h3,h4") || root.querySelector("h1,h2,h3,h4")) return "heading";
    if (declared === "list" || root.matches("ol,ul") || root.querySelector("ol,ul")) return "list";
    if (declared === "table" || root.matches("table") || root.querySelector("table")) return "table";
    if (declared === "image" || root.matches("figure,img") || root.querySelector("figure,img")) return "figure";
    if (
      declared === "callout"
      || root.matches(".cpb-callout,.note,.warning,.caution")
      || root.querySelector(".cpb-callout,.note,.warning,.caution")
    ) {
      const className = root.className.toLowerCase();
      const text = root.textContent.trim().toLowerCase();
      if (className.includes("warning") || text.startsWith("warning")) return "warning";
      if (className.includes("caution") || text.startsWith("caution")) return "caution";
      return "note";
    }
    if (declared === "paragraph" || root.matches("p,.cpb-paragraph") || root.querySelector("p,.cpb-paragraph")) return "paragraph";
    if (declared === "toc") return "toc";
    return declared || "unknown";
  }

  function fragment(section, unit, suffix, type, html, text, options) {
    const value = String(text == null ? "" : text);
    const result = {
      id: stableID(section, unit, suffix),
      anchor: String(options.anchor || section.stable_anchor || stableID(section, unit, suffix)),
      sourceOrder: sourceOrder++,
      type,
      html: String(html || ""),
      textLength: value.length,
      text: value,
      section,
      blockId: Number(unit.block_id || 0),
      blockType: String(unit.block_type || type),
      atomic: Boolean(options.atomic),
      splittable: Boolean(options.splittable),
      forceBreakBefore: Boolean(options.forceBreakBefore),
      headingLevel: Number(options.headingLevel || 0),
      tableHeaderHTML: String(options.tableHeaderHTML || ""),
      tableShellHTML: String(options.tableShellHTML || ""),
      orderedStart: Number(options.orderedStart || 0),
      unsupported: Boolean(options.unsupported),
      paginationAuthority: String(
        options.paginationAuthority
        || unit.pagination_authority
        || section.pagination_authority
        || (section.flags && section.flags.pagination_authority)
        || "author"
      ),
      generatedItemSelector: String(options.generatedItemSelector || "")
    };
    if (result.unsupported) {
      diagnostic(
        "UNSUPPORTED_MARKUP",
        "warning",
        `Preserved unsupported source markup at ${result.id}.`,
        result,
        null
      );
    }
    return result;
  }

  const PUBLICATION_CHROME_SELECTOR = [
    ".cpb-dropzone",
    "[data-dropzone]",
    ".cpb-block-chrome",
    ".cpb-block-btn",
    ".cpb-table-tools",
    ".cpb-image-resize",
    ".cpb-image-rotate",
    ".cpb-page-layout-toggle",
    ".cpb-orientation-toggle",
    ".cpb-col-resize",
    ".cpb-image--empty",
    "[data-editor-only]",
    "[data-layout-toggle]",
    "[data-table-action]",
    "[data-table-tools-close]",
    "[data-image-action]",
    "button[data-action]"
  ].join(",");

  function stripPublicationChrome(root) {
    if (!root || !root.querySelectorAll) return root;
    root.querySelectorAll(PUBLICATION_CHROME_SELECTOR).forEach((node) => node.remove());
    if (root.matches && root.matches(PUBLICATION_CHROME_SELECTOR)) {
      const holder = document.createElement("div");
      holder.className = "reader-source-fragment-root";
      return holder;
    }
    Array.from(root.querySelectorAll("[contenteditable]")).forEach((node) => {
      node.removeAttribute("contenteditable");
    });
    if (root.hasAttribute && root.hasAttribute("contenteditable")) {
      root.removeAttribute("contenteditable");
    }
    return root;
  }

  function isNestedPageShell(node) {
    return Boolean(
      node
      && node.classList
      && node.classList.contains("cpb-sheet")
      && !node.classList.contains("reader-canonical-page")
    );
  }

  function neutralizePageGeometry(node) {
    if (!node || !node.style || !isNestedPageShell(node)) return node;
    node.style.setProperty("width", "100%", "important");
    node.style.setProperty("max-width", "100%", "important");
    node.style.setProperty("min-width", "0", "important");
    node.style.setProperty("height", "auto", "important");
    node.style.setProperty("min-height", "0", "important");
    node.style.setProperty("padding", "0", "important");
    node.style.setProperty("margin", "0", "important");
    node.style.setProperty("position", "static", "important");
    node.style.setProperty("inset", "auto", "important");
    node.style.setProperty("overflow", "visible", "important");
    node.classList.remove("cpb-sheet");
    return node;
  }

  function neutralizeNestedPageShells(root) {
    if (!root || !root.querySelectorAll) return root;
    const shells = [];
    if (isNestedPageShell(root)) shells.push(root);
    root.querySelectorAll(".cpb-sheet").forEach((node) => {
      if (isNestedPageShell(node)) shells.push(node);
    });
    shells.forEach(neutralizePageGeometry);
    return root;
  }

  function extractPageShell(root) {
    if (!root || root.nodeType !== Node.ELEMENT_NODE) return root;
    const clone = root.cloneNode(true);
    clone.querySelectorAll(
      "header.cpb-page-header, footer.cpb-page-footer, "
      + ".reader-page-header-region, .reader-page-footer-region"
    ).forEach((node) => node.remove());
    const sheet = isNestedPageShell(clone)
      ? clone
      : clone.querySelector(".cpb-sheet:not(.reader-canonical-page)");
    if (!sheet) {
      neutralizeNestedPageShells(clone);
      return clone;
    }
    const preferred = sheet.querySelector(":scope > .cpb-sheet-body")
      || sheet.querySelector(":scope > .cpb-lep")
      || sheet.querySelector(":scope > .cpb-part0")
      || sheet.querySelector(":scope > .cpb-toc")
      || sheet.querySelector(":scope > nav.cpb-toc");
    const children = Array.from(sheet.children);
    const extracted = preferred || (children.length === 1 ? children[0] : null);
    const content = extracted || sheet;
    neutralizeNestedPageShells(content);
    return content;
  }

  function rootFromHTML(html) {
    const holder = document.createElement("div");
    holder.innerHTML = String(html || "");
    stripPublicationChrome(holder);
    if (
      holder.children.length === 1
      && canonicalText([holder.childNodes[0]?.textContent])
        === canonicalText(Array.from(holder.childNodes).map((node) => node.textContent))
    ) {
      return holder.firstElementChild;
    }
    holder.className = "reader-source-fragment-root";
    return holder;
  }

  function canonicalText(values) {
    return values
      .map((value) => String(value || "").replace(/\s+/g, " ").trim())
      .filter(Boolean)
      .join(" ")
      .replace(/\s+/g, " ")
      .trim();
  }

  function canonicalContent(values) {
    return canonicalText(values).replace(/\s+/g, "");
  }

  function cloneShellWithNode(root, selector, node) {
    const clone = root.cloneNode(true);
    const target = clone.querySelector(selector);
    if (target) {
      target.replaceChildren(node.cloneNode(true));
      return clone.outerHTML;
    }
    return node.outerHTML;
  }

  function normalizeList(section, unit, root, forceBreakBefore) {
    const list = root.matches("ol,ul") ? root : root.querySelector("ol,ul");
    if (!list) return [];
    const items = Array.from(list.children).filter((node) => node.matches("li"));
    const ordered = list.tagName.toLowerCase() === "ol";
    const initialStart = Number(list.getAttribute("start") || 1);
    const output = items.map((item, index) => {
      const clone = root.cloneNode(true);
      const clonedList = clone.matches("ol,ul") ? clone : clone.querySelector("ol,ul");
      clonedList.replaceChildren(item.cloneNode(true));
      if (ordered) clonedList.setAttribute("start", String(initialStart + index));
      return fragment(section, unit, `li-${index}`, "listItem", clone.outerHTML, item.textContent, {
        anchor: item.getAttribute("data-stable-anchor") || root.getAttribute("data-stable-anchor"),
        atomic: true,
        splittable: true,
        forceBreakBefore: forceBreakBefore && index === 0,
        orderedStart: ordered ? initialStart + index : 0
      });
    });
    if (canonicalContent(output.map((item) => item.text)) !== canonicalContent([root.textContent])) {
      diagnostic(
        "LEGACY_COMPOSITE_LIST",
        "warning",
        `Preserved composite list ${stableID(section, unit, "root")} as one source fragment.`,
        null,
        null
      );
      return [fragment(section, unit, "root", "list", root.outerHTML, root.textContent, {
        anchor: root.getAttribute("data-stable-anchor"),
        atomic: true,
        splittable: true,
        forceBreakBefore
      })];
    }
    return output;
  }

  function normalizeTable(section, unit, root, forceBreakBefore) {
    const table = root.matches("table") ? root : root.querySelector("table");
    if (!table) return [];
    const header = table.querySelector("thead");
    const rows = Array.from(table.querySelectorAll("tbody > tr"));
    const caption = table.querySelector("caption");
    const colgroup = table.querySelector("colgroup");
    const tableClasses = table.getAttribute("class") || "";
    const tableStyle = table.getAttribute("style") || "";
    const tableOpen = `<table class="${escapeHTML(tableClasses)}" style="${escapeHTML(tableStyle)}">`;
    const prefix = tableOpen + (caption ? caption.outerHTML : "") + (colgroup ? colgroup.outerHTML : "");
    const headerHTML = header ? header.outerHTML : "";
    const output = [];

    if (header) {
      output.push(fragment(
        section,
        unit,
        "table-header",
        "tableHeader",
        `${prefix}${headerHTML}</table>`,
        header.textContent,
        {
          anchor: root.getAttribute("data-stable-anchor"),
          atomic: true,
          splittable: false,
          forceBreakBefore,
          tableHeaderHTML: `${prefix}${headerHTML}</table>`,
          tableShellHTML: root.outerHTML
        }
      ));
    }

    rows.forEach((row, index) => {
      output.push(fragment(
        section,
        unit,
        `table-row-${index}`,
        "tableRow",
        `${prefix}<tbody>${row.outerHTML}</tbody></table>`,
        row.textContent,
        {
          anchor: row.getAttribute("data-stable-anchor") || root.getAttribute("data-stable-anchor"),
          atomic: true,
          splittable: true,
          forceBreakBefore: !header && forceBreakBefore && index === 0,
          tableHeaderHTML: headerHTML ? `${prefix}${headerHTML}</table>` : "",
          tableShellHTML: root.outerHTML
        }
      ));
    });

    if (!header && rows.length === 0) {
      output.push(fragment(section, unit, "table", "table", root.outerHTML, root.textContent, {
        anchor: root.getAttribute("data-stable-anchor"),
        atomic: true,
        splittable: false,
        forceBreakBefore
      }));
    }
    const normalizedTableText = canonicalContent(output.map((item) => item.text));
    const sourceTableText = canonicalContent([root.textContent]);
    if (normalizedTableText !== sourceTableText) {
      diagnostic(
        "LEGACY_COMPOSITE_TABLE",
        "warning",
        `Preserved composite table ${stableID(section, unit, "root")} as one source fragment `
          + `because normalized text length ${normalizedTableText.length} did not match `
          + `source length ${sourceTableText.length}.`,
        null,
        null
      );
      return [fragment(section, unit, "root", "table", root.outerHTML, root.textContent, {
        anchor: root.getAttribute("data-stable-anchor"),
        atomic: true,
        splittable: false,
        forceBreakBefore
      })];
    }
    return output;
  }

  function normalizeToc(section, unit, root, forceBreakBefore) {
    const rows = Array.from(root.querySelectorAll(".cpb-toc-row"));
    if (!rows.length) {
      return [fragment(section, unit, "root", "toc", root.outerHTML, root.textContent, {
        anchor: root.getAttribute("data-stable-anchor"),
        atomic: true,
        splittable: true,
        forceBreakBefore
      })];
    }
    const output = rows.map((row, index) => {
      let html;
      let text;
      if (index === 0) {
        const clone = root.cloneNode(true);
        const cloneRows = Array.from(clone.querySelectorAll(".cpb-toc-row"));
        cloneRows.slice(1).forEach((node) => node.remove());
        html = clone.outerHTML;
        text = clone.textContent;
      } else {
        const nav = row.closest(".cpb-toc");
        const navClone = nav ? nav.cloneNode(false) : document.createElement("nav");
        navClone.appendChild(row.cloneNode(true));
        html = navClone.outerHTML;
        text = row.textContent;
      }
      return fragment(section, unit, `toc-row-${index}`, "tocRow", html, text, {
        anchor: row.getAttribute("data-stable-anchor") || section.stable_anchor,
        atomic: true,
        splittable: false,
        forceBreakBefore: forceBreakBefore && index === 0
      });
    });
    const normalizedTocText = canonicalContent(output.map((item) => item.text));
    const sourceTocText = canonicalContent([root.textContent]);
    if (normalizedTocText !== sourceTocText) {
      diagnostic(
        "TOC_NORMALIZATION_CONTENT_MISMATCH",
        "failure",
        `TOC normalization text length ${normalizedTocText.length} `
          + `did not match source length ${sourceTocText.length}.`,
        null,
        null
      );
    }
    return output;
  }

  function resolvePaginationAuthority(section, unit, root) {
    const isPart0 = Boolean(
      section
      && (
        section.is_part0
        || (section.flags && section.flags.is_part0)
      )
    );
    if (isPart0) return "generated";
    if (root && (
      (root.matches && root.matches("[data-system-managed='1']"))
      || String(root.getAttribute && root.getAttribute("data-system-managed") || "") === "1"
    )) {
      return "generated";
    }
    const declared = String(
      (unit && unit.pagination_authority)
      || (section && section.pagination_authority)
      || (section && section.flags && section.flags.pagination_authority)
      || ""
    ).toLowerCase();
    if (declared === "generated" || declared === "author") return declared;
    const key = String(section && section.section_key || "").toLowerCase();
    const type = String(unit && unit.block_type || "").toLowerCase();
    if (type === "toc" || type === "lep" || type === "generated" || key === "toc" || key === "lep") {
      return "generated";
    }
    const allowAuthor = Boolean(
      section && (section.allow_author_blocks || (section.flags && section.flags.allow_author_blocks))
    );
    const systemOwned = Boolean(
      (section && (section.is_generated || section.is_system_managed))
      || (section && section.flags && (section.flags.is_generated || section.flags.is_system_managed))
      || (unit && unit.is_system_managed)
    );
    if (systemOwned && !allowAuthor) return "generated";
    if (
      root
      && root.querySelector
      && root.querySelector(".cpb-toc-row, table.cpb-lep-table, [data-lep-parts-table], [data-part0-table]")
      && !allowAuthor
    ) {
      return "generated";
    }
    return "author";
  }

  function generatedRepeatableItems(root) {
    const selectors = [
      "tr.cpb-part0-amend-row",
      "tr.cpb-part0-dist-row",
      "tr.cpb-annex-register-row",
      ".cpb-part0-abbr-row",
      ".cpb-part0-def-row"
    ];
    for (let index = 0; index < selectors.length; index++) {
      const selector = selectors[index];
      const nodes = Array.from(root.querySelectorAll(selector));
      if (nodes.length) return { selector, nodes };
    }
    return null;
  }

  function generatedItemMarkup(node) {
    if (node.tagName && node.tagName.toLowerCase() === "tr") {
      const table = node.closest("table");
      const clone = table ? table.cloneNode(false) : document.createElement("table");
      if (table) {
        Array.from(table.attributes || []).forEach((attr) => {
          clone.setAttribute(attr.name, attr.value);
        });
      }
      const thead = table && table.querySelector("thead");
      if (thead) clone.appendChild(thead.cloneNode(true));
      const tbody = document.createElement("tbody");
      tbody.appendChild(node.cloneNode(true));
      clone.appendChild(tbody);
      return clone.outerHTML;
    }
    return node.outerHTML;
  }

  function normalizeGeneratedRepeatable(section, unit, root, forceBreakBefore, items) {
    const output = items.nodes.map((node, index) => {
      let html;
      let text;
      if (index === 0) {
        const clone = root.cloneNode(true);
        Array.from(clone.querySelectorAll(items.selector)).slice(1).forEach((extra) => extra.remove());
        html = clone.outerHTML;
        text = clone.textContent;
      } else {
        html = generatedItemMarkup(node);
        text = node.textContent;
      }
      return fragment(section, unit, `generated-row-${index}`, "generatedRow", html, text, {
        anchor: node.getAttribute("data-stable-anchor")
          || root.getAttribute("data-stable-anchor")
          || section.stable_anchor,
        atomic: true,
        splittable: false,
        forceBreakBefore: forceBreakBefore && index === 0,
        paginationAuthority: "generated",
        generatedItemSelector: items.selector
      });
    });
    const normalizedText = canonicalContent(output.map((item) => item.text));
    const sourceText = canonicalContent([root.textContent]);
    if (normalizedText !== sourceText) {
      diagnostic(
        "GENERATED_NORMALIZATION_CONTENT_MISMATCH",
        "failure",
        `Generated Part 0 normalization text length ${normalizedText.length} `
          + `did not match source length ${sourceText.length}.`,
        output[0] || null,
        null
      );
    }
    return output;
  }

  function isGeneratedLep(section, unit, root) {
    if (String(section && section.section_key || "").toLowerCase() === "lep") return true;
    if (String(unit && unit.block_type || "").toLowerCase() === "lep") return true;
    return Boolean(
      root
      && root.querySelector
      && root.querySelector("table.cpb-lep-table, [data-lep-parts-table], tr.cpb-lep-part-row")
    );
  }

  function lepRowNodes(root) {
    if (!root || !root.querySelectorAll) return [];
    const table = root.querySelector("table.cpb-lep-table, [data-lep-parts-table]")
      || root.querySelector("table");
    if (table) {
      const named = Array.from(table.querySelectorAll("tbody > tr.cpb-lep-part-row"));
      if (named.length) return named;
      return Array.from(table.querySelectorAll("tbody > tr"));
    }
    return Array.from(root.querySelectorAll("tr.cpb-lep-part-row"));
  }

  function lepRowTableMarkup(row) {
    const table = row.closest("table");
    const clone = table ? table.cloneNode(false) : document.createElement("table");
    if (table) {
      Array.from(table.attributes || []).forEach((attr) => {
        clone.setAttribute(attr.name, attr.value);
      });
    } else {
      clone.className = "cpb-table cpb-lep-table";
    }
    const thead = table && table.querySelector("thead");
    if (thead) clone.appendChild(thead.cloneNode(true));
    const tbody = document.createElement("tbody");
    tbody.appendChild(row.cloneNode(true));
    clone.appendChild(tbody);
    return clone.outerHTML;
  }

  function normalizeGeneratedLep(section, unit, root, forceBreakBefore) {
    const rows = lepRowNodes(root);
    if (!rows.length) {
      return [fragment(section, unit, "root", "lep", root.outerHTML, root.textContent, {
        anchor: root.getAttribute("data-stable-anchor") || section.stable_anchor,
        atomic: true,
        splittable: false,
        forceBreakBefore
      })];
    }
    const output = rows.map((row, index) => {
      let html;
      let text;
      if (index === 0) {
        const clone = root.cloneNode(true);
        lepRowNodes(clone).slice(1).forEach((node) => node.remove());
        html = clone.outerHTML;
        text = clone.textContent;
      } else {
        html = lepRowTableMarkup(row);
        text = row.textContent;
      }
      return fragment(section, unit, `lep-row-${index}`, "lepRow", html, text, {
        anchor: row.getAttribute("data-stable-anchor")
          || row.getAttribute("data-lep-part")
          || root.getAttribute("data-stable-anchor")
          || section.stable_anchor,
        atomic: true,
        splittable: false,
        forceBreakBefore: forceBreakBefore && index === 0
      });
    });
    const normalizedLepText = canonicalContent(output.map((item) => item.text));
    const sourceLepText = canonicalContent([root.textContent]);
    if (normalizedLepText !== sourceLepText) {
      diagnostic(
        "LEP_NORMALIZATION_CONTENT_MISMATCH",
        "failure",
        `LEP normalization text length ${normalizedLepText.length} `
          + `did not match source length ${sourceLepText.length}.`,
        output[0] || null,
        null
      );
    }
    return output;
  }

  function normalizeLep(section, unit, root, forceBreakBefore) {
    const lep = root.matches(".cpb-lep") ? root : root.querySelector(".cpb-lep");
    if (!lep) return [];
    const children = Array.from(lep.children);
    const output = [];
    for (let index = 0; index < children.length; index++) {
      const child = children[index];
      const wrapper = lep.cloneNode(false);
      wrapper.appendChild(child.cloneNode(true));
      const isHeading = child.classList.contains("cpb-lep-heading");
      if (isHeading) {
        while (
          index + 1 < children.length
          && children[index + 1].classList.contains("cpb-lep-heading")
        ) {
          index += 1;
          wrapper.appendChild(children[index].cloneNode(true));
        }
      }
      const derivedUnit = {
        ...unit,
        unit_key: `${unit.unit_key || "lep"}-part-${index}`,
        html: wrapper.outerHTML,
        block_type: child.querySelector("table") ? "table" : "paragraph"
      };
      if (child.querySelector("table")) {
        output.push(...normalizeTable(
          section,
          derivedUnit,
          wrapper,
          forceBreakBefore && output.length === 0
        ));
        continue;
      }
      output.push(fragment(
        section,
        derivedUnit,
        "root",
        isHeading ? "heading" : "lepBlock",
        wrapper.outerHTML,
        wrapper.textContent,
        {
          anchor: child.getAttribute("data-stable-anchor") || section.stable_anchor,
          atomic: true,
          splittable: !isHeading,
          forceBreakBefore: forceBreakBefore && output.length === 0,
          headingLevel: isHeading ? 2 : 0
        }
      ));
    }
    if (canonicalContent(output.map((item) => item.text)) !== canonicalContent([lep.textContent])) {
      diagnostic(
        "LEP_NORMALIZATION_CONTENT_MISMATCH",
        "failure",
        "LEP normalization did not preserve the complete source text in order.",
        output[0] || null,
        null
      );
    }
    return output;
  }

  function normalizeHistoricalContainer(section, unit, root, forceBreakBefore) {
    const selector = [
      "h1", "h2", "h3", "h4", "p", "ol", "ul", "table", "figure",
      ".cpb-callout", ".note", ".warning", ".caution"
    ].join(",");
    const candidates = Array.from(root.querySelectorAll(selector)).filter((node) => {
      const parentCandidate = node.parentElement && node.parentElement.closest(selector);
      return !parentCandidate || parentCandidate === node;
    });
    if (candidates.length < 1) return [];
    const output = [];
    candidates.forEach((candidate, index) => {
      const derivedUnit = {
        ...unit,
        unit_key: `${unit.unit_key || "legacy"}-semantic-${index}`,
        html: candidate.outerHTML,
        block_type: candidate.tagName.toLowerCase()
      };
      output.push(...normalizeUnit(section, derivedUnit, index));
    });
    if (canonicalContent(output.map((item) => item.text)) !== canonicalContent([root.textContent])) {
      return [];
    }
    diagnostic(
      "HISTORICAL_MARKUP_NORMALIZED",
      "info",
      `Derived ${output.length} semantic fragments from historical container markup.`,
      output[0] || null,
      null
    );
    if (output.length) output[0].forceBreakBefore = forceBreakBefore;
    return output;
  }

  function contentRootFromHTML(html) {
    return extractPageShell(rootFromHTML(html));
  }

  function normalizeUnit(section, unit, unitIndex) {
    const parsed = rootFromHTML(unit.html);
    if (!parsed) return [];
    const isCover = String(unit.block_type || "").toLowerCase() === "cover"
      || String(section && section.content_mode || "") === "cover";
    const root = isCover ? parsed : extractPageShell(parsed);
    if (
      !canonicalText([root.textContent])
      && !root.querySelector("img,svg,canvas,video")
    ) {
      return [];
    }
    const type = semanticType(unit, root);
    const forceBreakBefore = Boolean(
      unit.force_break_before
      || unit.manual_page_break_before
    );
    const authority = resolvePaginationAuthority(section, unit, root);
    unit = Object.assign({}, unit, { pagination_authority: authority });
    if (type === "toc" || (authority === "generated" && root.querySelector(".cpb-toc-row"))) {
      return normalizeToc(section, unit, root, forceBreakBefore);
    }
    if (isGeneratedLep(section, unit, root)) {
      return normalizeGeneratedLep(section, unit, root, forceBreakBefore);
    }
    if (authority === "generated") {
      const items = generatedRepeatableItems(root);
      if (items) return normalizeGeneratedRepeatable(section, unit, root, forceBreakBefore, items);
      if (type === "list") return normalizeList(section, unit, root, forceBreakBefore);
      if (type === "table") return normalizeTable(section, unit, root, forceBreakBefore);
      return [fragment(section, unit, "root", type === "unknown" ? "generated" : type, root.outerHTML, root.textContent, {
        anchor: root.getAttribute("data-stable-anchor") || section.stable_anchor,
        atomic: true,
        splittable: type === "paragraph",
        forceBreakBefore,
        paginationAuthority: "generated"
      })];
    }
    if (root.matches(".cpb-lep") || root.querySelector(".cpb-lep")) {
      return normalizeLep(section, unit, root, forceBreakBefore);
    }
    if (type === "list") return normalizeList(section, unit, root, forceBreakBefore);
    if (type === "table") return normalizeTable(section, unit, root, forceBreakBefore);
    if (type === "toc") return normalizeToc(section, unit, root, forceBreakBefore);
    if (type === "shell" || type === "unknown") {
      const historical = normalizeHistoricalContainer(section, unit, root, forceBreakBefore);
      if (historical.length) return historical;
    }
    const heading = root.matches("h1,h2,h3,h4")
      ? root
      : root.querySelector("h1,h2,h3,h4");
    const anchor = root.getAttribute("data-stable-anchor")
      || (heading && heading.getAttribute("id"))
      || section.stable_anchor;
    const supported = [
      "heading", "paragraph", "note", "warning", "caution", "figure", "toc", "lep", "shell", "generated"
    ].includes(type);
    return [fragment(section, unit, "root", type, root.outerHTML, root.textContent, {
      anchor,
      atomic: ["heading", "note", "warning", "caution", "figure", "toc", "lep", "shell", "generated"].includes(type),
      splittable: ["paragraph", "note", "warning", "caution"].includes(type),
      forceBreakBefore,
      headingLevel: heading ? Number(heading.tagName.substring(1)) : 0,
      unsupported: !supported,
      paginationAuthority: authority
    })];
  }

  function normalizeDocument() {
    const fragments = [];
    const sourceText = [];
    const sections = Array.isArray(source.sections) ? source.sections : [];
    sections.forEach((section) => {
      if (section.content_mode === "cover") {
        const unit = { unit_key: "cover", block_type: "cover" };
        fragments.push(fragment(
          section,
          unit,
          "root",
          "cover",
          String(section.cover_html || ""),
          rootFromHTML(section.cover_html).textContent,
          {
            anchor: section.stable_anchor,
            atomic: true,
            splittable: false,
            forceBreakBefore: true
          }
        ));
        sourceText.push(rootFromHTML(section.cover_html).textContent);
        return;
      }
      (Array.isArray(section.units) ? section.units : []).forEach((unit, index) => {
        sourceText.push(contentRootFromHTML(unit.html).textContent);
        fragments.push(...normalizeUnit(section, unit, index));
      });
    });
    if (canonicalContent(sourceText) !== canonicalContent(fragments.map((item) => item.text))) {
      diagnostic(
        "NORMALIZATION_CONTENT_MISMATCH",
        "failure",
        "Read-only normalization changed, dropped, duplicated, or reordered source text.",
        null,
        null
      );
    }
    return { fragments, sections };
  }

  function cloneTextRangeHTML(sourceHTML, start, end, continuation) {
    const root = rootFromHTML(sourceHTML).cloneNode(true);
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    let offset = 0;
    const remove = [];
    while (walker.nextNode()) {
      const node = walker.currentNode;
      const nodeStart = offset;
      const nodeEnd = offset + node.nodeValue.length;
      offset = nodeEnd;
      if (nodeEnd <= start || nodeStart >= end) {
        remove.push(node);
        continue;
      }
      const localStart = Math.max(0, start - nodeStart);
      const localEnd = Math.min(node.nodeValue.length, end - nodeStart);
      node.nodeValue = node.nodeValue.slice(localStart, localEnd);
    }
    remove.forEach((node) => node.remove());
    root.querySelectorAll("*").forEach((node) => {
      if (!node.textContent && !node.matches("img,br,hr,td,th")) node.remove();
    });
    root.setAttribute("data-reader-fragment-continuation", continuation ? "1" : "0");
    return root.outerHTML;
  }

  function piece(fragmentValue, html, start, end, presentationCopy) {
    return {
      fragment: fragmentValue,
      html,
      rangeStart: start,
      rangeEnd: end,
      presentationCopy: Boolean(presentationCopy)
    };
  }

  function pageScale() {
    return Number(layout.pageScale);
  }

  function canonicalRect(rect) {
    const scale = pageScale();
    return {
      x: rect.x / scale,
      y: rect.y / scale,
      width: rect.width / scale,
      height: rect.height / scale
    };
  }

  function frameStyle(rect) {
    return [
      `left:${rect.x}px`,
      `top:${rect.y}px`,
      `width:${rect.width}px`,
      `height:${rect.height}px`
    ].join(";");
  }

  function pieceMarkup(value) {
    const root = contentRootFromHTML(value.html).cloneNode(true);
    if (!root || root.nodeType !== Node.ELEMENT_NODE) {
      return `<div class="reader-semantic-piece" style="align-self:start" `
        + `data-block-id="${value.fragment.blockId || ""}" `
        + `data-block-type="${escapeHTML(value.fragment.blockType || "")}" `
        + `data-stable-anchor="${escapeHTML(value.fragment.anchor || "")}" `
        + `data-source-fragment-id="${escapeHTML(value.fragment.id)}" `
        + `data-source-order="${value.fragment.sourceOrder}" `
        + `data-source-range-start="${value.rangeStart}" `
        + `data-source-range-end="${value.rangeEnd}" `
        + `data-source-length="${value.fragment.textLength}" `
        + `data-presentation-copy="${value.presentationCopy ? "1" : "0"}" `
        + `data-semantic-type="${escapeHTML(value.fragment.type)}">${value.html}</div>`;
    }
    root.classList.add("reader-semantic-piece");
    root.style.setProperty("align-self", "start");
    annotateSourceBinding(root, value.fragment);
    annotateCoverage(root, value);
    root.setAttribute("data-semantic-type", value.fragment.type);
    return root.outerHTML;
  }

  function annotateCoverage(node, value) {
    node.setAttribute("data-source-fragment-id", value.fragment.id);
    node.setAttribute("data-source-order", String(value.fragment.sourceOrder));
    node.setAttribute("data-source-range-start", String(value.rangeStart));
    node.setAttribute("data-source-range-end", String(value.rangeEnd));
    node.setAttribute("data-source-length", String(value.fragment.textLength));
    node.setAttribute("data-presentation-copy", value.presentationCopy ? "1" : "0");
  }

  function annotateSourceBinding(node, fragmentValue) {
    if (fragmentValue.blockId > 0) {
      node.setAttribute("data-block-id", String(fragmentValue.blockId));
    }
    if (fragmentValue.blockType && !node.getAttribute("data-block-type")) {
      node.setAttribute("data-block-type", fragmentValue.blockType);
    }
    if (fragmentValue.anchor && !node.getAttribute("data-stable-anchor")) {
      node.setAttribute("data-stable-anchor", fragmentValue.anchor);
    }
  }

  function tableGroupMarkup(values) {
    const firstRoot = contentRootFromHTML(values[0].html);
    const firstTable = firstRoot.matches("table") ? firstRoot : firstRoot.querySelector("table");
    if (!firstTable) return values.map(pieceMarkup).join("");
    const table = firstTable.cloneNode(true);
    table.querySelectorAll("thead,tbody,tfoot").forEach((node) => node.remove());
    let header = null;
    const body = document.createElement("tbody");
    values.forEach((value) => {
      const sourceRoot = contentRootFromHTML(value.html);
      const sourceTable = sourceRoot.matches("table")
        ? sourceRoot
        : sourceRoot.querySelector("table");
      if (!sourceTable) return;
      if (value.fragment.type === "tableHeader" && !header) {
        header = sourceTable.querySelector("thead")?.cloneNode(true) || null;
        if (header) annotateCoverage(header, value);
      }
      if (value.fragment.type === "tableRow") {
        sourceTable.querySelectorAll("tbody > tr").forEach((row) => {
          const clonedRow = row.cloneNode(true);
          annotateCoverage(clonedRow, value);
          body.appendChild(clonedRow);
        });
      }
    });
    if (header) table.appendChild(header);
    if (body.children.length) table.appendChild(body);
    let renderedTable = table.outerHTML;
    const shellHTML = values[0].fragment.tableShellHTML || "";
    if (shellHTML) {
      const shell = rootFromHTML(shellHTML).cloneNode(true);
      const shellTable = shell.querySelector("table");
      if (shellTable) {
        shellTable.replaceWith(table);
        renderedTable = shell.outerHTML;
      }
    } else if (!firstRoot.matches("table")) {
      const shell = firstRoot.cloneNode(true);
      const shellTable = shell.querySelector("table");
      if (shellTable) {
        shellTable.replaceWith(table);
        renderedTable = shell.outerHTML;
      }
    }
    const renderedRoot = rootFromHTML(renderedTable).cloneNode(true);
    renderedRoot.classList.add("reader-semantic-piece", "reader-table-group");
    renderedRoot.style.setProperty("align-self", "start");
    annotateSourceBinding(renderedRoot, values[0].fragment);
    renderedRoot.setAttribute("data-semantic-type", "table");
    return renderedRoot.outerHTML;
  }

  function tocGroupMarkup(values) {
    const root = contentRootFromHTML(values[0].html).cloneNode(true);
    let nav = root.matches(".cpb-toc") ? root : root.querySelector(".cpb-toc");
    if (!nav) return values.map(pieceMarkup).join("");
    nav.querySelectorAll(".cpb-toc-row").forEach((row) => row.remove());
    values.forEach((value) => {
      const sourceRoot = contentRootFromHTML(value.html);
      sourceRoot.querySelectorAll(".cpb-toc-row").forEach((row) => {
        const clone = row.cloneNode(true);
        annotateCoverage(clone, value);
        nav.appendChild(clone);
      });
    });
    root.classList.add("reader-semantic-piece", "reader-toc-group");
    annotateSourceBinding(root, values[0].fragment);
    root.setAttribute("data-semantic-type", "toc");
    return root.outerHTML;
  }

  function lepGroupMarkup(values) {
    if (!values.length) return "";
    const root = contentRootFromHTML(values[0].html).cloneNode(true);
    const table = root.matches("table")
      ? root
      : root.querySelector("table.cpb-lep-table, [data-lep-parts-table], table");
    if (!table) return values.map(pieceMarkup).join("");
    let tbody = table.querySelector("tbody");
    if (!tbody) {
      tbody = document.createElement("tbody");
      table.appendChild(tbody);
    }
    tbody.replaceChildren();
    values.forEach((value) => {
      const sourceRoot = contentRootFromHTML(value.html);
      lepRowNodes(sourceRoot).forEach((row) => {
        const clone = row.cloneNode(true);
        annotateCoverage(clone, value);
        tbody.appendChild(clone);
      });
    });
    root.classList.add("reader-semantic-piece", "reader-lep-group");
    root.style.setProperty("align-self", "start");
    annotateSourceBinding(root, values[0].fragment);
    root.setAttribute("data-semantic-type", "lep");
    return root.outerHTML;
  }

  function generatedGroupMarkup(values) {
    if (!values.length) return "";
    const selector = String(values[0].fragment.generatedItemSelector || "");
    if (!selector) return values.map(pieceMarkup).join("");
    const root = contentRootFromHTML(values[0].html).cloneNode(true);
    const existing = Array.from(root.querySelectorAll(selector));
    if (!existing.length) return values.map(pieceMarkup).join("");
    const parent = existing[0].parentNode;
    existing.forEach((node) => node.remove());
    values.forEach((value) => {
      const sourceRoot = contentRootFromHTML(value.html);
      sourceRoot.querySelectorAll(selector).forEach((node) => {
        const clone = node.cloneNode(true);
        annotateCoverage(clone, value);
        parent.appendChild(clone);
      });
    });
    root.classList.add("reader-semantic-piece", "reader-generated-group");
    root.style.setProperty("align-self", "start");
    annotateSourceBinding(root, values[0].fragment);
    root.setAttribute("data-semantic-type", "generated");
    return root.outerHTML;
  }

  function pagePiecesMarkup(values) {
    const output = [];
    let index = 0;
    while (index < values.length) {
      const value = values[index];
      if (value.fragment.type === "tocRow") {
        const group = [];
        while (index < values.length && values[index].fragment.type === "tocRow") {
          group.push(values[index]);
          index++;
        }
        output.push(tocGroupMarkup(group));
        continue;
      }
      if (value.fragment.type === "lepRow" || value.fragment.type === "lep") {
        const group = [];
        while (
          index < values.length
          && (values[index].fragment.type === "lepRow" || values[index].fragment.type === "lep")
        ) {
          group.push(values[index]);
          index++;
        }
        output.push(lepGroupMarkup(group));
        continue;
      }
      if (value.fragment.type === "generatedRow") {
        const group = [];
        while (index < values.length && values[index].fragment.type === "generatedRow") {
          group.push(values[index]);
          index++;
        }
        output.push(generatedGroupMarkup(group));
        continue;
      }
      if (!["tableHeader", "tableRow"].includes(value.fragment.type)) {
        output.push(pieceMarkup(value));
        index++;
        continue;
      }
      const group = [];
      while (
        index < values.length
        && ["tableHeader", "tableRow"].includes(values[index].fragment.type)
      ) {
        group.push(values[index]);
        index++;
      }
      output.push(tableGroupMarkup(group));
    }
    return output.join("");
  }

  function coverMarkup(value) {
    const root = rootFromHTML(value.html);
    const sheet = root.matches(".cpb-sheet") ? root : root.querySelector(".cpb-sheet");
    if (!sheet) return pieceMarkup(value);
    const renderedSheet = sheet.cloneNode(true);
    renderedSheet.style.setProperty("box-sizing", "border-box", "important");
    renderedSheet.style.setProperty("width", `${layout.canonicalPageWidth}px`, "important");
    renderedSheet.style.setProperty("height", `${layout.canonicalPageHeight}px`, "important");
    renderedSheet.style.setProperty("min-height", `${layout.canonicalPageHeight}px`, "important");
    renderedSheet.style.setProperty("max-width", "none", "important");
    renderedSheet.style.setProperty("margin", "0", "important");
    renderedSheet.style.setProperty(
      "padding",
      `${layout.topMargin / pageScale()}px ${layout.innerMargin / pageScale()}px `
        + `${layout.bottomMargin / pageScale()}px`,
      "important"
    );
    renderedSheet.style.setProperty("transform", "none", "important");
    renderedSheet.style.setProperty("zoom", "1", "important");
    return `<div class="reader-semantic-piece reader-cover-scale" `
      + `data-source-fragment-id="${escapeHTML(value.fragment.id)}" `
      + `data-source-order="${value.fragment.sourceOrder}" `
      + `data-source-range-start="${value.rangeStart}" `
      + `data-source-range-end="${value.rangeEnd}" `
      + `data-presentation-copy="0" data-semantic-type="cover" `
      + `style="position:absolute;inset:0;width:${layout.canonicalPageWidth}px;`
      + `height:${layout.canonicalPageHeight}px">${renderedSheet.outerHTML}</div>`;
  }

  function buildPageElement(page, pageNumber, total) {
    const section = page.section || {};
    const firstSourcePiece = page.pieces.find((value) => !value.presentationCopy);
    const documentPageNumber = officialPageFor(firstSourcePiece?.fragment) || pageNumber;
    const documentPageTotal = officialPageTotal || total;
    const header = section.show_header_footer
      ? tokenized(section.header_template, documentPageNumber, documentPageTotal)
      : "";
    const footer = section.show_header_footer
      ? tokenized(section.footer_template, documentPageNumber, documentPageTotal)
      : "";
    const element = document.createElement("div");
    element.className = "reader-generated-page";
    element.setAttribute("data-reader-page", String(pageNumber));
    element.style.cssText = `position:relative;box-sizing:border-box;width:${layout.pageWidth}px;`
      + `height:${layout.pageHeight}px;margin:0;padding:0;overflow:hidden;`;
    const canonicalPageStyle = `position:absolute;box-sizing:border-box;left:0;top:0;`
      + `width:${layout.canonicalPageWidth}px;height:${layout.canonicalPageHeight}px;`
      + `min-height:${layout.canonicalPageHeight}px;max-width:none;margin:0;padding:0;`
      + `box-shadow:none;border-radius:0;`
      + `transform-origin:top left;transform:scale(var(--reader-page-scale));`;
    if (page.isCover) {
      element.innerHTML = `
        <div class="reader-canonical-page cpb-sheet" style="${canonicalPageStyle}">
          <main class="reader-page-body reader-page-cover" data-blocks-root="1"
            style="position:absolute;box-sizing:border-box;inset:0;width:${layout.canonicalPageWidth}px;height:${layout.canonicalPageHeight}px;overflow:hidden">
            ${page.pieces.map(coverMarkup).join("")}
          </main>
        </div>
      `;
      applyReaderScale(element);
      return element;
    }
    const headerFrame = canonicalRect(layout.headerFrame);
    const contentFrame = canonicalRect(layout.contentFrame);
    const footerFrame = canonicalRect(layout.footerFrame);
    element.innerHTML = `
      <div class="reader-canonical-page cpb-sheet" style="${canonicalPageStyle}">
        <div class="reader-page-header-region" style="position:absolute;box-sizing:border-box;overflow:hidden;${frameStyle(headerFrame)}">${header}</div>
        <main class="reader-page-body cpb-sheet-body" data-blocks-root="1" style="position:absolute;box-sizing:border-box;align-content:start;${frameStyle(contentFrame)};overflow:hidden">${pagePiecesMarkup(page.pieces)}</main>
        <div class="reader-page-footer-region" style="position:absolute;box-sizing:border-box;display:flex;flex-direction:column;justify-content:flex-end;overflow:hidden;${frameStyle(footerFrame)}">${footer}</div>
      </div>
    `;
    applyReaderScale(element);
    return element;
  }

  function applyReaderScale(root) {
    root.style.setProperty("--reader-page-scale", String(pageScale()));
    const body = root.querySelector(".reader-page-body:not(.reader-page-cover)");
    if (body) body.style.setProperty("--reader-font-scale", String(layout.fontScale));
    root.querySelectorAll(".reader-page-header-region,.reader-page-footer-region").forEach((region) => {
      region.style.setProperty("--reader-font-scale", "1");
    });
  }

  function measurePage(page) {
    const element = buildPageElement(page, 1, 1);
    host.replaceChildren(element);
    const body = element.querySelector(".reader-page-body");
    const pageRect = element.getBoundingClientRect();
    const header = element.querySelector(".reader-page-header-region");
    const footer = element.querySelector(".reader-page-footer-region");
    const relativeRect = (node) => {
      if (!node) return null;
      const rect = node.getBoundingClientRect();
      return {
        x: rect.left - pageRect.left,
        y: rect.top - pageRect.top,
        width: rect.width,
        height: rect.height
      };
    };
    const scale = pageScale();
    const horizontalOverflow = (body.scrollWidth - body.clientWidth) * scale;
    const verticalOverflow = (body.scrollHeight - body.clientHeight) * scale;
    const bodyRect = body.getBoundingClientRect();
    const blocks = Array.from(body.querySelectorAll(":scope > .reader-semantic-piece"));
    const blockBounds = blocks.map((block) => {
      const rect = block.getBoundingClientRect();
      const identified = block.hasAttribute("data-source-fragment-id")
        ? block
        : block.querySelector("[data-source-fragment-id]");
      return {
        id: identified?.getAttribute("data-source-fragment-id") || null,
        left: rect.left - bodyRect.left,
        top: rect.top - bodyRect.top,
        right: rect.right - bodyRect.left,
        bottom: rect.bottom - bodyRect.top
      };
    });
    const tolerance = VALIDATION_TOLERANCE;
    const offendingBlock = blockBounds.find((block) =>
      block.left < -tolerance
      || block.top < -tolerance
      || block.right > bodyRect.width + tolerance
      || block.bottom > bodyRect.height + tolerance
    ) || null;
    const overflowingDescendant = Array.from(body.querySelectorAll("*")).map((node) => {
      const rect = node.getBoundingClientRect();
      return {
        tag: node.tagName.toLowerCase(),
        className: String(node.className || ""),
        left: rect.left - bodyRect.left,
        right: rect.right - bodyRect.left,
        top: rect.top - bodyRect.top,
        bottom: rect.bottom - bodyRect.top
      };
    }).find((rect) =>
      rect.left < -tolerance
      || rect.right > bodyRect.width + tolerance
      || rect.top < -tolerance
      || rect.bottom > bodyRect.height + tolerance
    ) || null;
    const regionOverflow = (region) => {
      if (!region) return { horizontal: 0, vertical: 0 };
      const bounds = region.getBoundingClientRect();
      const descendants = Array.from(region.querySelectorAll("*"));
      const overflow = descendants.reduce((maximum, node) => {
        const rect = node.getBoundingClientRect();
        return {
          horizontal: Math.max(
            maximum.horizontal,
            bounds.left - rect.left,
            rect.right - bounds.right
          ),
          vertical: Math.max(
            maximum.vertical,
            bounds.top - rect.top,
            rect.bottom - bounds.bottom
          )
        };
      }, { horizontal: 0, vertical: 0 });
      return {
        horizontal: Math.max(0, overflow.horizontal) * scale,
        vertical: Math.max(0, overflow.vertical) * scale
      };
    };
    const headerOverflow = regionOverflow(header);
    const footerOverflow = regionOverflow(footer);
    const headerRect = relativeRect(header);
    const footerRect = relativeRect(footer);
    const bodyRelativeRect = relativeRect(body);
    const contentFrame = layout.contentFrame;
    const headerFrame = layout.headerFrame;
    const footerFrame = layout.footerFrame;
    const firstBlock = blockBounds[0] || null;
    const lastBlock = blockBounds.length ? blockBounds[blockBounds.length - 1] : null;
    const firstBodyPageY = firstBlock && bodyRelativeRect
      ? bodyRelativeRect.y + firstBlock.top
      : null;
    const lastBodyPageY = lastBlock && bodyRelativeRect
      ? bodyRelativeRect.y + lastBlock.bottom
      : null;
    const headerInsideFrame = !headerRect || rectInside(headerRect, headerFrame);
    const bodyInsideFrame = !bodyRelativeRect || rectInside(bodyRelativeRect, contentFrame);
    const footerInsideFrame = !footerRect || rectInside(footerRect, footerFrame);
    const headerBodyIntersect = rectsIntersect(headerRect, bodyRelativeRect);
    const bodyFooterIntersect = rectsIntersect(bodyRelativeRect, footerRect);
    const firstBodyAboveContent = firstBodyPageY != null
      && firstBodyPageY < contentFrame.y - tolerance;
    const lastBodyBelowContent = lastBodyPageY != null
      && lastBodyPageY > contentFrame.y + contentFrame.height + tolerance;
    const rectInsidePage = (rect) => Boolean(rect)
      && rect.x >= -tolerance
      && rect.y >= -tolerance
      && rect.x + rect.width <= layout.pageWidth + tolerance
      && rect.y + rect.height <= layout.pageHeight + tolerance;
    const requiresHeaderFooter = !page.isCover
      && Boolean(page.section && page.section.show_header_footer);
    const hardFailure = horizontalOverflow > tolerance
      || verticalOverflow > tolerance
      || (requiresHeaderFooter && headerOverflow.horizontal > tolerance)
      || (requiresHeaderFooter && headerOverflow.vertical > tolerance)
      || (requiresHeaderFooter && footerOverflow.horizontal > tolerance)
      || (requiresHeaderFooter && footerOverflow.vertical > tolerance)
      || (requiresHeaderFooter && !rectInsidePage(headerRect))
      || !rectInsidePage(bodyRelativeRect)
      || (requiresHeaderFooter && !rectInsidePage(footerRect))
      || Boolean(offendingBlock)
      || (requiresHeaderFooter && !headerInsideFrame)
      || (!page.isCover && !bodyInsideFrame)
      || (requiresHeaderFooter && !footerInsideFrame)
      || (requiresHeaderFooter && headerBodyIntersect)
      || (requiresHeaderFooter && bodyFooterIntersect)
      || (!page.isCover && firstBodyAboveContent)
      || (!page.isCover && lastBodyBelowContent);
    return {
      fits: !hardFailure,
      horizontalOverflow,
      verticalOverflow,
      scrollWidth: body.scrollWidth * scale,
      clientWidth: body.clientWidth * scale,
      scrollHeight: body.scrollHeight * scale,
      clientHeight: body.clientHeight * scale,
      bodyHeight: body.scrollHeight * scale,
      maxBlockY: blockBounds.reduce((maximum, block) => Math.max(maximum, block.bottom), 0),
      offendingBlockID: offendingBlock?.id || null,
      overflowingDescendant,
      headerOverflow,
      footerOverflow,
      imageRect: relativeRect(body.querySelector("img")),
      figureRect: relativeRect(body.querySelector("figure")),
      headerRect,
      bodyRect: bodyRelativeRect,
      footerRect,
      firstBodyPageY,
      lastBodyPageY,
      headerInsideFrame,
      bodyInsideFrame,
      footerInsideFrame,
      headerBodyIntersect,
      bodyFooterIntersect,
      firstBodyAboveContent,
      lastBodyBelowContent
    };
  }

  function validateLayoutContract() {
    const scale = pageScale();
    const derivedScale = layout.pageWidth / layout.canonicalPageWidth;
    if (
      !Number.isFinite(scale)
      || scale <= 0
      || layout.canonicalPageWidth <= 0
      || layout.canonicalPageHeight <= 0
    ) {
      throw new Error("Canonical page dimensions or presentation scale are invalid.");
    }
    if (Math.abs(scale - derivedScale) > 0.0001) {
      throw new Error("Serialized pageScale does not match the authoritative page width.");
    }
    const expectedSpreadWidth = layout.mode === "twoPageSpread"
      ? (layout.pageWidth * 2) + layout.gutterWidth
      : layout.pageWidth;
    const availableWidth = layout.viewportWidth
      - layout.safeAreaInsets.leading
      - layout.safeAreaInsets.trailing;
    const availableHeight = layout.viewportHeight
      - layout.safeAreaInsets.top
      - layout.safeAreaInsets.bottom;
    if (expectedSpreadWidth > availableWidth + 0.01 || layout.pageHeight > availableHeight + 0.01) {
      throw new Error("Page or spread lies outside the safe reader viewport.");
    }
    const frames = [layout.headerFrame, layout.contentFrame, layout.footerFrame];
    const valid = frames.every((frame) => frame
      && frame.x >= 0
      && frame.y >= 0
      && frame.width >= 0
      && frame.height >= 0
      && frame.x + frame.width <= layout.pageWidth + 0.01
      && frame.y + frame.height <= layout.pageHeight + 0.01);
    if (!valid) throw new Error("Page frame lies outside the authoritative page bounds.");
    if (layout.headerFrame.y + layout.headerFrame.height > layout.contentFrame.y + 0.01) {
      throw new Error("Header frame overlaps the content frame.");
    }
    if (layout.contentFrame.y + layout.contentFrame.height > layout.footerFrame.y + 0.01) {
      throw new Error("Content frame overlaps the footer frame.");
    }
    const headerBodyGap = layout.contentFrame.y
      - (layout.headerFrame.y + layout.headerFrame.height);
    const bodyFooterGap = layout.footerFrame.y
      - (layout.contentFrame.y + layout.contentFrame.height);
    const requiredHeaderGap = Number(layout.headerBodySpacing);
    const requiredFooterGap = Number(layout.bodyFooterSpacing);
    if (Number.isFinite(requiredHeaderGap) && headerBodyGap + 0.01 < requiredHeaderGap) {
      throw new Error("Content frame does not honor Book Style header/body spacing.");
    }
    if (Number.isFinite(requiredFooterGap) && bodyFooterGap + 0.01 < requiredFooterGap) {
      throw new Error("Content frame does not honor Book Style body/footer spacing.");
    }
    const canonicalFrames = frames.map(canonicalRect);
    if (!canonicalFrames.every((frame) =>
      frame.x + frame.width <= layout.canonicalPageWidth + 0.01
      && frame.y + frame.height <= layout.canonicalPageHeight + 0.01
    )) {
      throw new Error("Canonical page frame lies outside the manifest page bounds.");
    }
  }

  function rectsIntersect(a, b, tolerance) {
    if (!a || !b) return false;
    const pad = Number.isFinite(tolerance) ? tolerance : VALIDATION_TOLERANCE;
    return a.x + a.width > b.x + pad
      && b.x + b.width > a.x + pad
      && a.y + a.height > b.y + pad
      && b.y + b.height > a.y + pad;
  }

  function rectInside(inner, outer, tolerance) {
    if (!inner || !outer) return false;
    const pad = Number.isFinite(tolerance) ? tolerance : VALIDATION_TOLERANCE;
    return inner.x >= outer.x - pad
      && inner.y >= outer.y - pad
      && inner.x + inner.width <= outer.x + outer.width + pad
      && inner.y + inner.height <= outer.y + outer.height + pad;
  }

  function rectMatches(actual, expected) {
    if (!actual || !expected) return false;
    return ["x", "y", "width", "height"].every(
      (key) => Math.abs(Number(actual[key]) - Number(expected[key])) <= 0.75
    );
  }

  function pageWith(section, pieces, flags) {
    return {
      section,
      pieces: pieces.slice(),
      isCover: Boolean(flags && flags.isCover),
      isSectionStart: Boolean(flags && flags.isSectionStart),
      isMajorSectionStart: Boolean(flags && flags.isMajorSectionStart),
      breakReason: String(flags && flags.breakReason || ""),
      decisions: []
    };
  }

  function meaningfulFollowingPiece(fragmentValue) {
    if (!fragmentValue) return null;
    const minimumLength = Math.min(fragmentValue.textLength, 160);
    if (minimumLength <= 0) {
      return piece(fragmentValue, fragmentValue.html, 0, 0, false);
    }
    if (fragmentValue.atomic && fragmentValue.type !== "paragraph") {
      return piece(fragmentValue, fragmentValue.html, 0, fragmentValue.textLength, false);
    }
    return piece(
      fragmentValue,
      cloneTextRangeHTML(fragmentValue.html, 0, minimumLength, false),
      0,
      minimumLength,
      false
    );
  }

  function bestTextSplit(page, fragmentValue, start) {
    const minimumRemaining = 2;
    let low = start + 1;
    let high = fragmentValue.textLength;
    let best = -1;
    while (low <= high) {
      const middle = Math.floor((low + high) / 2);
      const candidate = piece(
        fragmentValue,
        cloneTextRangeHTML(fragmentValue.html, start, middle, start > 0),
        start,
        middle,
        false
      );
      const trial = pageWith(page.section, page.pieces.concat([candidate]), {});
      if (measurePage(trial).fits) {
        best = middle;
        low = middle + 1;
      } else {
        high = middle - 1;
      }
    }
    if (best <= start) return null;
    const breakable = fragmentValue.text;
    while (best > start + 1 && best < breakable.length && !/\s/.test(breakable[best])) best--;
    if (best <= start) return null;

    const first = piece(
      fragmentValue,
      cloneTextRangeHTML(fragmentValue.html, start, best, start > 0),
      start,
      best,
      false
    );
    const remainingText = fragmentValue.text.slice(best).trim();
    const firstText = fragmentValue.text.slice(start, best).trim();
    if (
      remainingText.split(/\s+/).length < minimumRemaining
      && firstText.split(/\s+/).length > minimumRemaining * 2
    ) {
      const words = firstText.split(/\s+/);
      const move = words.slice(-minimumRemaining).join(" ");
      best = Math.max(start + 1, best - move.length - 1);
      return piece(
        fragmentValue,
        cloneTextRangeHTML(fragmentValue.html, start, best, start > 0),
        start,
        best,
        false
      );
    }
    return first;
  }

  function scaledFigurePiece(fragmentValue, maximumImageHeight) {
    const canonicalContent = canonicalRect(layout.contentFrame);
    const root = rootFromHTML(fragmentValue.html).cloneNode(true);
    root.querySelectorAll("figure").forEach((figure) => {
      figure.style.setProperty("width", "100%", "important");
      figure.style.setProperty("max-width", "100%", "important");
    });
    root.querySelectorAll("img").forEach((image) => {
      image.style.setProperty("max-width", `${canonicalContent.width}px`, "important");
      image.style.setProperty("max-height", `${maximumImageHeight}px`, "important");
      image.style.setProperty("width", "auto", "important");
      image.style.setProperty("height", "auto", "important");
      image.style.setProperty("object-fit", "contain", "important");
    });
    return piece(fragmentValue, root.outerHTML, 0, fragmentValue.textLength, false);
  }

  function bestScaledFigurePiece(fragmentValue, section) {
    const canonicalContent = canonicalRect(layout.contentFrame);
    let low = 1;
    let high = Math.max(1, Math.floor(canonicalContent.height));
    let best = null;
    while (low <= high) {
      const middle = Math.floor((low + high) / 2);
      const candidate = scaledFigurePiece(fragmentValue, middle);
      if (measurePage(pageWith(section, [candidate], {})).fits) {
        best = candidate;
        low = middle + 1;
      } else {
        high = middle - 1;
      }
    }
    return best;
  }

  function headingTextFromHTML(html) {
    if (!html) return "";
    const root = rootFromHTML(html);
    if (!root) return "";
    const selector = "h1,h2,h3,h4,h5,.cpb-lep-heading,.cpb-part0-heading,.cpb-part0-title";
    const headings = [];
    if (root.matches && root.matches(selector)) headings.push(root);
    if (root.querySelectorAll) headings.push(...Array.from(root.querySelectorAll(selector)));
    if (!headings.length) return "";
    const last = headings[headings.length - 1];
    return String(last.textContent || "").replace(/\s+/g, " ").trim();
  }

  function looksConcatenatedStructuralTitle(text) {
    const value = String(text || "");
    return /[A-Za-z]0\./.test(value) || /[A-Za-z]PART\s/i.test(value) && /0\./.test(value.replace(/\s+/g, ""));
  }

  function humanTitleForFragment(fragmentValue) {
    const htmlTitle = headingTextFromHTML(fragmentValue && fragmentValue.html || "");
    if (htmlTitle) return htmlTitle.slice(0, 180);
    const text = String(fragmentValue && fragmentValue.text || "").replace(/\s+/g, " ").trim();
    const sectionTitle = String(
      fragmentValue && fragmentValue.section && fragmentValue.section.title || ""
    ).trim();
    const anchor = String(fragmentValue && fragmentValue.anchor || "").trim();
    if (text && !looksConcatenatedStructuralTitle(text)) return text.slice(0, 180);
    if (sectionTitle) return sectionTitle;
    if (anchor) return anchor;
    return text.slice(0, 180);
  }

  function manualBreakRequiredError(fragmentValue) {
    const title = humanTitleForFragment(fragmentValue);
    const message = "Page content exceeds the available body area. "
      + `A Manual Page Break is required before: “${title}”`;
    const error = new Error(message);
    error.paginationError = {
      code: "MANUAL_BREAK_REQUIRED",
      message,
      before_block_anchor: String(fragmentValue && fragmentValue.anchor || ""),
      before_block_title: title,
      section_id: Number(fragmentValue && fragmentValue.section && fragmentValue.section.section_id || 0) || null
    };
    diagnostic(
      "MANUAL_BREAK_REQUIRED",
      "failure",
      message,
      fragmentValue,
      null
    );
    return error;
  }

  function unlayoutableFragmentError(fragmentValue, measurement, extra) {
    const mediaDetail = extra || "";
    diagnostic(
      "UNLAYOUTABLE_FRAGMENT",
      "failure",
      `Atomic fragment ${fragmentValue.id} exceeds a fresh content frame `
        + `(${measurement.bodyHeight.toFixed(2)}px > `
        + `${measurement.clientHeight.toFixed(2)}px; `
        + `horizontal overflow ${measurement.horizontalOverflow.toFixed(2)}px; `
        + `scroll ${measurement.scrollWidth.toFixed(2)}x`
        + `${measurement.scrollHeight.toFixed(2)}; `
        + `max block y ${measurement.maxBlockY.toFixed(2)}; `
        + `header overflow ${JSON.stringify(measurement.headerOverflow)}; `
        + `footer overflow ${JSON.stringify(measurement.footerOverflow)}).`
        + mediaDetail,
      fragmentValue,
      null
    );
    return new Error(`Atomic fragment exceeds page body: ${fragmentValue.id}.`);
  }

  function paginate(normalized) {
    const pages = [];
    let current = null;
    let pendingTableHeader = null;
    let continuation = null;

    function sourceBlockKey(fragmentValue) {
      if (Number(fragmentValue.blockId || 0) > 0) {
        return "block:" + fragmentValue.blockId;
      }
      const id = String(fragmentValue.id || "");
      const cut = id.lastIndexOf("/");
      return cut > 0 ? id.slice(0, cut) : id;
    }

    function isTocFragment(fragmentValue) {
      return fragmentValue.type === "toc" || fragmentValue.type === "tocRow";
    }

    function isLepFragment(fragmentValue) {
      return fragmentValue.type === "lep" || fragmentValue.type === "lepRow";
    }

    function isGeneratedFragment(fragmentValue) {
      if (String(fragmentValue.paginationAuthority || "") === "generated") return true;
      return isTocFragment(fragmentValue)
        || isLepFragment(fragmentValue)
        || fragmentValue.type === "generated"
        || fragmentValue.type === "generatedRow";
    }

    function isTableFragment(fragmentValue) {
      return fragmentValue.type === "tableHeader"
        || fragmentValue.type === "tableRow"
        || fragmentValue.type === "table";
    }

    function hasSourceContent(page) {
      return Boolean(page && page.pieces.some((value) => !value.presentationCopy));
    }

    function sourceKeysOnPage(page) {
      const keys = new Set();
      (page && page.pieces || []).forEach((value) => {
        if (!value.presentationCopy) keys.add(sourceBlockKey(value.fragment));
      });
      return keys;
    }

    function finish() {
      if (current && current.pieces.length) pages.push(current);
      current = null;
    }

    function begin(fragmentValue, flags) {
      current = pageWith(fragmentValue.section, [], {
        isSectionStart: Boolean(flags && flags.isSectionStart),
        isMajorSectionStart: Boolean(flags && flags.isMajorSectionStart),
        breakReason: String(flags && flags.breakReason || "")
      });
      if (pendingTableHeader && fragmentValue.type === "tableRow") {
        current.pieces.push(piece(
          pendingTableHeader,
          pendingTableHeader.html,
          0,
          pendingTableHeader.textLength,
          true
        ));
      }
    }

    function allowedOnContinuation(fragmentValue) {
      if (!continuation) return true;
      if (sourceBlockKey(fragmentValue) !== continuation.blockKey) return false;
      if (continuation.kind === "toc") return isTocFragment(fragmentValue);
      if (continuation.kind === "lep") return isLepFragment(fragmentValue);
      if (continuation.kind === "generated") return isGeneratedFragment(fragmentValue);
      if (continuation.kind === "table") return isTableFragment(fragmentValue);
      return continuation.kind === "oversized";
    }

    function startContinuation(fragmentValue, kind) {
      continuation = { kind, blockKey: sourceBlockKey(fragmentValue) };
      begin(fragmentValue, { breakReason: kind + "_continuation" });
    }

    function headingKeepChain(startIndex) {
      const first = normalized.fragments[startIndex];
      if (!first || first.type !== "heading") return [];
      const sectionID = Number(first.section && first.section.section_id || 0);
      const pieces = [];
      let index = startIndex;
      while (index < normalized.fragments.length) {
        const candidate = normalized.fragments[index];
        const candidateSectionID = Number(candidate.section && candidate.section.section_id || 0);
        if (
          candidateSectionID !== sectionID
          || (index > startIndex && candidate.forceBreakBefore)
        ) {
          return [];
        }
        if (candidate.type !== "heading") {
          const following = meaningfulFollowingPiece(candidate);
          if (following) pieces.push(following);
          return pieces;
        }
        pieces.push(piece(candidate, candidate.html, 0, candidate.textLength, false));
        index++;
      }
      return [];
    }

    for (let index = 0; index < normalized.fragments.length; index++) {
      const sourceFragment = normalized.fragments[index];
      if (sourceFragment.type === "cover") {
        finish();
        continuation = null;
        pendingTableHeader = null;
        pages.push(pageWith(sourceFragment.section, [
          piece(sourceFragment, sourceFragment.html, 0, sourceFragment.textLength, false)
        ], { isCover: true, isSectionStart: true, isMajorSectionStart: true }));
        continue;
      }

      if (sourceFragment.forceBreakBefore) {
        finish();
        continuation = null;
        current = null;
      }

      if (continuation && !allowedOnContinuation(sourceFragment)) {
        finish();
        continuation = null;
        current = null;
      }

      if (!current) {
        begin(sourceFragment, {
          isSectionStart: true,
          isMajorSectionStart: Boolean(
            sourceFragment.section.flags && sourceFragment.section.flags.is_major_section_start
          ),
          breakReason: sourceFragment.forceBreakBefore ? "forced_source_break" : ""
        });
      }

      if (sourceFragment.type === "tableHeader") pendingTableHeader = sourceFragment;
      if (sourceFragment.type !== "tableHeader" && sourceFragment.type !== "tableRow") {
        pendingTableHeader = null;
      }

      const whole = piece(sourceFragment, sourceFragment.html, 0, sourceFragment.textLength, false);
      if (sourceFragment.type === "heading" && hasSourceContent(current)) {
        const keepChain = headingKeepChain(index);
        if (keepChain.length > 1) {
          const currentChain = pageWith(current.section, current.pieces.concat(keepChain), {});
          const freshChain = pageWith(sourceFragment.section, keepChain, {});
          const headingOnly = pageWith(current.section, current.pieces.concat([whole]), {});
          if (
            measurePage(headingOnly).fits
            && !measurePage(currentChain).fits
            && measurePage(freshChain).fits
          ) {
            finish();
            continuation = null;
            begin(sourceFragment, { breakReason: "heading_keep_with_following" });
            current.pieces.push(whole);
            continue;
          }
        }
      }
      let trial = pageWith(current.section, current.pieces.concat([whole]), {});
      if (measurePage(trial).fits) {
        current.pieces.push(whole);
        continue;
      }

      if (isTocFragment(sourceFragment)) {
        if (!hasSourceContent(current)) {
          throw unlayoutableFragmentError(sourceFragment, measurePage(trial), "");
        }
        finish();
        startContinuation(sourceFragment, "toc");
        trial = pageWith(current.section, current.pieces.concat([whole]), {});
        if (measurePage(trial).fits) {
          current.pieces.push(whole);
          continue;
        }
        throw unlayoutableFragmentError(sourceFragment, measurePage(trial), "");
      }

      if (isLepFragment(sourceFragment)) {
        if (!hasSourceContent(current)) {
          throw unlayoutableFragmentError(sourceFragment, measurePage(trial), "");
        }
        finish();
        startContinuation(sourceFragment, "lep");
        trial = pageWith(current.section, current.pieces.concat([whole]), {});
        if (measurePage(trial).fits) {
          current.pieces.push(whole);
          continue;
        }
        throw unlayoutableFragmentError(sourceFragment, measurePage(trial), "");
      }

      if (isGeneratedFragment(sourceFragment) && !isTableFragment(sourceFragment)) {
        if (!hasSourceContent(current)) {
          if (!sourceFragment.splittable) {
            throw unlayoutableFragmentError(sourceFragment, measurePage(trial), "");
          }
        } else {
          finish();
          startContinuation(sourceFragment, "generated");
          trial = pageWith(current.section, current.pieces.concat([whole]), {});
          if (measurePage(trial).fits) {
            current.pieces.push(whole);
            continue;
          }
          if (!sourceFragment.splittable) {
            throw unlayoutableFragmentError(sourceFragment, measurePage(trial), "");
          }
        }
      }

      if (isTableFragment(sourceFragment)) {
        if (!hasSourceContent(current)) {
          throw unlayoutableFragmentError(sourceFragment, measurePage(trial), "");
        }
        finish();
        startContinuation(sourceFragment, "table");
        trial = pageWith(current.section, current.pieces.concat([whole]), {});
        if (measurePage(trial).fits) {
          current.pieces.push(whole);
          continue;
        }
        throw unlayoutableFragmentError(sourceFragment, measurePage(trial), "");
      }

      const keys = sourceKeysOnPage(current);
      const thisKey = sourceBlockKey(sourceFragment);
      const onlyThisBlock = keys.size === 1 && keys.has(thisKey);
      const pageEmpty = !hasSourceContent(current);

      if (!pageEmpty && !onlyThisBlock) {
        if (isGeneratedFragment(sourceFragment)) {
          finish();
          startContinuation(sourceFragment, "generated");
          trial = pageWith(current.section, current.pieces.concat([whole]), {});
          if (measurePage(trial).fits) {
            current.pieces.push(whole);
            continue;
          }
          throw unlayoutableFragmentError(sourceFragment, measurePage(trial), "");
        }
        finish();
        continuation = null;
        begin(sourceFragment, { breakReason: "automatic_author_flow" });
        trial = pageWith(current.section, current.pieces.concat([whole]), {});
        if (measurePage(trial).fits) {
          current.pieces.push(whole);
          continue;
        }
      }

      if (!pageEmpty && onlyThisBlock) {
        finish();
        startContinuation(sourceFragment, "oversized");
        trial = pageWith(current.section, current.pieces.concat([whole]), {});
        if (measurePage(trial).fits) {
          current.pieces.push(whole);
          continue;
        }
      }

      if (sourceFragment.splittable && sourceFragment.textLength > 0) {
        continuation = { kind: "oversized", blockKey: thisKey };
        let offset = 0;
        while (offset < sourceFragment.textLength) {
          if (!current) begin(sourceFragment, { breakReason: "oversized_block" });
          const fitted = bestTextSplit(current, sourceFragment, offset);
          if (!fitted) {
            if (hasSourceContent(current)) {
              finish();
              startContinuation(sourceFragment, "oversized");
              continue;
            }
            diagnostic(
              "UNLAYOUTABLE_FRAGMENT",
              "failure",
              `No safe split fits ${sourceFragment.id} inside the content frame.`,
              sourceFragment,
              pages.length + 1
            );
            throw new Error(`No safe pagination split for ${sourceFragment.id}.`);
          }
          current.pieces.push(fitted);
          offset = fitted.rangeEnd;
          if (offset < sourceFragment.textLength) {
            finish();
            startContinuation(sourceFragment, "oversized");
          }
        }
        continue;
      }

      if (sourceFragment.type === "figure") {
        const scaled = bestScaledFigurePiece(sourceFragment, current.section);
        if (scaled) {
          current.pieces.push(scaled);
          diagnostic(
            "OVERSIZED_IMAGE_SCALED",
            "info",
            `Scaled oversized figure ${sourceFragment.id} proportionally to the content frame.`,
            sourceFragment,
            pages.length + 1
          );
          continue;
        }
      }

      const failedPiece = sourceFragment.type === "figure"
        ? scaledFigurePiece(sourceFragment, 1)
        : whole;
      const failedMeasurement = measurePage(pageWith(current.section, [failedPiece], {}));
      const mediaDetail = sourceFragment.type === "figure"
        ? ` Figure ${JSON.stringify(failedMeasurement.figureRect)}, image `
          + `${JSON.stringify(failedMeasurement.imageRect)}.`
        : "";
      throw unlayoutableFragmentError(sourceFragment, failedMeasurement, mediaDetail);
    }
    finish();
    return pages;
  }

  function coverageForPage(page) {
    return page.pieces.map((value) => ({
      source_fragment_id: value.fragment.id,
      source_order: value.fragment.sourceOrder,
      range_start: value.rangeStart,
      range_end: value.rangeEnd,
      source_length: value.fragment.textLength,
      presentation_copy: value.presentationCopy
    }));
  }

  function validatePageStructure(pages) {
    let priorMetrics = null;
    pages.forEach((page, index) => {
      const pageNumber = index + 1;
      if (!page.pieces.length) {
        diagnostic("UNEXPECTED_BLANK_PAGE", "failure", "Generated page has no semantic content.", null, pageNumber);
        return;
      }
      const measurement = measurePage(page);
      const metrics = metricsForPage(
        page,
        pageNumber,
        pages.length,
        priorMetrics ? priorMetrics.content_utilization : null
      );
      page.metrics = metrics;
      if (measurement.horizontalOverflow > VALIDATION_TOLERANCE) {
        diagnostic(
          "CONTENT_WIDTH_OVERFLOW",
          "failure",
          `Body exceeds contentFrame width by ${measurement.horizontalOverflow.toFixed(2)}px. `
            + `First crossing descendant: ${JSON.stringify(measurement.overflowingDescendant)}.`,
          page.pieces[page.pieces.length - 1].fragment,
          pageNumber
        );
      }
      if (measurement.verticalOverflow > VALIDATION_TOLERANCE) {
        diagnostic(
          "CONTENT_HEIGHT_OVERFLOW",
          "failure",
          `Body exceeds contentFrame height by ${measurement.verticalOverflow.toFixed(2)}px.`,
          page.pieces[page.pieces.length - 1].fragment,
          pageNumber
        );
      }
      if (measurement.offendingBlockID) {
        const offending = page.pieces.find(
          (value) => value.fragment.id === measurement.offendingBlockID
        );
        diagnostic(
          "SEMANTIC_BLOCK_OUTSIDE_CONTENT_FRAME",
          "failure",
          `Semantic block ${measurement.offendingBlockID} crosses the body frame.`,
          offending?.fragment || null,
          pageNumber
        );
      }
      const last = [...page.pieces].reverse().find((value) => !value.presentationCopy);
      if (last && last.fragment.type === "heading") {
        diagnostic(
          "ORPHAN_HEADING",
          "info",
          `Heading ${last.fragment.id} ends page ${pageNumber}; no meaningful following fragment fits the same frame.`,
          last.fragment,
          pageNumber
        );
      }
      if (!page.isCover && page.section && page.section.show_header_footer) {
        if (!String(page.section.header_template || "").trim()) {
          diagnostic("MISSING_HEADER", "failure", "Required controlled header template is missing.", null, pageNumber);
        }
        if (!String(page.section.footer_template || "").trim()) {
          diagnostic("MISSING_FOOTER", "failure", "Required controlled footer template is missing.", null, pageNumber);
        }
        if (!rectMatches(measurement.headerRect, layout.headerFrame)) {
          diagnostic("HEADER_MISALIGNED", "failure", "Header region does not match headerFrame.", null, pageNumber);
        }
        if (!rectMatches(measurement.footerRect, layout.footerFrame)) {
          diagnostic("FOOTER_MISALIGNED", "failure", "Footer region does not match footerFrame.", null, pageNumber);
        }
        if (
          measurement.headerOverflow.horizontal > VALIDATION_TOLERANCE
          || measurement.headerOverflow.vertical > VALIDATION_TOLERANCE
        ) {
          diagnostic("HEADER_CLIPPED", "failure", "Controlled header content exceeds its reserved frame.", null, pageNumber);
        }
        if (
          measurement.footerOverflow.horizontal > VALIDATION_TOLERANCE
          || measurement.footerOverflow.vertical > VALIDATION_TOLERANCE
        ) {
          diagnostic("FOOTER_CLIPPED", "failure", "Controlled footer content exceeds its reserved frame.", null, pageNumber);
        }
      }
      if (!page.isCover && !rectMatches(measurement.bodyRect, layout.contentFrame)) {
        diagnostic("BODY_FRAME_MISALIGNED", "failure", "Body region does not match contentFrame.", null, pageNumber);
      }
      if (!page.isCover && page.section && page.section.show_header_footer) {
        if (measurement.headerBodyIntersect) {
          diagnostic("HEADER_BODY_INTERSECT", "failure", "Body content intersects the header region.", null, pageNumber);
        }
        if (measurement.bodyFooterIntersect) {
          diagnostic("BODY_FOOTER_INTERSECT", "failure", "Body content intersects the footer region.", null, pageNumber);
        }
        if (!measurement.headerInsideFrame) {
          diagnostic("HEADER_OUTSIDE_HEADER_FRAME", "failure", "Header is not fully inside headerFrame.", null, pageNumber);
        }
        if (!measurement.footerInsideFrame) {
          diagnostic("FOOTER_OUTSIDE_FOOTER_FRAME", "failure", "Footer is not fully inside footerFrame.", null, pageNumber);
        }
      }
      if (!page.isCover && !measurement.bodyInsideFrame) {
        diagnostic("BODY_OUTSIDE_CONTENT_FRAME", "failure", "Body is not fully inside contentFrame.", null, pageNumber);
      }
      if (!page.isCover && measurement.firstBodyAboveContent) {
        diagnostic(
          "BODY_STARTS_ABOVE_CONTENT_FRAME",
          "failure",
          "First body element starts above the Book Style contentFrame top.",
          page.pieces[0] ? page.pieces[0].fragment : null,
          pageNumber
        );
      }
      if (!page.isCover && measurement.lastBodyBelowContent) {
        diagnostic(
          "BODY_EXTENDS_BELOW_CONTENT_FRAME",
          "failure",
          "Last body element extends below the Book Style contentFrame bottom.",
          last ? last.fragment : null,
          pageNumber
        );
      }
      if (!page.isCover && metrics.content_utilization < 0.35) {
        diagnostic(
          "LOW_PAGE_UTILIZATION",
          "warning",
          `Page ${pageNumber} uses ${(metrics.content_utilization * 100).toFixed(1)}% `
            + `of its body frame; prior utilization `
            + `${metrics.prior_page_utilization == null ? "n/a" : `${(metrics.prior_page_utilization * 100).toFixed(1)}%`}; `
            + `prior near capacity=${metrics.prior_page_near_capacity ? "yes" : "no"}; `
            + `break=${metrics.break_reason || "none"}.`,
          last ? last.fragment : null,
          pageNumber
        );
      }
      if (!page.isCover && metrics.whitespace_ratio > 0.72) {
        diagnostic(
          "EXCESSIVE_WHITESPACE",
          "warning",
          `Page ${pageNumber} has ${(metrics.whitespace_ratio * 100).toFixed(1)}% whitespace `
            + `(${metrics.distance_from_last_block_to_footer.toFixed(1)}px below the last block); `
            + `forced break=${metrics.forced_break_before ? "yes" : "no"}.`,
          last ? last.fragment : null,
          pageNumber
        );
      }
      page.decisions.forEach((message) => {
        diagnostic(
          "PAGINATION_DECISION",
          "info",
          message,
          last ? last.fragment : null,
          pageNumber
        );
      });
      priorMetrics = metrics;
    });
  }

  function validateCoverage(normalized, pages) {
    const emitted = pages.flatMap(coverageForPage).filter((entry) => !entry.presentation_copy);
    const byID = new Map();
    emitted.forEach((entry) => {
      if (!byID.has(entry.source_fragment_id)) byID.set(entry.source_fragment_id, []);
      byID.get(entry.source_fragment_id).push(entry);
    });
    const validationDiagnostics = [];
    let emittedOrder = -1;
    emitted.forEach((entry) => {
      if (entry.source_order < emittedOrder) {
        validationDiagnostics.push({
          code: "SOURCE_ORDER_CHANGED",
          severity: "failure",
          page_number: null,
          source_fragment_id: entry.source_fragment_id,
          message: `Source fragment ${entry.source_fragment_id} was emitted out of source order.`
        });
      }
      emittedOrder = Math.max(emittedOrder, entry.source_order);
    });

    normalized.fragments.forEach((sourceFragment) => {
      const entries = (byID.get(sourceFragment.id) || []).sort((a, b) => a.range_start - b.range_start);
      if (!entries.length) {
        validationDiagnostics.push({
          code: "SOURCE_FRAGMENT_MISSING",
          severity: "failure",
          page_number: null,
          source_fragment_id: sourceFragment.id,
          message: `Source fragment ${sourceFragment.id} was not emitted.`
        });
        return;
      }
      let expectedStart = 0;
      entries.forEach((entry) => {
        if (entry.range_start !== expectedStart) {
          validationDiagnostics.push({
            code: entry.range_start < expectedStart ? "SOURCE_FRAGMENT_DUPLICATED" : "SOURCE_FRAGMENT_GAP",
            severity: "failure",
            page_number: null,
            source_fragment_id: sourceFragment.id,
            message: `Coverage for ${sourceFragment.id} expected offset ${expectedStart} but found ${entry.range_start}.`
          });
        }
        if (entry.range_end < entry.range_start || entry.range_end > sourceFragment.textLength) {
          validationDiagnostics.push({
            code: "SOURCE_FRAGMENT_RANGE_INVALID",
            severity: "failure",
            page_number: null,
            source_fragment_id: sourceFragment.id,
            message: `Invalid source range for ${sourceFragment.id}.`
          });
        }
        expectedStart = Math.max(expectedStart, entry.range_end);
      });
      if (expectedStart !== sourceFragment.textLength) {
        validationDiagnostics.push({
          code: "SOURCE_FRAGMENT_INCOMPLETE",
          severity: "failure",
          page_number: null,
          source_fragment_id: sourceFragment.id,
          message: `Coverage for ${sourceFragment.id} ended at ${expectedStart} of ${sourceFragment.textLength}.`
        });
      }
    });

    emitted.forEach((entry) => {
      if (!normalized.fragments.some((fragmentValue) => fragmentValue.id === entry.source_fragment_id)) {
        validationDiagnostics.push({
          code: "UNKNOWN_SOURCE_FRAGMENT",
          severity: "failure",
          page_number: null,
          source_fragment_id: entry.source_fragment_id,
          message: `Generated output contains unknown source fragment ${entry.source_fragment_id}.`
        });
      }
    });

    diagnostics.push(...validationDiagnostics);
    return {
      is_valid: diagnostics.every((item) => item.severity !== "failure"),
      source_fragment_count: normalized.fragments.length,
      covered_fragment_count: byID.size,
      diagnostics: diagnostics.slice()
    };
  }

  function location(fragmentValue, offset) {
    return {
      source_fragment_id: fragmentValue.id,
      semantic_anchor: fragmentValue.anchor,
      source_order: fragmentValue.sourceOrder,
      character_offset: offset,
      official_location: {
        section_id: Number(fragmentValue.section.section_id || 0) || null,
        stable_anchor: String(fragmentValue.section.stable_anchor || "") || null,
        official_page_number: officialPageFor(fragmentValue)
      }
    };
  }

  function sectionIndex(pages) {
    const output = {};
    pages.forEach((page, index) => {
      const sectionID = Number(page.section && page.section.section_id || 0);
      if (sectionID > 0 && output[String(sectionID)] == null) output[String(sectionID)] = index + 1;
    });
    return output;
  }

  function metricsForPage(page, pageNumber, total, priorPageUtilization = null) {
    const element = buildPageElement(page, pageNumber, total);
    host.replaceChildren(element);
    const measurement = measurePage(page);
    host.replaceChildren(element);
    const body = element.querySelector(".reader-page-body");
    const bodyRect = body.getBoundingClientRect();
    const blocks = Array.from(body.querySelectorAll(":scope > .reader-semantic-piece"));
    const blockMeasurements = blocks.map((block) => {
      const rect = block.getBoundingClientRect();
      const identified = block.hasAttribute("data-source-fragment-id")
        ? block
        : block.querySelector("[data-source-fragment-id]");
      return {
        source_fragment_id: identified?.getAttribute("data-source-fragment-id") || null,
        semantic_type: block.getAttribute("data-semantic-type") || "unknown",
        frame: {
          x: rect.left - bodyRect.left,
          y: rect.top - bodyRect.top,
          width: rect.width,
          height: rect.height
        }
      };
    });
    const last = blocks[blocks.length - 1];
    const lastRect = last?.getBoundingClientRect();
    const usedHeight = lastRect
      ? Math.min(bodyRect.height, Math.max(0, lastRect.bottom - bodyRect.top))
      : 0;
    const utilization = bodyRect.height > 0 ? usedHeight / bodyRect.height : 0;
    return {
      content_utilization: utilization,
      whitespace_ratio: Math.max(0, 1 - utilization),
      page_density: bodyRect.width * bodyRect.height > 0
        ? body.textContent.trim().length / (bodyRect.width * bodyRect.height)
        : 0,
      distance_from_last_block_to_footer: lastRect
        ? Math.max(0, bodyRect.bottom - lastRect.bottom)
        : bodyRect.height,
      heading_count: body.querySelectorAll("h1,h2,h3,h4").length,
      has_complex_table: body.querySelectorAll("table tr").length > 4,
      has_figure: Boolean(body.querySelector("figure,img")),
      prior_page_utilization: priorPageUtilization,
      prior_page_near_capacity: priorPageUtilization != null && priorPageUtilization >= 0.8,
      forced_break_before: ["section_change", "forced_source_break"].includes(page.breakReason),
      break_reason: page.breakReason || null,
      page_width: layout.pageWidth,
      page_height: layout.pageHeight,
      header_frame: layout.headerFrame,
      content_frame: layout.contentFrame,
      footer_frame: layout.footerFrame,
      content_scroll_width: measurement.scrollWidth,
      content_client_width: measurement.clientWidth,
      content_scroll_height: measurement.scrollHeight,
      content_client_height: measurement.clientHeight,
      max_block_y: measurement.maxBlockY,
      remaining_body_height: Math.max(0, measurement.clientHeight - measurement.maxBlockY),
      validation_passed: measurement.fits,
      offending_block_id: measurement.offendingBlockID,
      first_body_page_y: measurement.firstBodyPageY,
      last_body_page_y: measurement.lastBodyPageY,
      header_body_intersect: Boolean(measurement.headerBodyIntersect),
      body_footer_intersect: Boolean(measurement.bodyFooterIntersect),
      block_measurements: blockMeasurements
    };
  }

  async function ready(normalized) {
    if (document.fonts && document.fonts.ready) await document.fonts.ready;
    const sources = new Map();
    normalized.fragments.forEach((fragmentValue) => {
      const holder = document.createElement("div");
      holder.innerHTML = fragmentValue.html;
      holder.querySelectorAll("img[src]").forEach((image) => {
        if (!sources.has(image.src)) {
          sources.set(image.src, {
            hasDeclaredSize: Number(image.getAttribute("width") || 0) > 0
              && Number(image.getAttribute("height") || 0) > 0,
            fragment: fragmentValue
          });
        }
      });
    });
    await Promise.all(Array.from(sources.entries()).map(([src, metadata]) => {
      const image = new Image();
      image.src = src;
      if (image.complete && image.naturalWidth > 0) return Promise.resolve();
      return new Promise((resolve) => {
        image.addEventListener("load", resolve, { once: true });
        image.addEventListener("error", () => {
          diagnostic(
            "IMAGE_DIMENSIONS_UNAVAILABLE",
            metadata.hasDeclaredSize ? "warning" : "failure",
            metadata.hasDeclaredSize
              ? `Image failed to load during pagination; declared dimensions were retained: ${src}`
              : `Image failed to load and has no declared dimensions: ${src}`,
            metadata.fragment,
            null
          );
          resolve();
        }, { once: true });
      });
    }));
    await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
  }

  async function run() {
    validateLayoutContract();
    const normalized = normalizeDocument();
    await ready(normalized);
    const pages = paginate(normalized);
    validatePageStructure(pages);
    const validation = validateCoverage(normalized, pages);
    const total = pages.length;
    const responsePages = pages.map((page, index) => {
      const pageNumber = index + 1;
      const first = page.pieces.find((value) => !value.presentationCopy);
      const last = [...page.pieces].reverse().find((value) => !value.presentationCopy);
      const pageDiagnostics = diagnostics.filter((item) => item.page_number === pageNumber);
      return {
        page_number: pageNumber,
        page_html: buildPageElement(page, pageNumber, total).outerHTML,
        section_id: Number(page.section && page.section.section_id || 0) || null,
        section_title: String(page.section && page.section.title || ""),
        is_cover: Boolean(page.isCover),
        is_section_start: Boolean(page.isSectionStart),
        is_major_section_start: Boolean(page.isMajorSectionStart),
        start_location: first ? location(first.fragment, first.rangeStart) : null,
        end_location: last ? location(last.fragment, last.rangeEnd) : null,
        official_locations: first ? [location(first.fragment, first.rangeStart).official_location] : [],
        coverage: coverageForPage(page),
        diagnostics: pageDiagnostics,
        metrics: page.metrics || metricsForPage(page, pageNumber, total, null)
      };
    });
    window.webkit.messageHandlers.pagination.postMessage({
      ok: true,
      pages: responsePages,
      section_page_index: sectionIndex(pages),
      validation,
      normalizer_version: NORMALIZER_VERSION,
      engine_version: ENGINE_VERSION,
      validator_version: VALIDATOR_VERSION
    });
  }

  run().catch((error) => {
    const structured = error && error.paginationError
      ? error.paginationError
      : {
        code: "PAGINATION_FAILED",
        message: String(error && error.message || error)
      };
    window.webkit.messageHandlers.pagination.postMessage({
      ok: false,
      pages: [],
      section_page_index: {},
      validation: {
        is_valid: false,
        source_fragment_count: 0,
        covered_fragment_count: 0,
        diagnostics
      },
      normalizer_version: NORMALIZER_VERSION,
      engine_version: ENGINE_VERSION,
      validator_version: VALIDATOR_VERSION,
      error: structured
    });
  });
}());
