import AVFoundation
import Observation
import OSLog
import UIKit

/// Drives the cold-start brand intro: black (same as the launch screen) → video → final frame
/// held until the first screen is ready → fade out. Owned by the app, so it plays at most once
/// per process: warm starts and extra iPad windows never see it.
@MainActor @Observable
final class IntroController {
    enum Phase: Equatable {
        /// Pure black while the player decodes its first frame — identical to the launch screen.
        case preparing
        case playing
        /// Static final frame on screen, waiting for the first screen to be ready.
        case holding
        case finished
    }

    private(set) var phase: Phase {
        didSet { if phase != oldValue { logger.debug("intro phase: \(String(describing: self.phase), privacy: .public)") } }
    }
    private(set) var showsLoadingIndicator = false
    var isVisible: Bool { phase != .finished }

    /// Set by `RootView` once the initial route (session + first screen data) is resolved.
    var isRouteReady = false {
        didSet { if isRouteReady { finishIfPossible() } }
    }

    @ObservationIgnored private(set) var player: AVPlayer?
    @ObservationIgnored let playerLayer = AVPlayerLayer()
    /// The static PNG, above the player. Toggled directly (not through SwiftUI) so it covers
    /// the video in the same Core Animation commit that releases the player — no black frame.
    @ObservationIgnored let finalFrameLayer = CALayer()
    @ObservationIgnored private var readyObservation: NSKeyValueObservation?
    @ObservationIgnored private var statusObservation: NSKeyValueObservation?
    @ObservationIgnored private var endTask: Task<Void, Never>?
    @ObservationIgnored private var timeoutTask: Task<Void, Never>?
    @ObservationIgnored private var indicatorTask: Task<Void, Never>?
    @ObservationIgnored private var previousAudioSession: (category: AVAudioSession.Category, mode: AVAudioSession.Mode, options: AVAudioSession.CategoryOptions)?
    @ObservationIgnored private var started = false
    private let logger = Logger(subsystem: "md.filmoteca.ios", category: "Intro")

    init(enabled: Bool = IntroConfiguration.isEnabled) {
        phase = enabled ? .preparing : .finished
        if enabled { OrientationLock.mask = .portrait }
        playerLayer.videoGravity = .resizeAspectFill
        playerLayer.backgroundColor = UIColor.black.cgColor
        finalFrameLayer.contents = Bundle.main
            .path(forResource: IntroConfiguration.finalFrameName, ofType: "png")
            .flatMap(UIImage.init(contentsOfFile:))?.cgImage
        finalFrameLayer.contentsGravity = .resizeAspectFill
        finalFrameLayer.backgroundColor = UIColor.black.cgColor
        finalFrameLayer.masksToBounds = true
        finalFrameLayer.isHidden = true
    }

    func start() {
        guard phase == .preparing, !started else { return }
        started = true
        logger.debug("intro start")

        if UIAccessibility.isReduceMotionEnabled {
            revealFinalFrame()
            Task { [weak self] in
                try? await Task.sleep(for: IntroConfiguration.reduceMotionHold)
                self?.reachEnd()
            }
            return
        }

        guard let url = Bundle.main.url(forResource: IntroConfiguration.videoName, withExtension: IntroConfiguration.videoExtension) else {
            fail("intro video missing from bundle")
            return
        }

        beginAmbientAudio()
        let item = AVPlayerItem(url: url)
        let player = AVPlayer(playerItem: item)
        player.preventsDisplaySleepDuringVideoPlayback = false
        player.allowsExternalPlayback = false
        self.player = player
        playerLayer.player = player

        // Start only once a decoded frame is actually on screen, so playback begins in sync
        // and nothing but black precedes it.
        readyObservation = playerLayer.observe(\.isReadyForDisplay, options: [.initial, .new]) { [weak self] layer, _ in
            guard layer.isReadyForDisplay else { return }
            Task { @MainActor in self?.beginPlayback() }
        }
        statusObservation = item.observe(\.status, options: [.new]) { [weak self] item, _ in
            guard item.status == .failed else { return }
            let message = item.error?.localizedDescription ?? "unknown"
            Task { @MainActor in self?.fail("intro item failed: \(message)") }
        }
        endTask = Task { [weak self] in
            for await _ in NotificationCenter.default.notifications(named: AVPlayerItem.didPlayToEndTimeNotification, object: item) {
                self?.reachEnd()
                return
            }
        }
        timeoutTask = Task { [weak self] in
            try? await Task.sleep(for: IntroConfiguration.firstFrameTimeout)
            guard !Task.isCancelled, let self, self.phase == .preparing else { return }
            self.fail("intro first frame not ready within \(IntroConfiguration.firstFrameTimeout)")
        }
    }

