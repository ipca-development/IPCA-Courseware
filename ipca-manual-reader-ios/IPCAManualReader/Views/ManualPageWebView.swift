import SwiftUI
import WebKit

private final class ReaderWebView: WKWebView {
    override func canPerformAction(_ action: Selector, withSender sender: Any?) -> Bool {
        false
    }
}

struct ManualPageWebView: UIViewRepresentable {
    let html: String
    let baseURL: URL
    var zoomMode: ReaderZoomMode
    var containerSize: CGSize
    var pageSize: CGSize
    var pageBackground: Color
    var onReady: () -> Void
    var onRenderFailure: () -> Void
    var onNavigateToAnchor: (String) -> Void
    var onNavigateToSection: (Int) -> Void
    var onShareAnnex: (Int) -> Void
    var onExternalLink: (URL) -> Void
    var onZoomChanged: (Bool) -> Void
    var onTextSelection: (ReaderTextSelection) -> Void

    func makeCoordinator() -> Coordinator {
        Coordinator(
            onReady: onReady,
            onRenderFailure: onRenderFailure,
            onNavigateToAnchor: onNavigateToAnchor,
            onNavigateToSection: onNavigateToSection,
            onShareAnnex: onShareAnnex,
            onExternalLink: onExternalLink,
            onZoomChanged: onZoomChanged,
            onTextSelection: onTextSelection
        )
    }

    func makeUIView(context: Context) -> WKWebView {
        let config = WKWebViewConfiguration()
        config.defaultWebpagePreferences.allowsContentJavaScript = true
        config.userContentController.add(context.coordinator, name: "readerSelection")
        config.userContentController.add(context.coordinator, name: "readerAnnexAction")
        config.userContentController.add(context.coordinator, name: "readerPageReady")
        config.userContentController.addUserScript(
            WKUserScript(
                source: Self.selectionBridgeScript,
                injectionTime: .atDocumentEnd,
                forMainFrameOnly: true
            )
        )
        let webView = ReaderWebView(frame: .zero, configuration: config)
        webView.isOpaque = true
        webView.backgroundColor = UIColor(pageBackground)
        webView.scrollView.backgroundColor = UIColor(pageBackground)
        context.coordinator.onReady = onReady
        context.coordinator.onRenderFailure = onRenderFailure
        context.coordinator.onNavigateToAnchor = onNavigateToAnchor
        context.coordinator.onNavigateToSection = onNavigateToSection
        context.coordinator.onShareAnnex = onShareAnnex
        context.coordinator.onExternalLink = onExternalLink
        context.coordinator.onZoomChanged = onZoomChanged
        context.coordinator.onTextSelection = onTextSelection
        context.coordinator.onTextSelection = onTextSelection
        webView.scrollView.isScrollEnabled = true
        webView.scrollView.bounces = true
        webView.scrollView.bouncesZoom = true
        webView.scrollView.minimumZoomScale = 1
        webView.scrollView.maximumZoomScale = 4
        context.coordinator.observeZoom(in: webView.scrollView)
        webView.navigationDelegate = context.coordinator
        return webView
    }

