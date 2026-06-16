package md.film.tv.ui.catalog

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.tv.material3.Text
import md.film.tv.ui.AppViewModelFactory
import md.film.tv.ui.components.PosterGrid
import md.film.tv.ui.theme.TextPrimary
import md.film.tv.ui.theme.TextSecondary

/** A fixed catalogue pane (Movies / Series). [type] is "movie", "series", etc. */
@Composable
fun CatalogScreen(
    type: String?,
    onOpenDetail: (String) -> Unit,
    viewModelKey: String,
) {
    val viewModel: CatalogViewModel =
        viewModel(key = viewModelKey, factory = AppViewModelFactory.factory)
    val state by viewModel.state.collectAsStateWithLifecycle()

    LaunchedEffect(type) { viewModel.loadByType(type) }

    Box(modifier = Modifier.fillMaxSize()) {
        when (val s = state) {
            CatalogUiState.Loading -> Text(
                "Se încarcă…",
                color = TextSecondary,
                fontSize = 20.sp,
                modifier = Modifier.align(Alignment.Center),
            )
            is CatalogUiState.Error -> Text(
                s.message,
                color = TextPrimary,
                fontSize = 20.sp,
                modifier = Modifier.align(Alignment.Center),
            )
            is CatalogUiState.Ready ->
                if (s.items.isEmpty()) {
                    Text(
                        "Niciun rezultat.",
                        color = TextSecondary,
                        fontSize = 20.sp,
                        modifier = Modifier.align(Alignment.Center),
                    )
                } else {
                    PosterGrid(items = s.items, onOpen = onOpenDetail)
                }
        }
    }
}
