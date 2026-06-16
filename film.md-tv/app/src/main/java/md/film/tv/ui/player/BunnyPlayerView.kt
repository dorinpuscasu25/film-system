package md.film.tv.ui.player

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.produceState
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.media3.common.C
import androidx.media3.common.MediaItem
import androidx.media3.common.MimeTypes
import androidx.media3.common.Player
import androidx.media3.common.util.UnstableApi
import androidx.media3.datasource.DefaultHttpDataSource
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.exoplayer.drm.DefaultDrmSessionManager
import androidx.media3.exoplayer.drm.ExoMediaDrm
import androidx.media3.exoplayer.drm.FrameworkMediaDrm
import androidx.media3.exoplayer.drm.HttpMediaDrmCallback
import androidx.media3.exoplayer.hls.HlsMediaSource
import androidx.media3.ui.PlayerView
import androidx.tv.material3.Text
import md.film.tv.BuildConfig
import md.film.tv.data.remote.dto.PlaybackResponse
import md.film.tv.ui.theme.TextPrimary
import md.film.tv.ui.theme.TextSecondary

private sealed interface PlaylistState {
    data object Loading : PlaylistState
    data object Error : PlaylistState
    data class Ready(val url: String) : PlaylistState
}

/**
 * Native player for Bunny's Widevine HLS content. We resolve the playlist URL
 * from Bunny's /play endpoint (Referer-authorized) and let ExoPlayer handle the
 * Widevine licence — sending the same Referer on both media and licence
 * requests so Bunny authorizes them. Works at Widevine L3, so no special
 * hardware is required.
 *
 * [onEvent] = (positionSeconds, durationSeconds, eventType).
 */
@Composable
fun BunnyPlayerView(
    data: PlaybackResponse,
    onEvent: (Int, Int, String) -> Unit,
    modifier: Modifier = Modifier,
) {
    val ids = remember(data) { Bunny.parseIds(data.playback.embedUrl ?: data.playback.url) }
    val referer = remember {
        BuildConfig.WEB_BASE_URL.let { if (it.endsWith("/")) it else "$it/" }
    }

    val state by produceState<PlaylistState>(PlaylistState.Loading, ids) {
        value = if (ids == null) {
            PlaylistState.Error
        } else {
            Bunny.playlistUrl(ids, referer)?.let { PlaylistState.Ready(it) } ?: PlaylistState.Error
        }
    }

    Box(modifier = modifier.fillMaxSize().background(Color.Black), contentAlignment = Alignment.Center) {
        when (val s = state) {
            PlaylistState.Loading -> Text("Se pregătește redarea…", color = TextSecondary, fontSize = 20.sp)
            PlaylistState.Error -> Text("Nu am putut porni redarea.", color = TextPrimary, fontSize = 18.sp)
            is PlaylistState.Ready -> ExoSurface(
                playlistUrl = s.url,
                licenseUrl = Bunny.widevineLicenseUrl(ids!!),
                referer = referer,
                onEvent = onEvent,
            )
        }
    }
}