    func updateUIView(_ webView: WKWebView, context: Context) {
        webView.backgroundColor = UIColor(pageBackground)
        webView.scrollView.backgroundColor = UIColor(pageBackground)
        context.coordinator.onReady = onReady
        context.coordinator.onRenderFailure = onRenderFailure
        context.coordinator.onNavigateToAnchor = onNavigateToAnchor
        context.coordinator.onNavigateToSection = onNavigateToSection
        context.coordinator.onShareAnnex = onShareAnnex
        context.coordinator.onExternalLink = onExternalLink
        context.coordinator.onZoomChanged = onZoomChanged
        context.coordinator.refreshZoomGestureState(in: webView.scrollView)
        let scaleExpression: String = switch zoomMode {
        case .fitPage:
            "Math.min(\(containerSize.width) / contentWidth, \(containerSize.height) / contentHeight)"
        case .fitWidth:
            "\(containerSize.width) / contentWidth"
        case .percent75:
            "0.75"
        case .percent100:
            "1"
        case .percent125:
            "1.25"
        }
        let htmlChanged = context.coordinator.lastHTML != html
        if htmlChanged {
            context.coordinator.htmlRevision += 1
            context.coordinator.isLoaded = false
        }
        let revision = context.coordinator.htmlRevision
        let js = """
        (function() {
          const revision = \(revision);
          const frame = document.querySelector('.mr-ios-frame');
          if (!frame) return;
          const imageReady = Array.from(document.images).map(image => {
            if (image.complete) return Promise.resolve();
            if (typeof image.decode === 'function') return image.decode().catch(() => {});
            return new Promise(resolve => {
              image.addEventListener('load', resolve, { once: true });
              image.addEventListener('error', resolve, { once: true });
            });
          });
          const fontReady = document.fonts?.ready || Promise.resolve();
          Promise.all([fontReady, ...imageReady]).then(function() {
            frame.style.transform = 'none';
            const contentWidth = Math.max(frame.offsetWidth, 1);
            const contentHeight = Math.max(frame.offsetHeight, 1);
            const isLayoutBound = frame.getAttribute('data-layout-bound') === '1';
            const scale = isLayoutBound ? 1 : \(scaleExpression);
            frame.style.transform = 'scale(' + scale + ')';
            frame.style.marginBottom = ((contentHeight * scale) - contentHeight) + 'px';
            let previous = '';
            let stableFrames = 0;
            let attempts = 0;
            function verifyStableGeometry() {
              requestAnimationFrame(function() {
                const rect = frame.getBoundingClientRect();
                const signature = [
                  rect.width.toFixed(2),
                  rect.height.toFixed(2),
                  frame.scrollWidth,
                  frame.scrollHeight
                ].join(':');
                stableFrames = signature === previous ? stableFrames + 1 : 0;
                previous = signature;
                attempts += 1;
                if (stableFrames >= 2 || attempts >= 12) {
                  window.webkit.messageHandlers.readerPageReady.postMessage({
                    revision: revision,
                    stable: stableFrames >= 2
                  });
                  return;
                }
                verifyStableGeometry();
              });
            }
            verifyStableGeometry();
          });
        })();
        """
        context.coordinator.pendingScaleJS = js

        if htmlChanged {
            context.coordinator.lastHTML = html
            webView.loadHTMLString(html, baseURL: baseURL)
        } else if context.coordinator.isLoaded {
            webView.evaluateJavaScript(js, completionHandler: nil)
        }
    }

    final class Coordinator: NSObject, WKNavigationDelegate, WKScriptMessageHandler {
        var lastHTML: String = ""
        var htmlRevision = 0
        var pendingScaleJS: String = ""
        var isLoaded = false
        var onReady: () -> Void
        var onRenderFailure: () -> Void
        var onNavigateToAnchor: (String) -> Void
        var onNavigateToSection: (Int) -> Void
        var onShareAnnex: (Int) -> Void
        var onExternalLink: (URL) -> Void
        var onZoomChanged: (Bool) -> Void
        var onTextSelection: (ReaderTextSelection) -> Void
        private var zoomObservation: NSKeyValueObservation?

        init(
            onReady: @escaping () -> Void,
            onRenderFailure: @escaping () -> Void,
            onNavigateToAnchor: @escaping (String) -> Void,
            onNavigateToSection: @escaping (Int) -> Void,
            onShareAnnex: @escaping (Int) -> Void,
            onExternalLink: @escaping (URL) -> Void,
            onZoomChanged: @escaping (Bool) -> Void,
            onTextSelection: @escaping (ReaderTextSelection) -> Void
        ) {
            self.onReady = onReady
            self.onRenderFailure = onRenderFailure
            self.onNavigateToAnchor = onNavigateToAnchor
            self.onNavigateToSection = onNavigateToSection
            self.onShareAnnex = onShareAnnex
            self.onExternalLink = onExternalLink
            self.onZoomChanged = onZoomChanged
            self.onTextSelection = onTextSelection
        }

        func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
            isLoaded = true
            if !pendingScaleJS.isEmpty {
                webView.evaluateJavaScript(pendingScaleJS, completionHandler: nil)
            } else {
                DispatchQueue.main.async { self.onReady() }
            }
        }

        func observeZoom(in scrollView: UIScrollView) {
            zoomObservation = scrollView.observe(\.zoomScale, options: [.initial, .new]) {
                [weak self, weak scrollView] _, _ in
                guard let self, let scrollView else { return }
                self.refreshZoomGestureState(in: scrollView)
            }
        }

