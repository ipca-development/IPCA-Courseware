(function () {
  "use strict";

  const input = window.IPCAPaginationInput;
  if (!input || !input.source || !input.layout) {
    throw new Error("Missing pagination input.");
  }

  const NORMALIZER_VERSION = "reader-normalizer-v1";
  const ENGINE_VERSION = "semantic-paginator-v2";
  const VALIDATOR_VERSION = "pagination-validator-v1";
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
      atomic: Boolean(options.atomic),
      splittable: Boolean(options.splittable),
      forceBreakBefore: Boolean(options.forceBreakBefore),
      headingLevel: Number(options.headingLevel || 0),
      tableHeaderHTML: String(options.tableHeaderHTML || ""),
      orderedStart: Number(options.orderedStart || 0),
      unsupported: Boolean(options.unsupported)
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

  function rootFromHTML(html) {
    const holder = document.createElement("div");
    holder.innerHTML = String(html || "");
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
          tableHeaderHTML: `${prefix}${headerHTML}</table>`
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
          tableHeaderHTML: headerHTML ? `${prefix}${headerHTML}</table>` : ""
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

  function normalizeUnit(section, unit, unitIndex) {
    const root = rootFromHTML(unit.html);
    const type = semanticType(unit, root);
    const forceBreakBefore = Boolean(
      unit.force_break_before
      || (unitIndex === 0 && section.flags && section.flags.force_page_break_before)
    );
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
      "heading", "paragraph", "note", "warning", "caution", "figure", "toc", "shell"
    ].includes(type);
    return [fragment(section, unit, "root", type, String(unit.html || ""), root.textContent, {
      anchor,
      atomic: ["heading", "note", "warning", "caution", "figure", "toc", "shell"].includes(type),
      splittable: ["paragraph", "note", "warning", "caution"].includes(type),
      forceBreakBefore,
      headingLevel: heading ? Number(heading.tagName.substring(1)) : 0,
      unsupported: !supported
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
        sourceText.push(rootFromHTML(unit.html).textContent);
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
    return layout.pageWidth / layout.canonicalPageWidth;
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
    const root = rootFromHTML(value.html).cloneNode(true);
    if (!root || root.nodeType !== Node.ELEMENT_NODE) {
      return `<div class="reader-semantic-piece" `
        + `data-source-fragment-id="${escapeHTML(value.fragment.id)}" `
        + `data-source-order="${value.fragment.sourceOrder}" `
        + `data-source-range-start="${value.rangeStart}" `
        + `data-source-range-end="${value.rangeEnd}" `
        + `data-presentation-copy="${value.presentationCopy ? "1" : "0"}" `
        + `data-semantic-type="${escapeHTML(value.fragment.type)}">${value.html}</div>`;
    }
    root.classList.add("reader-semantic-piece");
    annotateCoverage(root, value);
    root.setAttribute("data-semantic-type", value.fragment.type);
    return root.outerHTML;
  }

  function annotateCoverage(node, value) {
    node.setAttribute("data-source-fragment-id", value.fragment.id);
    node.setAttribute("data-source-order", String(value.fragment.sourceOrder));
    node.setAttribute("data-source-range-start", String(value.rangeStart));
    node.setAttribute("data-source-range-end", String(value.rangeEnd));
    node.setAttribute("data-presentation-copy", value.presentationCopy ? "1" : "0");
  }

  function tableGroupMarkup(values) {
    const firstRoot = rootFromHTML(values[0].html);
    const firstTable = firstRoot.matches("table") ? firstRoot : firstRoot.querySelector("table");
    if (!firstTable) return values.map(pieceMarkup).join("");
    const table = firstTable.cloneNode(true);
    table.querySelectorAll("thead,tbody,tfoot").forEach((node) => node.remove());
    let header = null;
    const body = document.createElement("tbody");
    values.forEach((value) => {
      const sourceRoot = rootFromHTML(value.html);
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
    if (!firstRoot.matches("table")) {
      const shell = firstRoot.cloneNode(true);
      const shellTable = shell.querySelector("table");
      if (shellTable) {
        shellTable.replaceWith(table);
        renderedTable = shell.outerHTML;
      }
    }
    const renderedRoot = rootFromHTML(renderedTable).cloneNode(true);
    renderedRoot.classList.add("reader-semantic-piece", "reader-table-group");
    renderedRoot.setAttribute("data-semantic-type", "table");
    return renderedRoot.outerHTML;
  }

  function tocGroupMarkup(values) {
    const root = rootFromHTML(values[0].html).cloneNode(true);
    let nav = root.matches(".cpb-toc") ? root : root.querySelector(".cpb-toc");
    if (!nav) return values.map(pieceMarkup).join("");
    nav.querySelectorAll(".cpb-toc-row").forEach((row) => row.remove());
    values.forEach((value) => {
      const sourceRoot = rootFromHTML(value.html);
      sourceRoot.querySelectorAll(".cpb-toc-row").forEach((row) => {
        const clone = row.cloneNode(true);
        annotateCoverage(clone, value);
        nav.appendChild(clone);
      });
    });
    root.classList.add("reader-semantic-piece", "reader-toc-group");
    root.setAttribute("data-semantic-type", "toc");
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
    return `<div class="reader-semantic-piece reader-cover-scale" `
      + `data-source-fragment-id="${escapeHTML(value.fragment.id)}" `
      + `data-source-order="${value.fragment.sourceOrder}" `
      + `data-source-range-start="${value.rangeStart}" `
      + `data-source-range-end="${value.rangeEnd}" `
      + `data-presentation-copy="0" data-semantic-type="cover" `
      + `style="position:absolute;inset:0;width:${layout.canonicalPageWidth}px;`
      + `height:${layout.canonicalPageHeight}px">${value.html}</div>`;
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
      + `height:${layout.pageHeight}px;margin:0;padding:0;overflow:visible;`;
    const canonicalPageStyle = `position:absolute;box-sizing:border-box;left:0;top:0;`
      + `width:${layout.canonicalPageWidth}px;height:${layout.canonicalPageHeight}px;`
      + `transform-origin:top left;transform:scale(var(--reader-page-scale));`;
    if (page.isCover) {
      element.innerHTML = `
        <div class="reader-canonical-page cpb-sheet" style="${canonicalPageStyle}">
          <main class="reader-page-body reader-page-cover" data-blocks-root="1"
            style="position:absolute;box-sizing:border-box;inset:0;width:${layout.canonicalPageWidth}px;height:${layout.canonicalPageHeight}px;overflow:visible">
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
        <div class="reader-page-header-region" style="position:absolute;box-sizing:border-box;${frameStyle(headerFrame)}">${header}</div>
        <main class="reader-page-body cpb-sheet-body" data-blocks-root="1" style="position:absolute;box-sizing:border-box;${frameStyle(contentFrame)};overflow:visible">${pagePiecesMarkup(page.pieces)}</main>
        <div class="reader-page-footer-region" style="position:absolute;box-sizing:border-box;${frameStyle(footerFrame)}">${footer}</div>
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
    return {
      fits: horizontalOverflow <= 0.75 && verticalOverflow <= 0.75,
      horizontalOverflow,
      verticalOverflow,
      bodyHeight: body.scrollHeight * scale,
      clientHeight: body.clientHeight * scale,
      imageRect: relativeRect(body.querySelector("img")),
      figureRect: relativeRect(body.querySelector("figure")),
      headerRect: relativeRect(element.querySelector(".reader-page-header-region")),
      bodyRect: relativeRect(body),
      footerRect: relativeRect(element.querySelector(".reader-page-footer-region"))
    };
  }

  function validateLayoutContract() {
    const scale = pageScale();
    if (
      !Number.isFinite(scale)
      || scale <= 0
      || layout.canonicalPageWidth <= 0
      || layout.canonicalPageHeight <= 0
    ) {
      throw new Error("Canonical page dimensions or presentation scale are invalid.");
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
    const canonicalFrames = frames.map(canonicalRect);
    if (!canonicalFrames.every((frame) =>
      frame.x + frame.width <= layout.canonicalPageWidth + 0.01
      && frame.y + frame.height <= layout.canonicalPageHeight + 0.01
    )) {
      throw new Error("Canonical page frame lies outside the manifest page bounds.");
    }
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

  function paginate(normalized) {
    const pages = [];
    let current = null;
    let pendingTableHeader = null;

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

    for (let index = 0; index < normalized.fragments.length; index++) {
      const sourceFragment = normalized.fragments[index];
      const next = normalized.fragments[index + 1] || null;
      if (sourceFragment.type === "cover") {
        finish();
        pages.push(pageWith(sourceFragment.section, [
          piece(sourceFragment, sourceFragment.html, 0, sourceFragment.textLength, false)
        ], { isCover: true, isSectionStart: true, isMajorSectionStart: true }));
        continue;
      }
      let breakReason = "";
      if (
        current
        && Number(current.section && current.section.section_id || 0)
          !== Number(sourceFragment.section && sourceFragment.section.section_id || 0)
      ) {
        finish();
        breakReason = "section_change";
      }
      if (sourceFragment.forceBreakBefore) {
        finish();
        breakReason = "forced_source_break";
      }
      if (!current) {
        begin(sourceFragment, {
          isSectionStart: true,
          isMajorSectionStart: Boolean(sourceFragment.section.flags && sourceFragment.section.flags.is_major_section_start),
          breakReason
        });
      }

      if (sourceFragment.type === "tableHeader") pendingTableHeader = sourceFragment;
      if (sourceFragment.type !== "tableHeader" && sourceFragment.type !== "tableRow") {
        pendingTableHeader = null;
      }

      const whole = piece(sourceFragment, sourceFragment.html, 0, sourceFragment.textLength, false);

      if (
        (sourceFragment.type === "heading"
          || (sourceFragment.type === "tableHeader" && next && next.type === "tableRow"))
        && next
      ) {
        const keepNext = meaningfulFollowingPiece(next);
        const keepTrial = pageWith(
          current.section,
          current.pieces.concat([whole, keepNext]),
          {}
        );
        if (current.pieces.length && !measurePage(keepTrial).fits) {
          current.decisions.push(
            `${sourceFragment.type} ${sourceFragment.id} moved with ${next.id}`
          );
          finish();
          begin(sourceFragment, { breakReason: "keep_with_next" });
        }
      }

      let trial = pageWith(current.section, current.pieces.concat([whole]), {});
      if (measurePage(trial).fits) {
        current.pieces.push(whole);
        continue;
      }

      if (sourceFragment.splittable && sourceFragment.textLength > 0) {
        let offset = 0;
        while (offset < sourceFragment.textLength) {
          if (!current) begin(sourceFragment, {});
          const fitted = bestTextSplit(current, sourceFragment, offset);
          if (!fitted) {
            if (current.pieces.length) {
              finish();
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
          if (offset < sourceFragment.textLength) finish();
        }
        continue;
      }

      if (current.pieces.length) {
        finish();
        begin(sourceFragment, {});
        trial = pageWith(current.section, [whole], {});
        if (measurePage(trial).fits) {
          current.pieces.push(whole);
          continue;
        }
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
      diagnostic(
        "UNLAYOUTABLE_FRAGMENT",
        "failure",
        `Atomic fragment ${sourceFragment.id} exceeds a fresh content frame `
          + `(${failedMeasurement.bodyHeight.toFixed(2)}px > `
          + `${failedMeasurement.clientHeight.toFixed(2)}px; `
          + `horizontal overflow ${failedMeasurement.horizontalOverflow.toFixed(2)}px).`
          + mediaDetail,
        sourceFragment,
        pages.length + 1
      );
      throw new Error(`Atomic fragment exceeds page body: ${sourceFragment.id}.`);
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
      if (measurement.horizontalOverflow > 0.75) {
        diagnostic(
          "CONTENT_WIDTH_OVERFLOW",
          "failure",
          `Body exceeds contentFrame width by ${measurement.horizontalOverflow.toFixed(2)}px.`,
          page.pieces[page.pieces.length - 1].fragment,
          pageNumber
        );
      }
      if (measurement.verticalOverflow > 0.75) {
        diagnostic(
          "CONTENT_HEIGHT_OVERFLOW",
          "failure",
          `Body exceeds contentFrame height by ${measurement.verticalOverflow.toFixed(2)}px.`,
          page.pieces[page.pieces.length - 1].fragment,
          pageNumber
        );
      }
      const last = [...page.pieces].reverse().find((value) => !value.presentationCopy);
      if (last && last.fragment.type === "heading") {
        diagnostic(
          "ORPHAN_HEADING",
          "failure",
          `Heading ${last.fragment.id} is isolated at the end of page ${pageNumber}.`,
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
      }
      if (!page.isCover && !rectMatches(measurement.bodyRect, layout.contentFrame)) {
        diagnostic("BODY_FRAME_MISALIGNED", "failure", "Body region does not match contentFrame.", null, pageNumber);
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
      pages: responsePages,
      section_page_index: sectionIndex(pages),
      validation,
      normalizer_version: NORMALIZER_VERSION,
      engine_version: ENGINE_VERSION,
      validator_version: VALIDATOR_VERSION
    });
  }

  run().catch((error) => {
    window.webkit.messageHandlers.pagination.postMessage({
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
      error: String(error && error.message || error)
    });
  });
}());
