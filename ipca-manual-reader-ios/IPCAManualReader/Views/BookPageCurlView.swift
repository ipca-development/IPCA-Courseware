import SwiftUI
import UIKit

struct BookPageCurlView: UIViewControllerRepresentable {
    let pages: [FrozenPageMeta]
    let htmlByIndex: [Int: String]
    let baseURL: URL
    let isLandscape: Bool
    let pageSize: CGSize
    let pageBackground: Color
    let bookKey: String
    @Binding var currentIndex: Int
    let onTap: (Int) -> Void
    let onPageReady: (Int) -> Void
    let onNavigateToAnchor: (String) -> Void
    let onNavigateToSection: (Int) -> Void
    let onExternalLink: (URL) -> Void
    let onTextSelection: (Int, ReaderTextSelection) -> Void

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
        private var zoomedPositions: Set<Int> = []
        var isTransitioning = false

        init(parent: BookPageCurlView) {
            self.parent = parent
        }

        @objc func didTap(_ recognizer: UITapGestureRecognizer) {
            guard let controller else { return }
            let location = recognizer.location(in: controller.view)
            let visible = controller.viewControllers?
                .compactMap { $0 as? PageHostController }
                .filter { parent.pages.indices.contains($0.position) } ?? []
            if let hit = visible.first(where: {
                let frame = $0.view.convert($0.view.bounds, to: controller.view)
                return frame.contains(location)
            }) {
                parent.onTap(hit.position)
                return
            }
            let positions = visible.map(\.position).sorted()
            guard !positions.isEmpty else { return }
            if parent.isLandscape && positions.count > 1 {
                parent.onTap(location.x < controller.view.bounds.midX ? positions[0] : positions[1])
            } else {
                parent.onTap(positions[0])
            }
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
                    bookKey: parent.bookKey,
                    pageSize: parent.pageSize,
                    pageBackground: parent.pageBackground,
                    onReady: { [weak self] in self?.parent.onPageReady(position) },
                    onNavigateToAnchor: parent.onNavigateToAnchor,
                    onNavigateToSection: parent.onNavigateToSection,
                    onExternalLink: parent.onExternalLink,
                    onTextSelection: { [weak self] selection in
                        self?.parent.onTextSelection(position, selection)
                    },
                    onZoomChanged: { [weak self] zoomed in
                        self?.setZoomed(zoomed, at: position)
                    }
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
                let right = index(forPageNumber: 2) ?? parent.pages.count
                return [safeIndex, right]
            }
            if !pageNumber.isMultiple(of: 2) {
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
                    bookKey: parent.bookKey,
                    pageSize: parent.pageSize,
                    pageBackground: parent.pageBackground,
                    onReady: { [weak self] in self?.parent.onPageReady(position) },
                    onNavigateToAnchor: parent.onNavigateToAnchor,
                    onNavigateToSection: parent.onNavigateToSection,
                    onExternalLink: parent.onExternalLink,
                    onTextSelection: { [weak self] selection in
                        self?.parent.onTextSelection(position, selection)
                    },
                    onZoomChanged: { [weak self] zoomed in
                        self?.setZoomed(zoomed, at: position)
                    }
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

        private func setZoomed(_ zoomed: Bool, at position: Int) {
            if zoomed {
                zoomedPositions.insert(position)
            } else {
                zoomedPositions.remove(position)
            }
        }

        func pageViewController(
            _ pageViewController: UIPageViewController,
            viewControllerBefore viewController: UIViewController
        ) -> UIViewController? {
            guard let page = viewController as? PageHostController else { return nil }
            guard !zoomedPositions.contains(page.position) else { return nil }
            let previous = page.position - 1
            guard previous >= 0 else { return nil }
            return pageController(at: previous)
        }

        func pageViewController(
            _ pageViewController: UIPageViewController,
            viewControllerAfter viewController: UIViewController
        ) -> UIViewController? {
            guard let page = viewController as? PageHostController else { return nil }
            guard !zoomedPositions.contains(page.position) else { return nil }
            let next = page.position + 1
            guard next < parent.pages.count else {
                // A trailing blank is only needed when the final authoritative
                // page is a left-hand (odd-numbered) page. Supplying one after
                // a right-hand final page makes a mid-spine page curl request a
                // two-controller transition with only one controller.
                guard next == parent.pages.count,
                      parent.isLandscape,
                      parent.pages.last?.pageNumber.isMultiple(of: 2) == false else {
                    return nil
                }
                return pageController(at: next)
            }
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
        bookKey: String = "",
        pageSize: CGSize = CGSize(
            width: ManualPageLayout.width,
            height: ManualPageLayout.height
        ),
        pageBackground: Color = .white,
        onReady: @escaping () -> Void = {},
        onNavigateToAnchor: @escaping (String) -> Void = { _ in },
        onNavigateToSection: @escaping (Int) -> Void = { _ in },
        onExternalLink: @escaping (URL) -> Void = { _ in },
        onTextSelection: @escaping (ReaderTextSelection) -> Void = { _ in },
        onZoomChanged: @escaping (Bool) -> Void = { _ in }
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
                bookKey: bookKey,
                pageSize: pageSize,
                pageBackground: pageBackground,
                onReady: onReady,
                onNavigateToAnchor: onNavigateToAnchor,
                onNavigateToSection: onNavigateToSection,
                onExternalLink: onExternalLink,
                onTextSelection: onTextSelection,
                onZoomChanged: onZoomChanged
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
        bookKey: String,
        pageSize: CGSize,
        pageBackground: Color,
        onReady: @escaping () -> Void,
        onNavigateToAnchor: @escaping (String) -> Void,
        onNavigateToSection: @escaping (Int) -> Void,
        onExternalLink: @escaping (URL) -> Void,
        onTextSelection: @escaping (ReaderTextSelection) -> Void,
        onZoomChanged: @escaping (Bool) -> Void
    ) {
        view.backgroundColor = UIColor(pageBackground)
        rootView = AnyView(
            PhysicalManualPage(
                html: html,
                baseURL: baseURL,
                isLandscape: isLandscape,
                isLeftPage: !pageNumber.isMultiple(of: 2),
                pageNumber: pageNumber,
                bookKey: bookKey,
                pageSize: pageSize,
                pageBackground: pageBackground,
                onReady: onReady,
                onNavigateToAnchor: onNavigateToAnchor,
                onNavigateToSection: onNavigateToSection,
                onExternalLink: onExternalLink,
                onTextSelection: onTextSelection,
                onZoomChanged: onZoomChanged
            )
        )
    }
}

private struct PhysicalManualPage: View {
    @ObservedObject private var session = ManualReaderSessionStore.shared