        func refreshZoomGestureState(in scrollView: UIScrollView) {
            let zoomed = scrollView.zoomScale > scrollView.minimumZoomScale + 0.01
            // A page at its fitted scale has nothing to pan. Letting the nested
            // WKWebView pan recognizer remain active makes it compete with the
            // enclosing UIPageViewController's landscape page-curl gesture,
            // particularly on older iPads. Pinching remains available because
            // UIScrollView uses a separate pinch recognizer; panning is restored
            // as soon as the page is genuinely zoomed.
            if scrollView.panGestureRecognizer.isEnabled != zoomed {
                scrollView.panGestureRecognizer.isEnabled = zoomed
            }
            onZoomChanged(zoomed)
        }

        func userContentController(
            _ userContentController: WKUserContentController,
            didReceive message: WKScriptMessage
        ) {
            if message.name == "readerPageReady",
               let body = message.body as? [String: Any],
               body["revision"] as? Int == htmlRevision {
                let stable = body["stable"] as? Bool == true
                DispatchQueue.main.async {
                    stable ? self.onReady() : self.onRenderFailure()
                }
                return
            }
            if message.name == "readerAnnexAction",
               let body = message.body as? [String: Any],
               let sectionID = body["sectionID"] as? Int,
               sectionID > 0 {
                if body["action"] as? String == "share" {
                    onShareAnnex(sectionID)
                } else {
                    onNavigateToSection(sectionID)
                }
                return
            }
            guard message.name == "readerSelection",
                  let body = message.body as? [String: Any],
                  let text = body["selectedText"] as? String,
                  !text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else { return }
            onTextSelection(
                ReaderTextSelection(
                    selectedText: text,
                    sourceFragmentID: body["sourceFragmentID"] as? String,
                    stableAnchor: body["stableAnchor"] as? String,
                    startOffset: body["startOffset"] as? Int ?? 0,
                    endOffset: body["endOffset"] as? Int ?? text.count,
                    prefix: body["prefix"] as? String,
                    suffix: body["suffix"] as? String,
                    existingHighlightID: (body["existingHighlightID"] as? String)
                        .flatMap(UUID.init(uuidString:)),
                    opensPersonalNote: body["opensPersonalNote"] as? Bool,
                    opensReviewerNote: body["opensReviewerNote"] as? Bool,
                    reviewThreadID: body["reviewThreadID"] as? String
                )
            )
        }

