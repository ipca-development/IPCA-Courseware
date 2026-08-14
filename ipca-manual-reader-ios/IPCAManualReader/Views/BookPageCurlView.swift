import SwiftUI
import UIKit

struct BookPageCurlView: UIViewControllerRepresentable {
    let pages: [FrozenPageMeta]
    let htmlByIndex: [Int: String]
    let baseURL: URL
    let isLandscape: Bool
    let pageSize: CGSize
    let pageBackground: Color
    @Binding var currentIndex: Int
    let onTap: () -> Void

    func makeCoordinator() -> Coordinator {
        Coordinator(parent: self)
    }

    func makeUIViewController(context: Context) -> UIPageViewController {
        let spine: UIPageViewController.SpineLocation = isLandscape ? .mid : .min
        let controller = UIPageViewController(
            transitionStyle: .pageCurl,
            navigationOrientation: .horizontal,
            options: [.spineLocation: NSNumber(value: spine.rawValue)]
        )
        controller.dataSource = context.coordinator
        controller.delegate = context.coordinator
        controller.isDoubleSided = isLandscape
        controller.view.backgroundColor = UIColor(pageBackground)

        let tap = UITapGestureRecognizer(target: context.coordinator, action: #selector(Coordinator.didTap))
        tap.cancelsTouchesInView = false
        tap.delegate = context.coordinator
        controller.view.addGestureRecognizer(tap)

        context.coordinator.controller = controller
        context.coordinator.installVisiblePages(animated: false, direction: .forward)
        return controller
    }

    func updateUIViewController(_ controller: UIPageViewController, context: Context) {
        context.coordinator.parent = self
        controller.view.backgroundColor = UIColor(pageBackground)
        context.coordinator.refreshCachedPages()
        guard !context.coordinator.isTransitioning else { return }
        context.coordinator.installVisiblePages(animated: false, direction: .forward)
    }

    final class Coordinator: NSObject, UIPageViewControllerDataSource, UIPageViewControllerDelegate, UIGestureRecognizerDelegate {
        var parent: BookPageCurlView
        weak var controller: UIPageViewController?
        fileprivate var cache: [Int: PageHostController] = [:]
        var isTransitioning = false

        init(parent: BookPageCurlView) {
            self.parent = parent
        }

        @objc func didTap() {
            parent.onTap()
        }

        func gestureRecognizer(
            _ gestureRecognizer: UIGestureRecognizer,
            shouldRecognizeSimultaneouslyWith otherGestureRecognizer: UIGestureRecognizer
        ) -> Bool {
            true
        }

        func refreshCachedPages() {
            for (position, controller) in cache where parent.pages.indices.contains(position) {
                guard let html = parent.htmlByIndex[position] else { continue }
                controller.update(
                    html: html,
                    baseURL: parent.baseURL,
                    isLandscape: parent.isLandscape,
                    pageNumber: parent.pages[position].pageNumber,
                    pageSize: parent.pageSize,
                    pageBackground: parent.pageBackground
                )
            }
        }

        func installVisiblePages(
            animated: Bool,
            direction: UIPageViewController.NavigationDirection
        ) {
            guard let controller, !parent.pages.isEmpty else { return }
            let positions = visiblePositions(focusedIndex: parent.currentIndex)
            let pageControllers = positions.map { pageController(at: $0) }
            let currentPositions = controller.viewControllers?.compactMap { ($0 as? PageHostController)?.position } ?? []
            if currentPositions != positions {
                controller.setViewControllers(pageControllers, direction: direction, animated: animated)
            }
            pruneCache(around: parent.currentIndex)
        }

        private func visiblePositions(focusedIndex: Int) -> [Int] {
            let safeIndex = min(max(0, focusedIndex), parent.pages.count - 1)
            guard parent.isLandscape else { return [safeIndex] }

            let pageNumber = parent.pages[safeIndex].pageNumber
            if pageNumber == 1 {
                return [-1, safeIndex]
            }
            if pageNumber.isMultiple(of: 2) {
                let right = index(forPageNumber: pageNumber + 1) ?? parent.pages.count
                return [safeIndex, right]
            }
            let left = index(forPageNumber: pageNumber - 1) ?? -1
            return [left, safeIndex]
        }

        private func index(forPageNumber pageNumber: Int) -> Int? {
            parent.pages.firstIndex { $0.pageNumber == pageNumber }
        }

        private func pruneCache(around index: Int) {
            let retainedRange = (index - 4)...(index + 4)
            let visible = Set(
                controller?.viewControllers?.compactMap { ($0 as? PageHostController)?.position } ?? []
            )
            cache = cache.filter { position, _ in
                position == -1
                    || position == parent.pages.count
                    || retainedRange.contains(position)
                    || visible.contains(position)
            }
        }

        private func pageController(at position: Int) -> PageHostController {
            if let cached = cache[position] {
                return cached
            }

            let host: PageHostController
            if parent.pages.indices.contains(position), let html = parent.htmlByIndex[position] {
                host = PageHostController(
                    position: position,
                    html: html,
                    baseURL: parent.baseURL,
                    isLandscape: parent.isLandscape,
                    pageNumber: parent.pages[position].pageNumber,
                    pageSize: parent.pageSize,
                    pageBackground: parent.pageBackground
                )
            } else {
                host = PageHostController(
                    position: position,
                    pageBackground: parent.pageBackground
                )
            }
            cache[position] = host
            return host
        }

        func pageViewController(
            _ pageViewController: UIPageViewController,
            viewControllerBefore viewController: UIViewController
        ) -> UIViewController? {
            guard let page = viewController as? PageHostController else { return nil }
            let previous = page.position - 1
            guard previous >= -1 else { return nil }
            return pageController(at: previous)
        }

        func pageViewController(
            _ pageViewController: UIPageViewController,
            viewControllerAfter viewController: UIViewController
        ) -> UIViewController? {
            guard let page = viewController as? PageHostController else { return nil }
            let next = page.position + 1
            guard next <= parent.pages.count else { return nil }
            return pageController(at: next)
        }

        func pageViewController(
            _ pageViewController: UIPageViewController,
            willTransitionTo pendingViewControllers: [UIViewController]
        ) {
            isTransitioning = true
        }

        func pageViewController(
            _ pageViewController: UIPageViewController,
            didFinishAnimating finished: Bool,
            previousViewControllers: [UIViewController],
            transitionCompleted completed: Bool
        ) {
            isTransitioning = false
            guard completed else { return }
            let positions = pageViewController.viewControllers?
                .compactMap { ($0 as? PageHostController)?.position }
                .filter { parent.pages.indices.contains($0) } ?? []
            guard let focused = positions.max() else { return }
            pruneCache(around: focused)
            DispatchQueue.main.async {
                self.parent.currentIndex = focused
            }
        }

        func pageViewController(
            _ pageViewController: UIPageViewController,
            spineLocationFor orientation: UIInterfaceOrientation
        ) -> UIPageViewController.SpineLocation {
            parent.isLandscape ? .mid : .min
        }
    }
}

fileprivate final class PageHostController: UIHostingController<AnyView> {
    let position: Int

    init(
        position: Int,
        html: String? = nil,
        baseURL: URL? = nil,
        isLandscape: Bool = false,
        pageNumber: Int = 0,
        pageSize: CGSize = CGSize(
            width: ManualPageLayout.width,
            height: ManualPageLayout.height
        ),
        pageBackground: Color = .white
    ) {
        self.position = position
        super.init(rootView: AnyView(pageBackground))
        view.backgroundColor = UIColor(pageBackground)
        if let html, let baseURL {
            update(
                html: html,
                baseURL: baseURL,
                isLandscape: isLandscape,
                pageNumber: pageNumber,
                pageSize: pageSize,
                pageBackground: pageBackground
            )
        }
    }

    @MainActor required dynamic init?(coder aDecoder: NSCoder) {
        fatalError("init(coder:) has not been implemented")
    }

    func update(
        html: String,
        baseURL: URL,
        isLandscape: Bool,
        pageNumber: Int,
        pageSize: CGSize,
        pageBackground: Color
    ) {
        view.backgroundColor = UIColor(pageBackground)
        rootView = AnyView(
            PhysicalManualPage(
                html: html,
                baseURL: baseURL,
                isLandscape: isLandscape,
                isLeftPage: pageNumber.isMultiple(of: 2),
                pageSize: pageSize,
                pageBackground: pageBackground
            )
        )
    }
}

private struct PhysicalManualPage: View {
    let html: String
    let baseURL: URL
    let isLandscape: Bool
    let isLeftPage: Bool
    let pageSize: CGSize
    let pageBackground: Color

    var body: some View {
        GeometryReader { proxy in
            ManualPageWebView(
                html: html,
                baseURL: baseURL,
                zoomMode: .fitPage,
                containerSize: proxy.size,
                pageSize: pageSize,
                pageBackground: pageBackground
            )
            .background(pageBackground)
            .overlay {
                if isLandscape {
                    LinearGradient(
                        colors: isLeftPage
                            ? [.clear, .black.opacity(0.035)]
                            : [.black.opacity(0.035), .clear],
                        startPoint: .leading,
                        endPoint: .trailing
                    )
                    .frame(width: 4)
                    .frame(maxWidth: .infinity, alignment: isLeftPage ? .trailing : .leading)
                    .allowsHitTesting(false)
                }
            }
            .clipped()
        }
        .background(pageBackground)
    }
}

struct BookGutterView: View {
    var body: some View {
        LinearGradient(
            colors: [.clear, .black.opacity(0.07), .clear],
            startPoint: .leading,
            endPoint: .trailing
        )
        .frame(width: 8)
        .allowsHitTesting(false)
    }
}
