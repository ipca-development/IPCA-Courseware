import SwiftUI
import WebKit

struct ManualPageWebView: UIViewRepresentable {
    let html: String
    let baseURL: URL
    var zoomMode: ReaderZoomMode
    var containerSize: CGSize

    func makeCoordinator() -> Coordinator {
        Coordinator()
    }

    func makeUIView(context: Context) -> WKWebView {
        let config = WKWebViewConfiguration()
        config.defaultWebpagePreferences.allowsContentJavaScript = false
        let webView = WKWebView(frame: .zero, configuration: config)
        webView.isOpaque = false
        webView.backgroundColor = .clear
        webView.scrollView.backgroundColor = .clear
        webView.scrollView.isScrollEnabled = false
        webView.scrollView.bounces = false
        webView.scrollView.minimumZoomScale = 1
        webView.scrollView.maximumZoomScale = 1
        webView.navigationDelegate = context.coordinator
        return webView
    }

    func updateUIView(_ webView: WKWebView, context: Context) {
        let scale = ManualPageScale.scale(for: zoomMode, containerSize: containerSize)
        let js = """
        (function() {
          var frame = document.querySelector('.mr-ios-frame');
          if (!frame) return;
          frame.style.transform = 'scale(\(scale))';
          frame.style.marginBottom = '\((ManualPageLayout.height * scale) - ManualPageLayout.height)px';
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

        func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
            isLoaded = true
            if !pendingScaleJS.isEmpty {
                webView.evaluateJavaScript(pendingScaleJS, completionHandler: nil)
            }
        }

        func webView(_ webView: WKWebView, decidePolicyFor navigationAction: WKNavigationAction, decisionHandler: @escaping (WKNavigationActionPolicy) -> Void) {
            if navigationAction.navigationType == .linkActivated {
                decisionHandler(.cancel)
                return
            }
            decisionHandler(.allow)
        }
    }
}
