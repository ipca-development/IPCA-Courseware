import Foundation
import WebKit

struct PersonalPaginationResult {
    let pages: [FrozenPageMeta]
    let pageHTMLByNumber: [Int: String]
    let sectionPageIndex: [Int: Int]
}

enum HTMLPaginationError: LocalizedError {
    case invalidSource
    case failed(String)

    var errorDescription: String? {
        switch self {
        case .invalidSource:
            "The manual pagination source is invalid."
        case .failed(let message):
            "Unable to paginate the manual: \(message)"
        }
    }
}

@MainActor
final class HTMLPaginationEngine: NSObject, WKNavigationDelegate, WKScriptMessageHandler {
    private var webView: WKWebView?
    private var continuation: CheckedContinuation<PersonalPaginationResult, Error>?

    func paginate(
        sourceData: Data,
        editorCSS: String,
        readerCSS: String,
        fontScale: Double,
        baseURL: URL
    ) async throws -> PersonalPaginationResult {
        guard continuation == nil else {
            throw HTMLPaginationError.failed("Another pagination operation is still running.")
        }

        let configuration = WKWebViewConfiguration()
        configuration.defaultWebpagePreferences.allowsContentJavaScript = true
        configuration.userContentController.add(self, name: "pagination")
        let webView = WKWebView(
            frame: CGRect(
                x: 0,
                y: 0,
                width: ManualPageLayout.width,
                height: ManualPageLayout.height
            ),
            configuration: configuration
        )
        webView.navigationDelegate = self
        webView.isHidden = true
        self.webView = webView

        let sourceBase64 = sourceData.base64EncodedString()
        let safeEditorCSS = editorCSS.replacingOccurrences(of: "</style", with: "<\\/style")
        let safeReaderCSS = readerCSS.replacingOccurrences(of: "</style", with: "<\\/style")
        let html = paginationDocument(
            sourceBase64: sourceBase64,
            editorCSS: safeEditorCSS,
            readerCSS: safeReaderCSS,
            fontScale: max(0.75, min(1.5, fontScale))
        )

        return try await withCheckedThrowingContinuation { continuation in
            self.continuation = continuation
            webView.loadHTMLString(html, baseURL: baseURL)
        }
    }

    func userContentController(
        _ userContentController: WKUserContentController,
        didReceive message: WKScriptMessage
    ) {
        guard message.name == "pagination", let continuation else { return }
        self.continuation = nil
        defer {
            webView?.configuration.userContentController.removeScriptMessageHandler(forName: "pagination")
            webView = nil
        }

        do {
            guard JSONSerialization.isValidJSONObject(message.body) else {
                throw HTMLPaginationError.invalidSource
            }
            let data = try JSONSerialization.data(withJSONObject: message.body)
            let response = try JSONDecoder().decode(PaginationResponse.self, from: data)
            if let error = response.error {
                throw HTMLPaginationError.failed(error)
            }
            let pages = response.pages.map {
                FrozenPageMeta(
                    pageNumber: $0.pageNumber,
                    sectionId: $0.sectionId,
                    stableAnchor: $0.stableAnchor,
                    pageType: $0.isCover ? "cover" : "content",
                    isCover: $0.isCover,
                    isSectionStart: $0.isSectionStart,
                    isMajorSectionStart: $0.isMajorSectionStart,
                    sectionTitle: $0.sectionTitle
                )
            }
            let htmlByPage = Dictionary(uniqueKeysWithValues: response.pages.map {
                ($0.pageNumber, $0.pageHTML)
            })
            let sectionIndex = response.sectionPageIndex.reduce(into: [Int: Int]()) { output, pair in
                if let sectionID = Int(pair.key) {
                    output[sectionID] = pair.value
                }
            }
            continuation.resume(
                returning: PersonalPaginationResult(
                    pages: pages,
                    pageHTMLByNumber: htmlByPage,
                    sectionPageIndex: sectionIndex
                )
            )
        } catch {
            continuation.resume(throwing: error)
        }
    }

    func webView(
        _ webView: WKWebView,
        didFail navigation: WKNavigation!,
        withError error: Error
    ) {
        fail(error)
    }

    func webView(
        _ webView: WKWebView,
        didFailProvisionalNavigation navigation: WKNavigation!,
        withError error: Error
    ) {
        fail(error)
    }

    private func fail(_ error: Error) {
        guard let continuation else { return }
        self.continuation = nil
        continuation.resume(throwing: error)
        webView?.configuration.userContentController.removeScriptMessageHandler(forName: "pagination")
        webView = nil
    }

