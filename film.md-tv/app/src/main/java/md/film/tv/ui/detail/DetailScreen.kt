package md.film.tv.ui.detail

import androidx.compose.foundation.background
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.tv.material3.Button
import androidx.tv.material3.Text
import coil.compose.AsyncImage
import md.film.tv.data.remote.dto.ContentDetailDto
import md.film.tv.ui.AppViewModelFactory
import md.film.tv.ui.trailer.youtubeIdFrom
import md.film.tv.ui.theme.Accent
import md.film.tv.ui.theme.Background
import md.film.tv.ui.theme.TextPrimary
import md.film.tv.ui.theme.TextSecondary

@Composable
fun DetailScreen(
    slug: String,
    onPlay: (String, String?) -> Unit,
    onTrailer: (String) -> Unit,
    viewModel: DetailViewModel = viewModel(factory = AppViewModelFactory.factory),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()

    LaunchedEffect(slug) { viewModel.load(slug) }

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(Background),
    ) {
        when (val s = state) {
            DetailUiState.Loading -> Text(
                "Se încarcă…",
                color = TextSecondary,
                fontSize = 20.sp,
                modifier = Modifier.align(Alignment.Center),
            )

            is DetailUiState.Error -> Text(
                s.message,
                color = TextPrimary,
                fontSize = 20.sp,
                modifier = Modifier.align(Alignment.Center),
            )

            is DetailUiState.Ready -> DetailContent(s, onPlay, onTrailer)
        }
    }
}

@Composable
private fun DetailContent(
    state: DetailUiState.Ready,
    onPlay: (String, String?) -> Unit,
    onTrailer: (String) -> Unit,
) {
    val content = state.content
    Box(modifier = Modifier.fillMaxSize()) {
        val backdrop = content.heroDesktopUrl ?: content.backdropUrl ?: content.posterUrl
        if (backdrop != null) {
            AsyncImage(
                model = backdrop,
                contentDescription = null,
                contentScale = ContentScale.Crop,
                modifier = Modifier.fillMaxSize(),
            )
        }
        // Left-to-right + bottom scrim so text stays readable over any artwork.
        Box(
            modifier = Modifier
                .fillMaxSize()
                .background(
                    Brush.horizontalGradient(
                        0f to Background,
                        0.55f to Background.copy(alpha = 0.85f),
                        1f to Background.copy(alpha = 0.1f),
                    ),
                ),
        )

        Column(
            modifier = Modifier
                .fillMaxHeight()
                .width(820.dp)
                .verticalScroll(rememberScrollState())
                .padding(start = 56.dp, top = 64.dp, bottom = 56.dp, end = 32.dp),
            verticalArrangement = Arrangement.Center,
        ) {
            content.typeLabel?.let {
                Text(it.uppercase(), color = Accent, fontSize = 15.sp, fontWeight = FontWeight.Bold)
                Spacer(Modifier.height(8.dp))
            }
            Text(
                text = content.title ?: content.originalTitle ?: "",
                color = TextPrimary,
                fontSize = 46.sp,
                fontWeight = FontWeight.Bold,
            )
            Spacer(Modifier.height(12.dp))
            Text(
                text = metaLine(content),
                color = TextSecondary,
                fontSize = 16.sp,
            )
            Spacer(Modifier.height(20.dp))
            (content.description ?: content.shortDescription)?.let {
                Text(text = it, color = TextPrimary, fontSize = 18.sp, maxLines = 5)
                Spacer(Modifier.height(28.dp))
            }
            val firstEpisode = content.seasons.firstOrNull()?.episodes?.firstOrNull()
            val trailerId = youtubeIdFrom(content.trailerUrl)
            Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                Button(onClick = { onPlay(content.slug, firstEpisode?.id) }) {
                    Text(
                        text = when {
                            firstEpisode != null -> "Vizionează episodul 1"
                            content.isFree -> "Vizionează gratuit"
                            else -> "Vizionează"
                        },
                        fontWeight = FontWeight.SemiBold,
                    )
                }
                if (trailerId != null) {
                    Button(onClick = { onTrailer(trailerId) }) {
                        Text("Trailer", fontWeight = FontWeight.SemiBold)
                    }
                }
            }
            if (!content.isFree && (content.lowestPrice ?: 0.0) > 0.0) {
                Spacer(Modifier.height(14.dp))
                Text(
                    text = "De la ${content.lowestPrice} ${content.currency ?: "MDL"} · " +
                        "achiziția se face pe telefon sau computer",
                    color = TextSecondary,
                    fontSize = 14.sp,
                )
            }

            if (content.seasons.isNotEmpty()) {
                Spacer(Modifier.height(32.dp))
                EpisodesSection(content, onPlay)
            }

            if (state.reviews.isNotEmpty()) {
                Spacer(Modifier.height(32.dp))
                ReviewsSection(state)
            }
        }
    }
}

