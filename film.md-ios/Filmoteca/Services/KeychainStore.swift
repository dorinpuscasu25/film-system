import Foundation
import Security

enum KeychainStore {
    private static let service = "md.filmoteca.ios"
    private static let account = "api-token"
    private static var cachedToken: String?

    static func readToken() -> String? {
        if let cachedToken { return cachedToken }

        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne,
        ]
        var item: CFTypeRef?
        guard SecItemCopyMatching(query as CFDictionary, &item) == errSecSuccess,
              let data = item as? Data else { return nil }
        let token = String(data: data, encoding: .utf8)
        cachedToken = token
        return token
    }

    static func saveToken(_ token: String) {
        // Keep the active session usable even when Keychain is temporarily
        // unavailable (for example, an unsigned Debug simulator build).
        cachedToken = token

        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
            kSecValueData as String: Data(token.utf8),
            kSecAttrAccessible as String: kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly,
        ]
        let status = SecItemAdd(query as CFDictionary, nil)
        if status == errSecDuplicateItem {
            let lookup: [String: Any] = [
                kSecClass as String: kSecClassGenericPassword,
                kSecAttrService as String: service,
                kSecAttrAccount as String: account,
            ]
            SecItemUpdate(
                lookup as CFDictionary,
                [kSecValueData as String: Data(token.utf8)] as CFDictionary
            )
        }
    }

    static func deleteToken() {
        cachedToken = nil
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
        ]
        SecItemDelete(query as CFDictionary)
    }
}