    let html: String
    let baseURL: URL
    let isLandscape: Bool
    let isLeftPage: Bool
    let pageNumber: Int
    let bookKey: String
    let pageSize: CGSize
    let pageBackground: Color
    let onReady: () -> Void
    let onNavigateToAnchor: (String) -> Void
    let onNavigateToSection: (Int) -> Void
    let onExternalLink: (URL) -> Void
    let onTextSelection: (ReaderTextSelection) -> Void
    let onZoomChanged: (Bool) -> Void

    var body: some View {
        GeometryReader { proxy in
            ManualPageWebView(
                html: html,
                baseURL: baseURL,
                zoomMode: session.settings.zoom,
                containerSize: proxy.size,
                pageSize: pageSize,
                pageBackground: pageBackground,
                onReady: onReady,
                onNavigateToAnchor: onNavigateToAnchor,
                onNavigateToSection: onNavigateToSection,
                onExternalLink: onExternalLink,
                onZoomChanged: onZoomChanged,
                onTextSelection: onTextSelection
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
            .overlay(alignment: .topLeading) {
                if let ordinal = session.bookmarkOrdinal(
                    for: bookKey,
                    pageNumber: pageNumber
                ) {
                    ZStack(alignment: .top) {
                        Image(systemName: "bookmark.fill")
                            .font(.system(size: 36, weight: .bold))
                            .foregroundStyle(IPCAReaderTheme.navy)
                        Text("\(ordinal)")
                            .font(.system(size: ordinal > 99 ? 8 : 10, weight: .bold))
                            .foregroundStyle(.white)
                            .monospacedDigit()
                            .padding(.top, 7)
                    }
                        .shadow(color: .black.opacity(0.18), radius: 1, y: 1)
                        .padding(.top, 4)
                        .padding(.leading, 8)
                        .allowsHitTesting(false)
                        .accessibilityLabel("Bookmark \(ordinal)")
                }
            }
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
