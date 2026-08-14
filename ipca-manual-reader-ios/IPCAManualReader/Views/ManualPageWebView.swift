import SwiftUI
import WebKit

struct ManualPageWebView: UIViewRepresentable {
    let html: String
    let baseURL: URL
    var zoomMode: ReaderZoomMode
    var containerSize: CGSize
    var pageSize: CGSize
    var pageBackground: Color

    func makeCoordinator() -> Coordinator {
        Coordinator()
    }

    func makeUIView(context: Context) -> WKWebView {
        let config = WKWebViewConfiguration()
        config.defaultWebpagePreferences.allowsContentJavaScript = false
        let webView = WKWebView(frame: .zero, configuration: config)
        webView.isOpaque = true
        webView.backgroundColor = UIColor(pageBackground)
        webView.scrollView.backgroundColor = UIColor(pageBackground)
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
