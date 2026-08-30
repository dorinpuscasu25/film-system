import AVFoundation
import Foundation
import Observation
import OSLog

/// A selectable subtitle or audio track exposed to the player UI.
struct PlayerMediaOption: Identifiable, Hashable {
    let id: String
    let title: String
}

/// Caps the HLS variant the player is allowed to pick.
///
/// AVPlayer always adapts to the network, so these are ceilings rather than
/// fixed resolutions — `auto` removes the cap entirely.
enum PlaybackQuality: String, CaseIterable, Identifiable {
    case auto, high, medium, low

    var id: String { rawValue }

    /// Peak bitrate in bits per second; `0` means unlimited.
    var preferredPeakBitRate: Double {
        switch self {
        case .auto: 0
        case .high: 6_000_000
        case .medium: 3_000_000
        case .low: 1_200_000
        }
    }
}

@MainActor @Observable
final class PlayerViewModel {
    enum LoadingState: Equatable {
        case idle
        case loading
        case ready
        case failed(String)
    }

    /// Playback speeds offered in the settings sheet.
    static let availableSpeeds: [Float] = [0.5, 0.75, 1.0, 1.25, 1.5, 2.0]

    private let playback: any PlaybackRepositoryProtocol
    private let configuration: AppConfiguration
    private(set) var player: AVPlayer?
    private(set) var loadingState: LoadingState
    let request: PlayerRequest
    private var observer: Any?
    private var itemStatusObserver: NSKeyValueObservation?
    private var playbackFailureObserver: NSObjectProtocol?
    private var fairPlayLoader: BunnyFairPlayResourceLoader?
    private var preparationTask: Task<Void, Never>?
    private var readinessTimeoutTask: Task<Void, Never>?
    private let logger = Logger(subsystem: "md.filmoteca.ios", category: "Player")

    // MARK: - Track, quality and speed selection

    private(set) var subtitleOptions: [PlayerMediaOption] = []
    private(set) var audioOptions: [PlayerMediaOption] = []
    /// `nil` means subtitles are off.
    private(set) var selectedSubtitleID: String?
    private(set) var selectedAudioID: String?
    private(set) var quality: PlaybackQuality = .auto
    private(set) var speed: Float = 1.0

    private var legibleGroup: AVMediaSelectionGroup?
    private var audibleGroup: AVMediaSelectionGroup?
    private var mediaOptionsTask: Task<Void, Never>?

    /// Embedded (iframe) sources are driven by the remote page, so the native
    /// settings sheet only applies once we own an `AVPlayer`.
    var hasPlaybackSettings: Bool {
        if case .embedded = request.source { return false }
        return player != nil
    }

    init(request: PlayerRequest, container: AppContainer) {
        self.request = request
        playback = container.playbackRepository
        configuration = container.configuration
        if case .native(let url) = request.source {
            player = AVPlayer(url: url)
            loadingState = .ready
        } else {
            player = nil
            loadingState = request.source.isEmbedded ? .ready : .idle
        }
    }

    func start() {
        switch request.source {
        case .native:
            guard let player else { return }
            beginPlayback(player)
        case .embedded:
            break
        case .bunny(let reference):
            guard preparationTask == nil, player == nil else {
                player?.play()
                return
            }
            loadingState = .loading
            preparationTask = Task { [weak self] in
                await self?.prepareBunny(reference)
            }
        }
    }

    func retry() {
        cleanupPlayer()
        loadingState = .idle
        start()
    }

    private func prepareBunny(_ reference: BunnyVideoReference) async {
        do {
            let service = BunnyStreamService(refererURL: configuration.webBaseURL)
            let playlistURL = try await service.playlistURL(for: reference)
            try Task.checkCancellation()

            let loader = BunnyFairPlayResourceLoader(
                reference: reference,
                refererURL: configuration.webBaseURL
            )
            let item = loader.playerItem(playlistURL: playlistURL)
            let player = AVPlayer(playerItem: item)
            player.automaticallyWaitsToMinimizeStalling = true

            fairPlayLoader = loader
            self.player = player
            observe(item: item, player: player)
            scheduleReadinessTimeout()
        } catch is CancellationError {
            return
        } catch {
            logger.error("Bunny preparation failed: \(error.localizedDescription, privacy: .public)")
            loadingState = .failed(error.localizedDescription)
            preparationTask = nil
        }
    }

    private func observe(item: AVPlayerItem, player: AVPlayer) {
        itemStatusObserver = item.observe(\.status, options: [.initial, .new]) { [weak self, weak player] item, _ in
            Task { @MainActor in
                guard let self, let player else { return }
                switch item.status {
                case .readyToPlay:
                    self.readinessTimeoutTask?.cancel()
                    self.readinessTimeoutTask = nil
                    self.loadingState = .ready
                    self.preparationTask = nil
                    self.beginPlayback(player)
                case .failed:
                    self.readinessTimeoutTask?.cancel()
                    self.readinessTimeoutTask = nil
                    let message = item.error?.localizedDescription ?? "Fluxul DRM nu a putut fi redat."
                    self.logger.error("AVPlayer item failed: \(message, privacy: .public)")
                    player.pause()
                    self.player = nil
                    self.loadingState = .failed(message)
                    self.preparationTask = nil
                case .unknown:
                    break
                @unknown default:
                    break
                }
            }
        }

        playbackFailureObserver = NotificationCenter.default.addObserver(
            forName: .AVPlayerItemFailedToPlayToEndTime,
            object: item,
            queue: .main
        ) { [weak self] notification in
            let message = (notification.userInfo?[AVPlayerItemFailedToPlayToEndTimeErrorKey] as? NSError)?
                .localizedDescription ?? "Redarea video s-a întrerupt."
            Task { @MainActor in
                guard let self else { return }
                self.logger.error("Playback failed: \(message, privacy: .public)")
                self.loadingState = .failed(message)
            }
        }
    }

