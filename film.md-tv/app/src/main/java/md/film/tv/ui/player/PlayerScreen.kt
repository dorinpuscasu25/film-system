package md.film.tv.ui.player

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import md.film.tv.BuildConfig
import md.film.tv.ui.AppViewModelFactory
import androidx.tv.material3.Button
import androidx.tv.material3.Text
import md.film.tv.ui.theme.TextPrimary

@Composable
fun PlayerScreen(
    slug: String,
    episodeId: String? = null,
    onNeedReauth: () -> Unit,
    viewModel: PlayerViewModel = viewModel(factory = AppViewModelFactory.factory),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()

    LaunchedEffect(slug, episodeId) { viewModel.load(slug, episodeId) }

    LaunchedEffect(state) {
        if (state is PlayerUiState.NeedsReauth) onNeedReauth()
    }

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(Color.Black),
        contentAlignment = Alignment.Center,
    ) {
        when (val s = state) {
            PlayerUiState.Loading -> Text("Se pregătește redarea…", color = TextPrimary, fontSize = 20.sp)

            is PlayerUiState.Ready -> {
                val embed = s.data.playback.embedUrl ?: s.data.playback.url
                if (!embed.isNullOrBlank()) {
                    BunnyPlayerView(
                        data = s.data,
                        onEvent = { pos, dur, type -> viewModel.reportProgress(pos, dur, type) },
                    )
                } else {
                    Text(
                        "Nu există sursă video pentru acest titlu.",
                        color = TextPrimary,
                        fontSize = 18.sp,
                    )
                }
            }

            PlayerUiState.NoAccess -> PurchaseBlockedScreen(
                title = null,
                movieUrl = "${BuildConfig.WEB_BASE_URL}/movie/$slug",
                onRefresh = viewModel::refresh,
            )

            PlayerUiState.NeedsReauth -> Text("Sesiune expirată…", color = TextPrimary, fontSize = 20.sp)

            is PlayerUiState.Error -> Column(
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.spacedBy(16.dp),
                modifier = Modifier.padding(32.dp),
            ) {
                Text(s.message, color = TextPrimary, fontSize = 20.sp)
                Button(onClick = viewModel::refresh) { Text("Reîncearcă") }
            }
        }
    }
}
