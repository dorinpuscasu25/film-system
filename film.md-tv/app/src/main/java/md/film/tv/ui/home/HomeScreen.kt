package md.film.tv.ui.home

import androidx.compose.animation.Crossfade
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import kotlinx.coroutines.delay
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.tv.material3.Button
import androidx.tv.material3.Text
import md.film.tv.data.remote.dto.ContentCardDto
import md.film.tv.ui.AppViewModelFactory
import md.film.tv.ui.components.ContinueCard
import md.film.tv.ui.components.MovieCard
import coil.compose.AsyncImage
import md.film.tv.ui.theme.Accent
import md.film.tv.ui.theme.Background
import md.film.tv.ui.theme.SurfaceHover
import md.film.tv.ui.theme.TextPrimary
import md.film.tv.ui.theme.TextSecondary

@Composable
fun HomeScreen(
    onOpenDetail: (String) -> Unit,
    onResume: (String) -> Unit,
    viewModel: HomeViewModel = viewModel(factory = AppViewModelFactory.factory),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(Background),
    ) {
        when (val s = state) {
            HomeUiState.Loading -> Text(
                text = "Se încarcă…",
                color = TextSecondary,
                fontSize = 20.sp,
                modifier = Modifier.align(Alignment.Center),
            )

            is HomeUiState.Error -> Column(
                modifier = Modifier.align(Alignment.Center),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.spacedBy(16.dp),
            ) {
                Text(s.message, color = TextPrimary, fontSize = 20.sp)
                Button(onClick = viewModel::load) { Text("Reîncarcă") }
            }

            is HomeUiState.Ready -> HomeContent(s, onOpenDetail, onResume)
        }
    }
}

@Composable
private fun HomeContent(
    state: HomeUiState.Ready,
    onOpenDetail: (String) -> Unit,
    onResume: (String) -> Unit,
) {
    // Auto-rotate the hero through featured titles, Netflix-style.
    var heroIndex by remember(state.heroes) { mutableIntStateOf(0) }
    LaunchedEffect(state.heroes) {
        if (state.heroes.size > 1) {
            while (true) {
                delay(8000)
                heroIndex = (heroIndex + 1) % state.heroes.size
            }
        }
    }

    LazyColumn(
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(vertical = 32.dp),
        verticalArrangement = Arrangement.spacedBy(28.dp),
    ) {
        state.heroes.getOrNull(heroIndex)?.let { hero ->
            item {
                Crossfade(targetState = hero, label = "hero") { current ->
                    HeroHeader(current, onOpenDetail)
                }
            }
        }
        if (state.continueWatching.isNotEmpty()) {
            item {
                Column {
                    Text(
                        text = "Continuă vizionarea",
                        color = TextPrimary,
                        fontSize = 22.sp,
                        fontWeight = FontWeight.SemiBold,
                        modifier = Modifier.padding(start = 48.dp, bottom = 12.dp),
                    )
                    LazyRow(
                        contentPadding = PaddingValues(horizontal = 48.dp),
                        horizontalArrangement = Arrangement.spacedBy(16.dp),
                    ) {
                        items(state.continueWatching) { item ->
                            ContinueCard(item = item, onClick = { onResume(item.contentSlug) })
                        }
                    }
                }
            }
        }
        items(state.rows) { row ->
            ContentRow(row, onOpenDetail)
        }
    }
}

@Composable
private fun HeroHeader(hero: ContentCardDto, onOpenDetail: (String) -> Unit) {
    Box(
        modifier = Modifier
            .fillMaxWidth()
            .height(460.dp),
    ) {
        val backdrop = hero.backdropUrl ?: hero.posterUrl
        if (backdrop != null) {
            AsyncImage(
                model = backdrop,
                contentDescription = hero.title,
                contentScale = ContentScale.Crop,
                modifier = Modifier.fillMaxSize(),
            )
        }
        // Bottom + left cinematic scrims so the copy stays legible.
        Box(
            modifier = Modifier.matchParentSize().background(
                Brush.verticalGradient(0.35f to Color.Transparent, 1f to Background),
            ),
        )
        Box(
            modifier = Modifier.matchParentSize().background(
                Brush.horizontalGradient(0f to Background.copy(alpha = 0.9f), 0.7f to Color.Transparent),
            ),
        )

        Column(
            modifier = Modifier
                .align(Alignment.BottomStart)
                .padding(start = 48.dp, bottom = 28.dp, end = 48.dp)
                .width(720.dp),
        ) {
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                hero.typeLabel?.let { HeroTag(it.uppercase(), Accent) }
                hero.genres.take(2).forEach { HeroTag(it, SurfaceHover) }
            }
            Spacer(Modifier.height(12.dp))
            Text(
                text = hero.title ?: "",
                color = TextPrimary,
                fontSize = 50.sp,
                fontWeight = FontWeight.Black,
                maxLines = 2,
            )
            hero.shortDescription?.takeIf { it.isNotBlank() }?.let {
                Spacer(Modifier.height(10.dp))
                Text(text = it, color = TextSecondary, fontSize = 17.sp, maxLines = 2)
            }
            Spacer(Modifier.height(18.dp))
            Button(onClick = { onOpenDetail(hero.slug) }) {
                Text("Vezi detalii", fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

@Composable
private fun HeroTag(text: String, container: Color) {
    Box(
        modifier = Modifier
            .background(container.copy(alpha = 0.85f), RoundedCornerShape(6.dp))
            .padding(horizontal = 10.dp, vertical = 4.dp),
    ) {
        Text(text = text, color = TextPrimary, fontSize = 12.sp, fontWeight = FontWeight.Bold)
    }
}

@Composable
private fun ContentRow(row: HomeRow, onOpenDetail: (String) -> Unit) {
    Column {
        Text(
            text = row.title,
            color = TextPrimary,
            fontSize = 22.sp,
            fontWeight = FontWeight.SemiBold,
            modifier = Modifier.padding(start = 48.dp, bottom = 12.dp),
        )
        LazyRow(
            contentPadding = PaddingValues(horizontal = 48.dp),
            horizontalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            items(row.items) { card ->
                MovieCard(card = card, onClick = { onOpenDetail(card.slug) })
            }
        }
    }
}
