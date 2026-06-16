package md.film.tv.ui.components

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import md.film.tv.data.remote.dto.ContentCardDto

/** A focusable poster grid for catalogue / search / library panes. */
@Composable
fun PosterGrid(
    items: List<ContentCardDto>,
    onOpen: (String) -> Unit,
    modifier: Modifier = Modifier,
    columns: Int = 6,
) {
    LazyVerticalGrid(
        columns = GridCells.Fixed(columns),
        modifier = modifier.fillMaxSize(),
        contentPadding = PaddingValues(horizontal = 48.dp, vertical = 32.dp),
        horizontalArrangement = Arrangement.spacedBy(16.dp),
        verticalArrangement = Arrangement.spacedBy(20.dp),
    ) {
        items(items, key = { it.id }) { card ->
            MovieCard(card = card, onClick = { onOpen(card.slug) }, fillWidth = true)
        }
    }
}
