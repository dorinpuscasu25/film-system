import Foundation

/// Brand intro played on cold start (`Features/Intro`).
enum IntroConfiguration {
    /// INTRO_ENABLED — master switch. Set to `false` to ship a build without the intro.
    static let defaultEnabled = true

    /// UserDefaults key that overrides `defaultEnabled` without a rebuild. For a quick test,
    /// pass `-filmoteca.introEnabled NO` as a launch argument (Scheme → Run → Arguments).
    static let enabledOverrideKey = "filmoteca.introEnabled"

    static var isEnabled: Bool {
        let defaults = UserDefaults.standard
        // `bool(forKey:)` also parses the "YES"/"NO" strings that launch arguments produce.
        return defaults.object(forKey: enabledOverrideKey) == nil ? defaultEnabled : defaults.bool(forKey: enabledOverrideKey)
    }

    static let videoName = "filmoteca-intro"
    static let videoExtension = "mp4"
    /// Pixel-identical to the video's last frame, so swapping one for the other is invisible.
    static let finalFrameName = "filmoteca-intro-final-frame"

    /// The player must have its first frame on screen within this, otherwise the static
    /// final frame is shown instead.
    static let firstFrameTimeout: Duration = .milliseconds(1500)
    /// Reduce Motion: only the static final frame, for this long.
    static let reduceMotionHold: Duration = .milliseconds(800)
    /// Still waiting for the first screen after this long → show a discreet spinner.
    static let loadingIndicatorDelay: Duration = .seconds(2)
    static let exitFadeDuration = 0.3
}