@Composable
private fun ReviewsSection(state: DetailUiState.Ready) {
    Column(verticalArrangement = Arrangement.spacedBy(16.dp)) {
        val summary = state.reviewSummary
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Text("Recenzii", color = TextPrimary, fontSize = 22.sp, fontWeight = FontWeight.SemiBold)
            if (summary != null && summary.count > 0) {
                Text(
                    text = "★ ${summary.averageRating}  ·  ${summary.count} recenzii",
                    color = Accent,
                    fontSize = 16.sp,
                    fontWeight = FontWeight.Medium,
                )
            }
        }
        state.reviews.take(6).forEach { review ->
            Column(
                modifier = Modifier
                    .clip(RoundedCornerShape(12.dp))
                    .background(Background.copy(alpha = 0.6f))
                    .padding(16.dp),
            ) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(
                        text = review.userName ?: "Anonim",
                        color = TextPrimary,
                        fontSize = 16.sp,
                        fontWeight = FontWeight.SemiBold,
                    )
                    Text(text = "★".repeat(review.rating.coerceIn(0, 5)), color = Accent, fontSize = 16.sp)
                }
                review.comment?.takeIf { it.isNotBlank() }?.let {
                    Spacer(Modifier.height(4.dp))
                    Text(text = it, color = TextSecondary, fontSize = 15.sp, maxLines = 4)
                }
            }
        }
    }
}

@Composable
private fun EpisodesSection(content: ContentDetailDto, onPlay: (String, String?) -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(24.dp)) {
        content.seasons.forEach { season ->
            Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text(
                    text = season.title?.takeIf { it.isNotBlank() } ?: "Sezonul ${season.seasonNumber}",
                    color = TextPrimary,
                    fontSize = 22.sp,
                    fontWeight = FontWeight.SemiBold,
                )
                season.episodes.forEach { episode ->
                    EpisodeRow(
                        number = episode.episodeNumber,
                        title = episode.title,
                        description = episode.description,
                        onClick = { onPlay(content.slug, episode.id) },
                    )
                }
            }
        }
    }
}

@Composable
private fun EpisodeRow(
    number: Int,
    title: String?,
    description: String?,
    onClick: () -> Unit,
) {
    androidx.tv.material3.Card(
        onClick = onClick,
        modifier = Modifier.fillMaxWidth(),
    ) {
        Row(
            modifier = Modifier.padding(16.dp),
            horizontalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            Text(
                text = number.toString().padStart(2, '0'),
                color = Accent,
                fontSize = 20.sp,
                fontWeight = FontWeight.Bold,
            )
            Column {
                Text(
                    text = title?.takeIf { it.isNotBlank() } ?: "Episodul $number",
                    color = TextPrimary,
                    fontSize = 17.sp,
                    fontWeight = FontWeight.Medium,
                )
                description?.takeIf { it.isNotBlank() }?.let {
                    Spacer(Modifier.height(4.dp))
                    Text(text = it, color = TextSecondary, fontSize = 14.sp, maxLines = 2)
                }
            }
        }
    }
}

private fun metaLine(content: ContentDetailDto): String {
    val parts = buildList {
        content.releaseYear?.let { add(it.toString()) }
        content.runtimeMinutes?.let { add("$it min") }
        content.ageRating?.let { add(it) }
        content.imdbRating?.let { add("IMDb $it") }
        if (content.genres.isNotEmpty()) add(content.genres.take(3).joinToString(", "))
    }
    return parts.joinToString("  ·  ")
}