        func webView(_ webView: WKWebView, decidePolicyFor navigationAction: WKNavigationAction, decisionHandler: @escaping (WKNavigationActionPolicy) -> Void) {
            if navigationAction.navigationType == .linkActivated {
                if let url = navigationAction.request.url,
                   url.path.hasSuffix("/admin/compliance/controlled_book_editor.php"),
                   let sectionValue = URLComponents(
                       url: url,
                       resolvingAgainstBaseURL: false
                   )?.queryItems?.first(where: { $0.name == "section_id" })?.value,
                   let sectionID = Int(sectionValue),
                   sectionID > 0 {
                    onNavigateToSection(sectionID)
                } else if let url = navigationAction.request.url,
                   let fragment = url.fragment?.removingPercentEncoding,
                   !fragment.isEmpty {
                    onNavigateToAnchor(fragment)
                } else if let url = navigationAction.request.url {
                    onExternalLink(url)
                }
                decisionHandler(.cancel)
                return
            }
            decisionHandler(.allow)
        }
    }

    private static let selectionBridgeScript = """
    (function() {
      if (window.__ipcaReaderSelectionBridge) return;
      window.__ipcaReaderSelectionBridge = true;
      document.querySelectorAll('.cpb-annex-register-row[data-annex-link]').forEach(function(row) {
        const sectionID = Number(row.getAttribute('data-annex-link') || 0);
        if (!sectionID) return;
        row.setAttribute('role', 'button');
        row.tabIndex = 0;
        const titleCell = row.querySelector('.cpb-annex-register-col-title');
        if (titleCell && !titleCell.querySelector('.mr-annex-share')) {
          const share = document.createElement('button');
          share.type = 'button';
          share.className = 'mr-annex-share';
          share.textContent = '↗';
          share.setAttribute('aria-label', 'Share Annex PDF');
          share.style.cssText = 'float:right;width:18px;height:18px;padding:0;border:1px solid #0b3f73;'
            + 'border-radius:5px;background:#fff;color:#0b3f73;font-weight:800;line-height:15px;';
          share.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            window.webkit.messageHandlers.readerAnnexAction.postMessage({
              action: 'share', sectionID: sectionID
            });
          });
          titleCell.appendChild(share);
        }
        row.addEventListener('click', function(event) {
          if (event.target.closest('.mr-annex-share')) return;
          window.webkit.messageHandlers.readerAnnexAction.postMessage({
            action: 'open', sectionID: sectionID
          });
        });
      });
      function annotationTextNodes(scope) {
        const walker = document.createTreeWalker(scope, NodeFilter.SHOW_TEXT, {
          acceptNode(node) {
            const parent = node.parentElement;
            if (!node.nodeValue || !node.nodeValue.length || !parent) {
              return NodeFilter.FILTER_REJECT;
            }
            if (['SCRIPT', 'STYLE', 'BUTTON'].includes(parent.tagName)) {
              return NodeFilter.FILTER_REJECT;
            }
            return NodeFilter.FILTER_ACCEPT;
          }
        });
        const nodes = [];
        while (walker.nextNode()) nodes.push(walker.currentNode);
        return nodes;
      }
      function boundaryOffset(nodes, container, offset) {
        let total = 0;
        for (const node of nodes) {
          if (node === container) return total + Math.min(offset, node.nodeValue.length);
          if (container.nodeType === Node.ELEMENT_NODE) {
            const child = container.childNodes[offset] || null;
            if (child && (child === node || child.contains?.(node))) return total;
            if (!child && container.contains(node)) {
              total += node.nodeValue.length;
              continue;
            }
          }
          total += node.nodeValue.length;
        }
        return total;
      }
      document.addEventListener('touchend', function() {
        setTimeout(function() {
          const selection = window.getSelection();
          if (!selection || selection.rangeCount === 0 || selection.isCollapsed) return;
          const range = selection.getRangeAt(0);
          const startElement = range.startContainer.nodeType === Node.ELEMENT_NODE
            ? range.startContainer : range.startContainer.parentElement;
          const endElement = range.endContainer.nodeType === Node.ELEMENT_NODE
            ? range.endContainer : range.endContainer.parentElement;
          const startFragment = startElement && startElement.closest(
            '[data-source-fragment-id], [data-fragment-id], [data-source-fragment], [data-stable-anchor], [id]'
          );
          const endFragment = endElement && endElement.closest(
            '[data-source-fragment-id], [data-fragment-id], [data-source-fragment], [data-stable-anchor], [id]'
          );
          const sameFragment = startFragment && startFragment === endFragment;
          const fragment = sameFragment ? startFragment : null;
          const scope = fragment || document.querySelector('.mr-ios-frame') || document.body;
          const nodes = annotationTextNodes(scope);
          const scopeText = nodes.map(node => node.nodeValue).join('');
          const startOffset = boundaryOffset(nodes, range.startContainer, range.startOffset);
          const endOffset = boundaryOffset(nodes, range.endContainer, range.endOffset);
          const text = scopeText.substring(startOffset, endOffset);
          if (!text || !text.trim()) return;
          const existingMark = startElement
            && startElement.closest('.mr-user-highlight[data-highlight-id]');
          window.webkit.messageHandlers.readerSelection.postMessage({
            selectedText: text,
            sourceFragmentID: fragment?.getAttribute('data-source-fragment-id')
              || fragment?.getAttribute('data-fragment-id')
              || fragment?.getAttribute('data-source-fragment') || '',
            stableAnchor: fragment?.getAttribute('data-stable-anchor') || fragment?.id || '',
            startOffset: startOffset,
            endOffset: endOffset,
            prefix: scopeText.substring(Math.max(0, startOffset - 24), startOffset),
            suffix: scopeText.substring(endOffset, endOffset + 24),
            existingHighlightID: existingMark?.dataset.highlightId || '',
            opensPersonalNote: false
          });
        }, 20);
      }, { passive: true });
    })();
    """
}
