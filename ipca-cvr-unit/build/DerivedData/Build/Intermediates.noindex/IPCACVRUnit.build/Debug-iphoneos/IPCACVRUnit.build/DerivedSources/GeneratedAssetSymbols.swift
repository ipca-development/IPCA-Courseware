import Foundation
#if canImport(DeveloperToolsSupport)
import DeveloperToolsSupport
#endif

#if SWIFT_PACKAGE
private let resourceBundle = Foundation.Bundle.module
#else
private class ResourceBundleClass {}
private let resourceBundle = Foundation.Bundle(for: ResourceBundleClass.self)
#endif

// MARK: - Color Symbols -

@available(iOS 17.0, macOS 14.0, tvOS 17.0, watchOS 10.0, *)
extension DeveloperToolsSupport.ColorResource {

}

// MARK: - Image Symbols -

@available(iOS 17.0, macOS 14.0, tvOS 17.0, watchOS 10.0, *)
extension DeveloperToolsSupport.ImageResource {

    /// The "ipca_cvr_app_icon_dark" asset catalog image resource.
    static let ipcaCvrAppIconDark = DeveloperToolsSupport.ImageResource(name: "ipca_cvr_app_icon_dark", bundle: resourceBundle)

    /// The "ipca_cvr_app_icon_flat" asset catalog image resource.
    static let ipcaCvrAppIconFlat = DeveloperToolsSupport.ImageResource(name: "ipca_cvr_app_icon_flat", bundle: resourceBundle)

    /// The "ipca_cvr_app_icon_glassy" asset catalog image resource.
    static let ipcaCvrAppIconGlassy = DeveloperToolsSupport.ImageResource(name: "ipca_cvr_app_icon_glassy", bundle: resourceBundle)

    /// The "ipca_cvr_app_icon_standard" asset catalog image resource.
    static let ipcaCvrAppIconStandard = DeveloperToolsSupport.ImageResource(name: "ipca_cvr_app_icon_standard", bundle: resourceBundle)

    /// The "ipca_cvr_logo_official" asset catalog image resource.
    static let ipcaCvrLogoOfficial = DeveloperToolsSupport.ImageResource(name: "ipca_cvr_logo_official", bundle: resourceBundle)

}