    private func paginationDocument(
        sourceBase64: String,
        editorCSS: String,
        readerCSS: String,
        fontScale: Double
    ) -> String {
        """
        <!doctype html>
        <html>
        <head>
          <meta charset="utf-8">
          <style>
          \(editorCSS)
          \(readerCSS)
          html, body { margin: 0; width: 816px; min-height: 1056px; background: #fff; }
          #measure { position: absolute; inset: 0 auto auto 0; width: 816px; visibility: hidden; pointer-events: none; }
          #measure .cpb-sheet { width: 816px !important; height: 1056px !important; min-height: 1056px !important; max-height: 1056px !important; overflow: hidden !important; box-shadow: none !important; font-size: \(11 * fontScale)pt !important; }
          #measure .cpb-sheet-body { overflow: hidden !important; }
          </style>
        </head>
        <body><div id="measure"></div>
        <script>
        (() => {
          const sourceText = decodeURIComponent(escape(atob('\(sourceBase64)')));
          const source = JSON.parse(sourceText);
          const scale = \(fontScale);
          const host = document.getElementById('measure');
          const layout = source.layout || {};
          const bodyCapacity = Number(layout.body_capacity_px || 744);
          const fontSizes = [8, 9, 10, 11, 12, 14, 16, 18, 24];

          function applyFontScale(root) {
            root.style.fontSize = `${11 * scale}pt`;
            fontSizes.forEach(size => {
              root.querySelectorAll(`[data-font-size="${size}"]`).forEach(node => {
                node.style.setProperty('font-size', `${size * scale}pt`, 'important');
              });
            });
          }

          function tokens(html, page, total) {
            return String(html || '')
              .replaceAll('{{page}}', String(page))
              .replaceAll('{page}', String(page))
              .replaceAll('{{page_total}}', String(total))
              .replaceAll('{page_total}', String(total))
              .replaceAll('Page: —', `Page: ${page}`)
              .replaceAll('Page:&nbsp;—', `Page: ${page}`);
          }

          function makeMeasurementPage(section, parts) {
            const holder = document.createElement('div');
            const body = (section.uses_sheet_body !== false)
              ? `<div class="cpb-sheet-body" data-blocks-root="1">${parts.join('')}</div>`
              : parts.join('');
            holder.innerHTML = `${section.sheet_open || '<div class="cpb-sheet">'}${section.show_header_footer ? section.header_template || '' : ''}${body}${section.show_header_footer ? section.footer_template || '' : ''}</div>`;
            const sheet = holder.firstElementChild;
            host.replaceChildren(sheet);
            applyFontScale(sheet);
            const bodyElement = sheet.querySelector('.cpb-sheet-body') || sheet;
            bodyElement.style.height = `${bodyCapacity}px`;
            bodyElement.style.maxHeight = `${bodyCapacity}px`;
            bodyElement.style.overflow = 'hidden';
            return { sheet, bodyElement };
          }

          function fits(section, parts) {
            const measured = makeMeasurementPage(section, parts);
            return measured.bodyElement.scrollHeight <= bodyCapacity + 1
              && measured.sheet.scrollHeight <= 1057;
          }

          function splitUnit(unit) {
            if (!unit.splittable) return [String(unit.html || '')];
            const holder = document.createElement('div');
            holder.innerHTML = String(unit.html || '');
            const paragraphs = Array.from(holder.querySelectorAll('p'));
            if (paragraphs.length > 1) {
              return paragraphs.map(paragraph => {
                const clone = holder.firstElementChild.cloneNode(true);
                const target = clone.querySelector('.cpb-paragraph') || clone;
                target.innerHTML = paragraph.outerHTML;
                return clone.outerHTML;
              });
            }
            const textTarget = holder.querySelector('p') || holder.firstElementChild;
            const words = String(textTarget?.textContent || '').trim().split(/\\s+/);
            if (words.length <= 16 || !holder.firstElementChild) return [String(unit.html || '')];
            const chunks = [];
            for (let index = 0; index < words.length; index += 16) {
              const clone = holder.firstElementChild.cloneNode(true);
              const cloneTarget = clone.querySelector('p') || clone;
              cloneTarget.textContent = words.slice(index, index + 16).join(' ');
              chunks.push(clone.outerHTML);
            }
            return chunks;
          }

          function paginateSections(sections) {
            const output = [];
            for (const section of sections) {
              if (section.content_mode === 'cover') {
                output.push({
                  section,
                  parts: [String(section.cover_html || '')],
                  isCover: true,
                  isSectionStart: true,
                  isMajorSectionStart: true
                });
                continue;
              }
              const units = Array.isArray(section.units) ? section.units : [];
              let page = null;
              const finish = () => {
                if (page && page.parts.length) output.push(page);
                page = null;
              };
              units.forEach((unit, unitIndex) => {
                if (unitIndex === 0) finish();
                if (unit.force_break_before || (unitIndex === 0 && section.flags?.force_page_break_before)) finish();
                const fragments = splitUnit(unit);
                fragments.forEach(fragment => {
                  if (!page) {
                    page = {
                      section,
                      parts: [],
                      isCover: false,
                      isSectionStart: unitIndex === 0 && Boolean(section.flags?.is_section_start),
                      isMajorSectionStart: unitIndex === 0 && Boolean(section.flags?.is_major_section_start)
                    };
                  }
                  const trial = page.parts.concat([fragment]);
                  if (page.parts.length && !fits(section, trial)) {
                    finish();
                    page = {
                      section,
                      parts: [],
                      isCover: false,
                      isSectionStart: false,
                      isMajorSectionStart: false
                    };
                  }
                  page.parts.push(fragment);
                });
              });
              finish();
            }
            return output;
          }

          function sectionIndex(pages) {
            const result = {};
            pages.forEach((page, index) => {
              const id = Number(page.section.section_id || 0);
              if (id > 0 && result[String(id)] == null) result[String(id)] = index + 1;
            });
            return result;
          }

          function patchToc(sections, index) {
            return sections.map(section => {
              const clone = structuredClone(section);
              clone.units = (clone.units || []).map(unit => {
                const holder = document.createElement('div');
                holder.innerHTML = String(unit.html || '');
                holder.querySelectorAll('.cpb-toc-row[data-section-id]').forEach(row => {
                  const page = index[String(row.getAttribute('data-section-id'))];
                  const pageNode = row.querySelector('.cpb-toc-page, [data-toc-page]');
                  if (pageNode && page != null) pageNode.textContent = String(page);
                });
                unit.html = holder.innerHTML;
                return unit;
              });
              return clone;
            });
          }

          function assemble(page, pageNumber, total) {
            const section = page.section;
            if (page.isCover) return tokens(page.parts.join(''), pageNumber, total);
            const open = String(section.sheet_open || '<div class="cpb-sheet">')
              .replace(/^<div /, `<div data-reader-page="${pageNumber}" `);
            const header = section.show_header_footer ? tokens(section.header_template, pageNumber, total) : '';
            const footer = section.show_header_footer ? tokens(section.footer_template, pageNumber, total) : '';
            const body = section.uses_sheet_body !== false
              ? `<div class="cpb-sheet-body" data-blocks-root="1">${page.parts.join('')}</div>`
              : page.parts.join('');
            return `${open}${header}${body}${footer}</div>`;
          }

          try {
            const allSections = Array.isArray(source.sections) ? source.sections : [];
            const tocSections = allSections.filter(section => section.section_key === 'toc');
            const contentSections = allSections.filter(section => section.section_key !== 'toc');
            const contentPages = paginateSections(contentSections);
            let index = sectionIndex(contentPages);
            let tocPages = paginateSections(patchToc(tocSections, index));
            const coverCount = contentPages.length && contentPages[0].isCover ? 1 : 0;
            if (tocPages.length) {
              const shifted = {};
              Object.entries(index).forEach(([key, value]) => {
                shifted[key] = Number(value) > coverCount ? Number(value) + tocPages.length : Number(value);
              });
              tocPages = paginateSections(patchToc(tocSections, shifted));
            }
            const pages = coverCount
              ? [contentPages[0], ...tocPages, ...contentPages.slice(1)]
              : [...tocPages, ...contentPages];
            index = sectionIndex(pages);
            const total = pages.length;
            const responsePages = pages.map((page, arrayIndex) => {
              const section = page.section;
              return {
                page_number: arrayIndex + 1,
                section_id: Number(section.section_id || 0) || null,
                stable_anchor: String(section.stable_anchor || ''),
                section_title: String(section.title || ''),
                is_cover: Boolean(page.isCover),
                is_section_start: Boolean(page.isSectionStart),
                is_major_section_start: Boolean(page.isMajorSectionStart),
                page_html: assemble(page, arrayIndex + 1, total)
              };
            });
            window.webkit.messageHandlers.pagination.postMessage({
              pages: responsePages,
              section_page_index: index
            });
          } catch (error) {
            window.webkit.messageHandlers.pagination.postMessage({
              pages: [],
              section_page_index: {},
              error: String(error?.message || error)
            });
          }
        })();
        </script></body></html>
        """
    }
}

private struct PaginationResponse: Decodable {
    let pages: [PaginationPage]
    let sectionPageIndex: [String: Int]
    let error: String?

    enum CodingKeys: String, CodingKey {
        case pages
        case sectionPageIndex = "section_page_index"
        case error
    }
}

private struct PaginationPage: Decodable {
    let pageNumber: Int
    let sectionId: Int?
    let stableAnchor: String?
    let sectionTitle: String?
    let isCover: Bool
    let isSectionStart: Bool
    let isMajorSectionStart: Bool
    let pageHTML: String

    enum CodingKeys: String, CodingKey {
        case pageNumber = "page_number"
        case sectionId = "section_id"
        case stableAnchor = "stable_anchor"
        case sectionTitle = "section_title"
        case isCover = "is_cover"
        case isSectionStart = "is_section_start"
        case isMajorSectionStart = "is_major_section_start"
        case pageHTML = "page_html"
    }
}
