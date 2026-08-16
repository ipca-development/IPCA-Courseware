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

    func makeCoordinator() -> Coordinator {
        Coordinator(
            onReady: onReady,
            onNavigateToAnchor: onNavigateToAnchor,
            onNavigateToSection: onNavigateToSection,
            onExternalLink: onExternalLink
        )
    }

    func makeUIView(context: Context) -> WKWebView {
        let config = WKWebViewConfiguration()
        config.defaultWebpagePreferences.allowsContentJavaScript = false
        let webView = WKWebView(frame: .zero, configuration: config)
        webView.isOpaque = true
        webView.backgroundColor = UIColor(pageBackground)
        webView.scrollView.backgroundColor = UIColor(pageBackground)
        context.coordinator.onReady = onReady
        context.coordinator.onNavigateToAnchor = onNavigateToAnchor
        context.coordinator.onNavigateToSection = onNavigateToSection
        context.coordinator.onExternalLink = onExternalLink
        webView.scrollView.isScrollEnabled = false
        webView.scrollView.bounces = false
        webView.scrollView.minimumZoomScale = 1
        webView.scrollView.maximumZoomScale = 1
        webView.navigationDelegate = context.coordinator
        return webView
    }

    func updateUIView(_ webView: WKWebView, context: Context) {
        webView.backgroundColor = UIColor(pageBackground)
        webView.scrollView.backgroundColor = UIColor(pageBackground)
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

    final class Coordinator: NSObject, WKNavigationDelegate {
        var lastHTML: String = ""
        var pendingScaleJS: String = ""
        var isLoaded = false
        var onReady: () -> Void
        var onNavigateToAnchor: (String) -> Void
        var onNavigateToSection: (Int) -> Void
        var onExternalLink: (URL) -> Void

        init(
            onReady: @escaping () -> Void,
            onNavigateToAnchor: @escaping (String) -> Void,
            onNavigateToSection: @escaping (Int) -> Void,
            onExternalLink: @escaping (URL) -> Void
        ) {
            self.onReady = onReady
            self.onNavigateToAnchor = onNavigateToAnchor
            self.onNavigateToSection = onNavigateToSection
            self.onExternalLink = onExternalLink
        }

        func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
            isLoaded = true
            if !pendingScaleJS.isEmpty {
                webView.evaluateJavaScript(pendingScaleJS, completionHandler: nil)
            }
            DispatchQueue.main.async { self.onReady() }
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
}