@OptIn(UnstableApi::class)
@Composable
private fun ExoSurface(
    playlistUrl: String,
    licenseUrl: String,
    referer: String,
    onEvent: (Int, Int, String) -> Unit,
) {
    val context = LocalContext.current
    var secLevel by remember { mutableStateOf("?") }

    val player = remember(playlistUrl) {
        // Referer on every media/manifest/segment request.
        val httpFactory = DefaultHttpDataSource.Factory()
            .setDefaultRequestProperties(mapOf("Referer" to referer))

        // Widevine licence callback, with Referer on the licence POST.
        val drmCallback = HttpMediaDrmCallback(licenseUrl, httpFactory).apply {
            setKeyRequestProperty("Referer", referer)
        }

        // Try to force Widevine **L3** (software) — the content allows it. Some
        // L1-locked TVs refuse the downgrade; we record the effective level so we
        // can tell whether the secure path is the cause of a black screen.
        val drmSessionManager = DefaultDrmSessionManager.Builder()
            .setUuidAndExoMediaDrmProvider(C.WIDEVINE_UUID) { uuid ->
                FrameworkMediaDrm.newInstance(uuid).apply {
                    runCatching { setPropertyString("securityLevel", "L3") }
                    secLevel = runCatching { getPropertyString("securityLevel") }.getOrDefault("?")
                }
            }
            .setMultiSession(true)
            .build(drmCallback)

        val mediaSourceFactory = HlsMediaSource.Factory(httpFactory)
            .setDrmSessionManagerProvider { drmSessionManager }

        ExoPlayer.Builder(context)
            .setMediaSourceFactory(mediaSourceFactory)
            .build().apply {
                // Cap at 1080p: the 4K rendition is H.264 (avc1) which many TV
                // decoders can't decode at 2160p — that shows as black video with
                // working audio. 1080p is plenty for a 10-foot screen anyway.
                trackSelectionParameters = trackSelectionParameters.buildUpon()
                    .setMaxVideoSize(1920, 1080)
                    .build()
                setMediaItem(
                    MediaItem.Builder()
                        .setUri(playlistUrl)
                        .setMimeType(MimeTypes.APPLICATION_M3U8)
                        .build(),
                )
                prepare()
                playWhenReady = true
            }
    }

    // On-screen diagnostics (no adb needed to read them on the TV).
    var stateName by remember { mutableStateOf("…") }
    var videoDim by remember { mutableStateOf("0x0") }
    var tracksInfo by remember { mutableStateOf("?") }
    var errorText by remember { mutableStateOf<String?>(null) }
    var renderedFirstFrame by remember { mutableStateOf(false) }

    DisposableEffect(player) {
        onEvent(0, 0, "play")
        val listener = object : Player.Listener {
            override fun onPlaybackStateChanged(state: Int) {
                stateName = when (state) {
                    Player.STATE_IDLE -> "IDLE"
                    Player.STATE_BUFFERING -> "BUFFERING"
                    Player.STATE_READY -> "READY"
                    Player.STATE_ENDED -> "ENDED"
                    else -> "?"
                }
                if (state == Player.STATE_ENDED) onEvent(0, 0, "complete")
            }

            override fun onVideoSizeChanged(videoSize: androidx.media3.common.VideoSize) {
                videoDim = "${videoSize.width}x${videoSize.height}"
            }

            override fun onRenderedFirstFrame() {
                renderedFirstFrame = true
            }

            override fun onTracksChanged(tracks: androidx.media3.common.Tracks) {
                val v = tracks.groups.count { it.type == C.TRACK_TYPE_VIDEO && it.isSelected }
                val a = tracks.groups.count { it.type == C.TRACK_TYPE_AUDIO && it.isSelected }
                tracksInfo = "video=$v audio=$a"
            }

            override fun onPlayerError(error: androidx.media3.common.PlaybackException) {
                errorText = "${error.errorCodeName}: ${error.message}"
            }
        }
        player.addListener(listener)
        onDispose {
            val pos = (player.currentPosition / 1000).toInt().coerceAtLeast(0)
            onEvent(pos, 0, "stop")
            player.removeListener(listener)
            player.release()
        }
    }

    Box(modifier = Modifier.fillMaxSize()) {
        AndroidView(
            modifier = Modifier.fillMaxSize(),
            factory = { ctx ->
                // Inflate the TextureView-backed PlayerView (see the layout's note).
                (android.view.LayoutInflater.from(ctx)
                    .inflate(md.film.tv.R.layout.bunny_player_view, null) as PlayerView)
                    .apply {
                        this.player = player
                        setShowNextButton(false)
                        setShowPreviousButton(false)
                    }
            },
        )

        // Diagnostic overlay — screenshot this on the TV and send it over.
        Text(
            text = "DIAG  secLevel=$secLevel  state=$stateName  video=$videoDim  " +
                "tracks=$tracksInfo  firstFrame=$renderedFirstFrame" +
                (errorText?.let { "\nERR $it" } ?: ""),
            color = Color.Yellow,
            fontSize = 14.sp,
            modifier = Modifier
                .align(Alignment.TopStart)
                .background(Color(0xCC000000))
                .padding(10.dp),
        )
    }
}
