package md.film.tv.data.remote.dto

import com.squareup.moshi.Json
import com.squareup.moshi.JsonClass

@JsonClass(generateAdapter = true)
data class PlaybackResponse(
    @Json(name = "content") val content: PlaybackContentDto? = null,
    @Json(name = "episode") val episode: EpisodeDto? = null,
    @Json(name = "playback") val playback: PlaybackStreamDto,
    @Json(name = "subtitles") val subtitles: List<SubtitleDto> = emptyList(),
    @Json(name = "continue_watching") val continueWatching: ContinueWatchingDto? = null,
)

@JsonClass(generateAdapter = true)
data class PlaybackContentDto(
    @Json(name = "id") val id: Long? = null,
    @Json(name = "slug") val slug: String? = null,
    @Json(name = "title") val title: String? = null,
    @Json(name = "poster_url") val posterUrl: String? = null,
    @Json(name = "backdrop_url") val backdropUrl: String? = null,
)

@JsonClass(generateAdapter = true)
data class PlaybackStreamDto(
    @Json(name = "url") val url: String,
    @Json(name = "embed_url") val embedUrl: String? = null,
    @Json(name = "quality") val quality: String? = null,
    @Json(name = "content_format_id") val contentFormatId: Int? = null,
    @Json(name = "drm") val drm: DrmDto? = null,
    @Json(name = "session_token") val sessionToken: String? = null,
)

@JsonClass(generateAdapter = true)
data class DrmDto(
    @Json(name = "policy") val policy: String? = null,
    @Json(name = "servers") val servers: Map<String, String> = emptyMap(),
    @Json(name = "headers") val headers: Map<String, String> = emptyMap(),
    @Json(name = "clear_keys") val clearKeys: Map<String, String> = emptyMap(),
)

@JsonClass(generateAdapter = true)
data class SubtitleDto(
    @Json(name = "id") val id: Int? = null,
    @Json(name = "locale") val locale: String? = null,
    @Json(name = "label") val label: String? = null,
    @Json(name = "url") val url: String? = null,
    @Json(name = "is_default") val isDefault: Boolean = false,
)

@JsonClass(generateAdapter = true)
data class ContinueWatchingDto(
    @Json(name = "position_seconds") val positionSeconds: Double = 0.0,
    @Json(name = "duration_seconds") val durationSeconds: Double = 0.0,
)
