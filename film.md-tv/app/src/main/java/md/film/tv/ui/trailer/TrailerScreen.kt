package md.film.tv.ui.trailer

import android.annotation.SuppressLint
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.viewinterop.AndroidView

/** Extract the 11-char YouTube id from any common YouTube URL form. */
fun youtubeIdFrom(url: String?): String? {
    if (url.isNullOrBlank()) return null
    val patterns = listOf(
        Regex("""v=([A-Za-z0-9_-]{11})"""),
        Regex("""youtu\.be/([A-Za-z0-9_-]{11})"""),
        Regex("""embed/([A-Za-z0-9_-]{11})"""),
        Regex("""shorts/([A-Za-z0-9_-]{11})"""),
    )
    for (p in patterns) p.find(url)?.groupValues?.getOrNull(1)?.let { return it }
    // Bare id fallback.
    return if (url.matches(Regex("""[A-Za-z0-9_-]{11}"""))) url else null
}

/** Full-screen YouTube trailer played through the IFrame embed in a WebView. */
@SuppressLint("SetJavaScriptEnabled")
@Composable
fun TrailerScreen(videoId: String) {
    AndroidView(
        modifier = Modifier.fillMaxSize().background(Color.Black),
        factory = { context ->
            WebView(context).apply {
                webViewClient = WebViewClient()
                settings.javaScriptEnabled = true
                settings.mediaPlaybackRequiresUserGesture = false
                settings.domStorageEnabled = true
                setBackgroundColor(android.graphics.Color.BLACK)
                val html = """
                    <html><body style="margin:0;background:#000">
                    <iframe width="100%" height="100%"
                        src="https://www.youtube.com/embed/$videoId?autoplay=1&playsinline=1&rel=0&modestbranding=1"
                        frameborder="0"
                        allow="autoplay; encrypted-media; fullscreen"
                        allowfullscreen></iframe>
                    </body></html>
                """.trimIndent()
                loadDataWithBaseURL("https://www.youtube.com", html, "text/html", "utf-8", null)
            }
        },
    )
}
