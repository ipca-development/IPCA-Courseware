import Foundation

enum AnonymousSafetyReceiptStore {
    static let secretAccount = "safety.anonymous.receiptSecret"
    private static let receiptIDKey = "ipca.safety.anonymous.receiptID"

    static var receiptID: String? {
        UserDefaults.standard.string(forKey: receiptIDKey)
    }

    static var receiptSecret: String? {
        KeychainStore.string(for: secretAccount)
    }

    static func save(_ receipt: AnonymousSafetyReceipt) throws {
        try KeychainStore.setString(receipt.receiptSecret, for: secretAccount)
        UserDefaults.standard.set(receipt.receiptID, forKey: receiptIDKey)
    }

    static func clear() {
        KeychainStore.delete(account: secretAccount)
        UserDefaults.standard.removeObject(forKey: receiptIDKey)
    }
}
