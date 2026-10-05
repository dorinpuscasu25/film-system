import Foundation

/// Mirrors the backend's production `Password::defaults()` (AppServiceProvider): at least 12
/// characters with upper- and lowercase letters, a digit and a symbol. The server additionally
/// rejects passwords found in known data breaches, which can't be checked on the device.
enum PasswordPolicy {
    static let minimumLength = 12

    static func isValid(_ password: String) -> Bool {
        password.count >= minimumLength
            && password.contains(where: \.isUppercase)
            && password.contains(where: \.isLowercase)
            && password.contains(where: \.isNumber)
            && password.contains(where: { !$0.isLetter && !$0.isNumber && !$0.isWhitespace })
    }
}
