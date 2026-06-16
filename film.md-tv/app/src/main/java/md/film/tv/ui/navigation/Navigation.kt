package md.film.tv.ui.navigation

import android.net.Uri

object Routes {
    const val SPLASH = "splash"
    const val PAIRING = "pairing"
    const val PROFILES = "profiles"
    const val BROWSE = "browse"
    const val DETAIL = "detail/{slug}"
    const val PLAYER = "player/{slug}?episodeId={episodeId}"
    const val TRAILER = "trailer/{videoId}"

    fun detail(slug: String) = "detail/${Uri.encode(slug)}"

    fun trailer(videoId: String) = "trailer/${Uri.encode(videoId)}"

    fun player(slug: String, episodeId: String? = null): String {
        val base = "player/${Uri.encode(slug)}"
        return if (episodeId != null) "$base?episodeId=${Uri.encode(episodeId)}" else base
    }
}
