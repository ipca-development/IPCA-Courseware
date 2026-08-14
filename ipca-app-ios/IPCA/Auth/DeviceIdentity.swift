import Foundation
import UIKit

enum DeviceIdentity {
    private static let defaultsKey = "ipca.app.deviceUUID"

    static var deviceUUID: String {
        if let existing = UserDefaults.standard.string(forKey: defaultsKey), !existing.isEmpty {
            return existing
        }
        let created = UUID().uuidString.lowercased()
        UserDefaults.standard.set(created, forKey: defaultsKey)
        return created
    }

    static var platform: String {
        UIDevice.current.userInterfaceIdiom == .pad ? "ipad" : "iphone"
    }

    static var model: String {
        UIDevice.current.model
    }

    static var osVersion: String {
        UIDevice.current.systemVersion
    }

    static var appVersion: String {
        Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "1.0.0"
    }

    static var payload: [String: String] {
        [
            "device_uuid": deviceUUID,
            "platform": platform,
            "model": model,
            "os_version": osVersion,
            "app_version": appVersion
        ]
    }

    static var apnsEnvironment: String {
        #if DEBUG
        return "sandbox"
        #else
        return "production"
        #endif
    }
}
