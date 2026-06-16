package md.film.tv.ui.player

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONObject
import java.util.concurrent.TimeUnit

/** A Bunny Stream reference: numeric library id + video GUID. */
data class BunnyIds(val libraryId: Long, val videoId: String)

/**
 * Resolves Bunny playback for native ExoPlayer.
 *
 * Bunny content is Widevine SAMPLE-AES (HLS), referrer-gated but L3-allowed.
 * The playlist URL lives behind Bunny's `/play` endpoint, which returns it for
 * any request carrying an allowed Referer (no API key/token needed). The
 * Widevine licence endpoint is `WidevineLicense/{lib}/{video}` (probed: a real
 * challenge returns 200, a junk one 400 — i.e. the endpoint exists).
 */
object Bunny {

    private const val STREAM_API = "https://video.bunnycdn.com"

    private val client = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(20, TimeUnit.SECONDS)
        .build()

    fun widevineLicenseUrl(ids: BunnyIds): String =
        "$STREAM_API/WidevineLicense/${ids.libraryId}/${ids.videoId}"

    /** Parse `.../embed/{lib}/{video}` (or a trailing `{lib}/{video}`) into ids. */
    fun parseIds(rawUrl: String?): BunnyIds? {
        if (rawUrl.isNullOrBlank()) return null
        val segments = rawUrl.substringBefore('?').trimEnd('/').split('/').filter { it.isNotBlank() }
        val embedIdx = segments.indexOf("embed")
        val (libRaw, videoRaw) = when {
            embedIdx >= 0 && segments.size >= embedIdx + 3 ->
                segments[embedIdx + 1] to segments[embedIdx + 2]
            segments.size >= 2 ->
                segments[segments.size - 2] to segments[segments.size - 1]
            else -> return null
        }
        val lib = libRaw.toLongOrNull() ?: return null
        if (videoRaw.isBlank()) return null
        return BunnyIds(lib, videoRaw)
    }

    /** Fetch the HLS playlist URL from Bunny's /play endpoint, with a Referer. */
    suspend fun playlistUrl(ids: BunnyIds, referer: String): String? = withContext(Dispatchers.IO) {
        runCatching {
            val request = Request.Builder()
                .url("$STREAM_API/library/${ids.libraryId}/videos/${ids.videoId}/play")
                .header("Referer", referer)
                .header("Accept", "application/json")
                .build()
            client.newCall(request).execute().use { response ->
                if (!response.isSuccessful) return@use null
                val body = response.body?.string() ?: return@use null
                JSONObject(body).optString("videoPlaylistUrl").ifBlank { null }
            }
        }.getOrNull()
    }
}
