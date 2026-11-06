import AVKit
import SwiftUI

/// Plays one linear ad over the film and reports VAST events.
///
/// The content player stays alive underneath and paused, so resuming does not
/// re-buffer or re-acquire a FairPlay licence. Any failure — bad media URL,
/// decode error, stalled network — resolves the break immediately rather than
/// trapping the viewer in front of an ad that will not play.
struct AdBreakOverlayView: View {
    let adBreak: AdBreak
    let ads: AdService
    let onFinished: () -> Void
    /// Leaves the player entirely; the overlay covers the player's own close button.
    var onClose: (() -> Void)? = nil

    @Environment(FilmotecaModel.self) private var app

    @State private var player: AVPlayer?
    @State private var remaining: Int = 0
    @State private var skipIn: Int?
    @State private var isMuted = false
    @State private var timeObserver: Any?
    @State private var endObserver: NSObjectProtocol?
    @State private var firedQuartiles: Set<String> = []
    @State private var hasFinished = false
    @State private var hasStarted = false
    @State private var progress: Double = 0
    @State private var statusObserver: NSKeyValueObservation?
    @State private var failureObserver: NSObjectProtocol?
    @State private var watchdog: Task<Void, Never>?

    /// A creative that has not started rendering by then is treated as broken.
    private static let startTimeout: Duration = .seconds(8)

    private var creative: AdCreative { adBreak.creative }

    var body: some View {
        ZStack {
            Color.black.ignoresSafeArea()

            if let player {
                VideoPlayer(player: player)
                    .disabled(true)
                    .ignoresSafeArea()
            }

            if !hasStarted {
                ProgressView().tint(.white)
            }

            VStack {
                HStack(spacing: 10) {
                    if let onClose {
                        Button {
                            finish(event: "close", notifyParent: false)
                            onClose()
                        } label: {
                            Image(systemName: "xmark")
                                .font(.footnote.weight(.bold))
                                .frame(width: 36, height: 36)
                                .background(.black.opacity(0.7), in: Circle())
                        }
                        .accessibilityLabel(app.t("close"))
                    }

                    Text(remaining > 0 ? "\(app.t("ad_label")) · \(remaining)s" : app.t("ad_label"))
                        .font(.caption.weight(.semibold))
                        .padding(.horizontal, 10)
                        .padding(.vertical, 6)
                        .background(.black.opacity(0.7), in: Capsule())

                    Spacer()

                    Button {
                        toggleMute()
                    } label: {
                        Image(systemName: isMuted ? "speaker.slash.fill" : "speaker.wave.2.fill")
                            .font(.footnote)
                            .frame(width: 36, height: 36)
                            .background(.black.opacity(0.7), in: Circle())
                    }
                    .accessibilityLabel(isMuted ? app.t("ad_unmute") : app.t("ad_mute"))
                }
                .padding(.horizontal, 16)
                .padding(.top, 10)

                Spacer()

                HStack {
                    if creative.clickThroughURL != nil {
                        Button { openClickThrough() } label: {
                            Text(app.t("ad_learn_more"))
                                .font(.subheadline.weight(.bold))
                                .padding(.horizontal, 16)
                                .padding(.vertical, 10)
                                .background(FilmotecaTheme.accent, in: Capsule())
                        }
                    }

                    Spacer()

                    if creative.skipOffsetSeconds != nil {
                        Button { finish(event: "skip") } label: {
                            Label {
                                Text(canSkip ? app.t("ad_skip") : "\(app.t("ad_skip_in")) \(skipIn ?? 0)s")
                            } icon: {
                                if canSkip { Image(systemName: "forward.end.fill") }
                            }
                            .labelStyle(.titleAndIcon)
                            .font(.subheadline.weight(.semibold))
                            .padding(.horizontal, 16)
                            .padding(.vertical, 10)
                            .background(.black.opacity(0.75), in: Capsule())
                            .overlay(Capsule().stroke(.white.opacity(canSkip ? 0.5 : 0.15)))
                        }
                        .disabled(!canSkip)
                        .opacity(canSkip ? 1 : 0.7)
                        .animation(.easeOut(duration: 0.2), value: canSkip)
                    }
                }
                .padding(.horizontal, 16)
                .padding(.bottom, 14)

                GeometryReader { proxy in
                    ZStack(alignment: .leading) {
                        Rectangle().fill(.white.opacity(0.25))
                        Rectangle()
                            .fill(FilmotecaTheme.accent)
                            .frame(width: proxy.size.width * min(1, max(0, progress)))
                            .animation(.linear(duration: 0.5), value: progress)
                    }
                }
                .frame(height: 3)
                .accessibilityHidden(true)
            }
            .foregroundStyle(.white)
        }
        .onAppear(perform: start)
        .onDisappear(perform: teardown)
    }