    /// Tap anywhere: jump straight to the exit.
    func skip() {
        guard phase == .preparing || phase == .playing else { return }
        reachEnd()
    }

    /// Opened from a deep link or notification: the user goes straight to the content.
    func cancelForExternalLaunch() {
        guard phase != .finished else { return }
        logger.info("intro skipped: external launch")
        tearDownPlayer()
        phase = .finished
        OrientationLock.mask = .allButUpsideDown
    }

    private func beginPlayback() {
        guard phase == .preparing, let player else { return }
        timeoutTask?.cancel()
        phase = .playing
        player.play()
    }

    private func fail(_ message: String) {
        guard phase == .preparing || phase == .playing else { return }
        logger.error("\(message, privacy: .public)")
        reachEnd()
    }

    private func reachEnd() {
        guard phase == .preparing || phase == .playing else { return }
        revealFinalFrame()
        tearDownPlayer()
        phase = .holding
        indicatorTask = Task { [weak self] in
            try? await Task.sleep(for: IntroConfiguration.loadingIndicatorDelay)
            guard !Task.isCancelled, let self, self.phase == .holding else { return }
            self.showsLoadingIndicator = true
        }
        finishIfPossible()
    }

    private func finishIfPossible() {
        guard phase == .holding, isRouteReady else { return }
        indicatorTask?.cancel()
        phase = .finished
        OrientationLock.mask = .allButUpsideDown
    }

    private func revealFinalFrame() {
        CATransaction.begin()
        CATransaction.setDisableActions(true)
        finalFrameLayer.isHidden = false
        CATransaction.commit()
    }

    private func tearDownPlayer() {
        timeoutTask?.cancel()
        endTask?.cancel()
        readyObservation?.invalidate()
        statusObservation?.invalidate()
        readyObservation = nil
        statusObservation = nil
        player?.pause()
        player?.replaceCurrentItem(with: nil)
        playerLayer.player = nil
        player = nil
        endAmbientAudio()
    }

    // MARK: Audio — mixes with whatever is already playing and obeys the silent switch.

    private func beginAmbientAudio() {
        let session = AVAudioSession.sharedInstance()
        previousAudioSession = (session.category, session.mode, session.categoryOptions)
        do {
            try session.setCategory(.ambient, mode: .default)
            try session.setActive(true)
        } catch {
            logger.error("intro audio session failed: \(error.localizedDescription, privacy: .public)")
        }
    }

    private func endAmbientAudio() {
        guard let previous = previousAudioSession else { return }
        previousAudioSession = nil
        let session = AVAudioSession.sharedInstance()
        do {
            try session.setActive(false, options: .notifyOthersOnDeactivation)
            try session.setCategory(previous.category, mode: previous.mode, options: previous.options)
        } catch {
            logger.error("intro audio restore failed: \(error.localizedDescription, privacy: .public)")
        }
    }
}

/// Supported orientations, read by `AppDelegate`. The intro is 9:16, so iPhone is held in
/// portrait while it plays; iPad keeps every orientation (multitasking requires it) and
/// relies on aspect-fill.
@MainActor
enum OrientationLock {
    static var mask: UIInterfaceOrientationMask = .allButUpsideDown {
        didSet {
            guard mask != oldValue else { return }
            for scene in UIApplication.shared.connectedScenes {
                (scene as? UIWindowScene)?.keyWindow?.rootViewController?.setNeedsUpdateOfSupportedInterfaceOrientations()
            }
        }
    }

    static func supported(for idiom: UIUserInterfaceIdiom) -> UIInterfaceOrientationMask {
        idiom == .phone ? mask : .all
    }
}