    private func scheduleReadinessTimeout() {
        readinessTimeoutTask?.cancel()
        readinessTimeoutTask = Task { [weak self] in
            try? await Task.sleep(for: .seconds(18))
            guard !Task.isCancelled, let self, self.loadingState == .loading else { return }
            self.logger.error("Native FairPlay playback timed out before becoming ready.")
            self.player?.pause()
            self.player = nil
            self.loadingState = .failed("Licența FairPlay nu a răspuns la timp.")
            self.preparationTask = nil
        }
    }

    private func beginPlayback(_ player: AVPlayer) {
        configureAudioSession()
        if request.startPosition > 2, player.currentTime().seconds < 1 {
            player.seek(to: CMTime(seconds: request.startPosition, preferredTimescale: 600))
        }
        if let item = player.currentItem { loadMediaOptions(for: item) }
        player.play()
        guard observer == nil else { return }
        observer = player.addPeriodicTimeObserver(forInterval: CMTime(seconds: 10, preferredTimescale: 1), queue: .main) { [weak self] _ in
            Task { @MainActor in self?.report(event: "progress") }
        }
    }

    // MARK: - Playback settings

    /// Reads the subtitle and audio tracks the HLS stream advertises.
    ///
    /// Called once the item is ready — media selection groups are not populated
    /// before the master playlist has been parsed.
    private func loadMediaOptions(for item: AVPlayerItem) {
        mediaOptionsTask?.cancel()
        mediaOptionsTask = Task { [weak self] in
            let asset = item.asset
            let legible = try? await asset.loadMediaSelectionGroup(for: .legible)
            let audible = try? await asset.loadMediaSelectionGroup(for: .audible)

            guard !Task.isCancelled, let self else { return }

            self.legibleGroup = legible
            self.audibleGroup = audible
            self.subtitleOptions = Self.options(from: legible)
            self.audioOptions = Self.options(from: audible)

            let selection = item.currentMediaSelection
            if let legible, let current = selection.selectedMediaOption(in: legible) {
                self.selectedSubtitleID = legible.options.firstIndex(of: current).map(String.init)
            }
            if let audible, let current = selection.selectedMediaOption(in: audible) {
                self.selectedAudioID = audible.options.firstIndex(of: current).map(String.init)
            }
        }
    }

    private static func options(from group: AVMediaSelectionGroup?) -> [PlayerMediaOption] {
        guard let group else { return [] }
        return group.options.enumerated().map { index, option in
            PlayerMediaOption(id: String(index), title: option.displayName)
        }
    }

    /// Passing `nil` turns subtitles off.
    func selectSubtitle(id: String?) {
        selectedSubtitleID = id
        apply(optionID: id, in: legibleGroup)
    }

    func selectAudio(id: String?) {
        selectedAudioID = id
        apply(optionID: id, in: audibleGroup)
    }

    private func apply(optionID: String?, in group: AVMediaSelectionGroup?) {
        guard let group, let item = player?.currentItem else { return }

        guard let optionID, let index = Int(optionID), group.options.indices.contains(index) else {
            item.select(nil, in: group)
            return
        }

        item.select(group.options[index], in: group)
    }

    func selectQuality(_ newQuality: PlaybackQuality) {
        quality = newQuality
        player?.currentItem?.preferredPeakBitRate = newQuality.preferredPeakBitRate
    }

    func selectSpeed(_ newSpeed: Float) {
        speed = newSpeed
        guard let player else { return }
        // `defaultRate` keeps the speed after the user pauses and resumes.
        player.defaultRate = newSpeed
        if player.timeControlStatus != .paused { player.rate = newSpeed }
    }

    func stop() {
        preparationTask?.cancel()
        preparationTask = nil
        mediaOptionsTask?.cancel()
        mediaOptionsTask = nil
        readinessTimeoutTask?.cancel()
        readinessTimeoutTask = nil
        if player != nil { report(event: "pause") }
        cleanupPlayer()
    }

    private func cleanupPlayer() {
        readinessTimeoutTask?.cancel()
        readinessTimeoutTask = nil
        player?.pause()
        if let observer, let player { player.removeTimeObserver(observer) }
        observer = nil
        itemStatusObserver?.invalidate()
        itemStatusObserver = nil
        if let playbackFailureObserver { NotificationCenter.default.removeObserver(playbackFailureObserver) }
        playbackFailureObserver = nil
        player = nil
        fairPlayLoader = nil
        mediaOptionsTask?.cancel()
        mediaOptionsTask = nil
        legibleGroup = nil
        audibleGroup = nil
        subtitleOptions = []
        audioOptions = []
        selectedSubtitleID = nil
        selectedAudioID = nil
    }

    private func configureAudioSession() {
        do {
            let session = AVAudioSession.sharedInstance()
            try session.setCategory(.playback, mode: .moviePlayback)
            try session.setActive(true)
        } catch {
            logger.error("Audio session failed: \(error.localizedDescription, privacy: .public)")
        }
    }

    private func report(event: String) {
        guard let player, let tracking = request.tracking else { return }
        let position = player.currentTime().seconds
        let duration = player.currentItem?.duration.seconds ?? 0
        Task { await playback.track(tracking, position: position.isFinite ? max(position, 0) : 0, duration: duration.isFinite ? duration : 0, event: event) }
    }
}

private extension MediaPlaybackSource {
    var isEmbedded: Bool {
        if case .embedded = self { return true }
        return false
    }
}