    private var canSkip: Bool {
        creative.skipOffsetSeconds != nil && (skipIn ?? 1) <= 0
    }

    private func start() {
        // The embedded / web-fallback paths never configure the session, and the
        // default category obeys the silent switch — the ad would play mute.
        try? AVAudioSession.sharedInstance().setCategory(.playback, mode: .moviePlayback)
        try? AVAudioSession.sharedInstance().setActive(true)

        let item = AVPlayerItem(url: creative.mediaURL)
        let newPlayer = AVPlayer(playerItem: item)
        newPlayer.actionAtItemEnd = .pause
        player = newPlayer

        remaining = Int(creative.durationSeconds.rounded())
        skipIn = creative.skipOffsetSeconds.map { Int($0.rounded()) }

        timeObserver = newPlayer.addPeriodicTimeObserver(
            forInterval: CMTime(seconds: 0.5, preferredTimescale: 600),
            queue: .main
        ) { time in
            updateProgress(at: time.seconds, player: newPlayer)
        }

        endObserver = NotificationCenter.default.addObserver(
            forName: .AVPlayerItemDidPlayToEndTime,
            object: item,
            queue: .main
        ) { _ in
            finish(event: "complete")
        }

        // A broken media URL, an unsupported codec or a 403 from the CDN used
        // to leave the viewer on a black screen with no way out.
        failureObserver = NotificationCenter.default.addObserver(
            forName: .AVPlayerItemFailedToPlayToEndTime,
            object: item,
            queue: .main
        ) { _ in
            finish(event: nil)
        }
        statusObserver = item.observe(\.status, options: [.new]) { item, _ in
            guard item.status == .failed else { return }
            Task { @MainActor in finish(event: nil) }
        }
        watchdog = Task { @MainActor in
            try? await Task.sleep(for: Self.startTimeout)
            guard !Task.isCancelled, !hasStarted else { return }
            finish(event: nil)
        }

        newPlayer.play()
    }

    private func updateProgress(at seconds: Double, player: AVPlayer) {
        let itemDuration = player.currentItem?.duration.seconds ?? 0
        let duration = itemDuration.isFinite && itemDuration > 0 ? itemDuration : creative.durationSeconds
        guard duration > 0 else { return }

        // The impression counts only once the creative actually renders — not
        // when a broken file is merely requested.
        if !hasStarted, seconds > 0, player.timeControlStatus == .playing {
            hasStarted = true
            watchdog?.cancel()
            ads.fireTracking(creative, event: "impression")
        }

        remaining = max(0, Int((duration - seconds).rounded()))
        progress = seconds / duration
        if let skipOffset = creative.skipOffsetSeconds {
            skipIn = max(0, Int((skipOffset - seconds).rounded()))
        }

        // Quartile beacons must fire exactly once per playback.
        guard hasStarted else { return }
        for (event, threshold) in [("start", 0.0), ("firstQuartile", 0.25), ("midpoint", 0.5), ("thirdQuartile", 0.75)] {
            if progress >= threshold, !firedQuartiles.contains(event) {
                firedQuartiles.insert(event)
                ads.fireTracking(creative, event: event)
            }
        }

        // A creative that stalls past its own length should not hold the film.
        if player.timeControlStatus != .playing, seconds >= duration {
            finish(event: "complete")
        }
    }

    private func toggleMute() {
        guard let player else { return }
        player.isMuted.toggle()
        isMuted = player.isMuted
        ads.fireTracking(creative, event: player.isMuted ? "mute" : "unmute")
    }

    private func openClickThrough() {
        guard let url = creative.clickThroughURL else { return }
        ads.fireTracking(creative, event: "click")
        UIApplication.shared.open(url)
    }

    /// Guarded so the parent is told exactly once per break.
    private func finish(event: String?, notifyParent: Bool = true) {
        guard !hasFinished else { return }
        hasFinished = true
        if let event { ads.fireTracking(creative, event: event) }
        teardown()
        if notifyParent { onFinished() }
    }

    private func teardown() {
        if let timeObserver, let player { player.removeTimeObserver(timeObserver) }
        timeObserver = nil
        if let endObserver { NotificationCenter.default.removeObserver(endObserver) }
        endObserver = nil
        if let failureObserver { NotificationCenter.default.removeObserver(failureObserver) }
        failureObserver = nil
        statusObserver?.invalidate()
        statusObserver = nil
        watchdog?.cancel()
        watchdog = nil
        player?.pause()
        player = nil
    }
}
