import SwiftUI
import WebKit

struct ManualPageWebView: UIViewRepresentable {
    let html: String
    let baseURL: URL
    var zoomMode: ReaderZoomMode
    var containerSize: CGSize
    var pageSize: CGSize
    var pageBackground: Color
    var onReady: () -> Void
    var onNavigateToAnchor: (String) -> Void
    var onNavigateToSection: (Int) -> Void
    var onExternalLink: (URL) -> Void
    var onZoomChanged: (Bool) -> Void
    var onTextSelection: (ReaderTextSelection) -> Void

    func makeCoordinator() -> Coordinator {
        Coordinator(
            onReady: onReady,
            onNavigateToAnchor: onNavigateToAnchor,
            onNavigateToSection: onNavigateToSection,
            onExternalLink: onExternalLink,
            onZoomChanged: onZoomChanged,
            onTextSelection: onTextSelection
        )
    }

    func makeUIView(context: Context) -> WKWebView {
        let config = WKWebViewConfiguration()
        config.defaultWebpagePreferences.allowsContentJavaScript = true
        config.userContentController.add(context.coordinator, name: "readerSelection")
        config.userContentController.addUserScript(
            WKUserScript(
                source: Self.selectionBridgeScript,
                injectionTime: .atDocumentEnd,
                forMainFrameOnly: true
            )
        )
        let webView = WKWebView(frame: .zero, configuration: config)
        webView.isOpaque = true
        webView.backgroundColor = UIColor(pageBackground)
        webView.scrollView.backgroundColor = UIColor(pageBackground)
        context.coordinator.onReady = onReady
        context.coordinator.onNavigateToAnchor = onNavigateToAnchor
        context.coordinator.onNavigateToSection = onNavigateToSection
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
        context.coordinator.onNavigateToAnchor = onNavigateToAnchor
        context.coordinator.onNavigateToSection = onNavigateToSection
        context.coordinator.onExternalLink = onExternalLink
        context.coordinator.onZoomChanged = onZoomChanged
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
        let js = """
        (function() {
          var frame = document.querySelector('.mr-ios-frame');
          if (!frame) return;
          frame.style.transform = 'none';
          var contentWidth = Math.max(frame.offsetWidth, 1);
          var contentHeight = Math.max(frame.offsetHeight, 1);
          var isLayoutBound = frame.getAttribute('data-layout-bound') === '1';
          var scale = isLayoutBound ? 1 : \(scaleExpression);
          frame.style.transform = 'scale(' + scale + ')';
          frame.style.marginBottom = ((contentHeight * scale) - contentHeight) + 'px';
        })();
        """
        context.coordinator.pendingScaleJS = js

        if context.coordinator.lastHTML != html {
            context.coordinator.lastHTML = html
            webView.loadHTMLString(html, baseURL: baseURL)
        } else if context.coordinator.isLoaded {
            webView.evaluateJavaScript(js, completionHandler: nil)
        }
    }

    final class Coordinator: NSObject, WKNavigationDelegate, WKScriptMessageHandler {
        var lastHTML: String = ""
        var pendingScaleJS: String = ""
        var isLoaded = false
        var onReady: () -> Void
        var onNavigateToAnchor: (String) -> Void
        var onNavigateToSection: (Int) -> Void
        var onExternalLink: (URL) -> Void
        var onZoomChanged: (Bool) -> Void
        var onTextSelection: (ReaderTextSelection) -> Void
        private var zoomObservation: NSKeyValueObservation?

        init(
            onReady: @escaping () -> Void,
            onNavigateToAnchor: @escaping (String) -> Void,
            onNavigateToSection: @escaping (Int) -> Void,
            onExternalLink: @escaping (URL) -> Void,
            onZoomChanged: @escaping (Bool) -> Void,
            onTextSelection: @escaping (ReaderTextSelection) -> Void
        ) {
            self.onReady = onReady
            self.onNavigateToAnchor = onNavigateToAnchor
            self.onNavigateToSection = onNavigateToSection
            self.onExternalLink = onExternalLink
            self.onZoomChanged = onZoomChanged
            self.onTextSelection = onTextSelection
        }

        func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
            isLoaded = true
            if !pendingScaleJS.isEmpty {
                webView.evaluateJavaScript(pendingScaleJS, completionHandler: nil)
            }
            DispatchQueue.main.async { self.onReady() }
        }

        func observeZoom(in scrollView: UIScrollView) {
            zoomObservation = scrollView.observe(\.zoomScale, options: [.initial, .new]) {
                [weak self, weak scrollView] _, _ in
                guard let self, let scrollView else { return }
                self.onZoomChanged(
                    scrollView.zoomScale > scrollView.minimumZoomScale + 0.01
                )
            }
        }

        func userContentController(
            _ userContentController: WKUserContentController,
            didReceive message: WKScriptMessage
        ) {
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
                    suffix: body["suffix"] as? String
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
      document.addEventListener('touchend', function() {
        setTimeout(function() {
          const selection = window.getSelection();
          if (!selection || selection.rangeCount === 0 || selection.isCollapsed) return;
          const range = selection.getRangeAt(0);
          const text = selection.toString();
          if (!text || !text.trim()) return;
          let element = range.commonAncestorContainer.nodeType === Node.ELEMENT_NODE
            ? range.commonAncestorContainer : range.commonAncestorContainer.parentElement;
          const fragment = element && element.closest(
            '[data-source-fragment-id], [data-fragment-id], [data-source-fragment], [data-stable-anchor], [id]'
          );
          const scope = fragment || document.querySelector('.mr-ios-frame') || document.body;
          const prefixRange = document.createRange();
          prefixRange.selectNodeContents(scope);
          try { prefixRange.setEnd(range.startContainer, range.startOffset); } catch (_) {}
          const startOffset = prefixRange.toString().length;
          const scopeText = scope.textContent || '';
          window.webkit.messageHandlers.readerSelection.postMessage({
            selectedText: text,
            sourceFragmentID: fragment?.getAttribute('data-source-fragment-id')
              || fragment?.getAttribute('data-fragment-id')
              || fragment?.getAttribute('data-source-fragment') || '',
            stableAnchor: fragment?.getAttribute('data-stable-anchor') || fragment?.id || '',
            startOffset: startOffset,
            endOffset: startOffset + text.length,
            prefix: scopeText.substring(Math.max(0, startOffset - 24), startOffset),
            suffix: scopeText.substring(startOffset + text.length, startOffset + text.length + 24)
          });
        }, 20);
      }, { passive: true });
    })();
    """
}
