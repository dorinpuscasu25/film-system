import Foundation

enum LoadableState: Equatable {
    case idle
    case loading
    case loaded
    case failed(message: String)

    var isLoading: Bool { self == .loading }
    var errorMessage: String? { if case .failed(let message) = self { message } else { nil } }
}
