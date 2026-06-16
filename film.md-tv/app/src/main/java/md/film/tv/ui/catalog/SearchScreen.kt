package md.film.tv.ui.catalog

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.compose.foundation.text.KeyboardOptions
import androidx.tv.material3.Text
import md.film.tv.ui.AppViewModelFactory
import md.film.tv.ui.components.PosterGrid
import md.film.tv.ui.theme.Accent
import md.film.tv.ui.theme.Border
import md.film.tv.ui.theme.Surface
import md.film.tv.ui.theme.TextPrimary
import md.film.tv.ui.theme.TextSecondary

@Composable
fun SearchScreen(
    onOpenDetail: (String) -> Unit,
    viewModel: CatalogViewModel = viewModel(key = "search", factory = AppViewModelFactory.factory),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    var query by remember { mutableStateOf("") }

    Column(modifier = Modifier.fillMaxSize().padding(horizontal = 48.dp, vertical = 32.dp)) {
        Text("Caută", color = TextPrimary, fontSize = 28.sp)
        Spacer(Modifier.height(16.dp))

        Box(
            modifier = Modifier
                .fillMaxWidth()
                .height(56.dp)
                .clip(RoundedCornerShape(12.dp))
                .background(Surface)
                .border(1.dp, Border, RoundedCornerShape(12.dp))
                .padding(horizontal = 20.dp),
            contentAlignment = Alignment.CenterStart,
        ) {
            BasicTextField(
                value = query,
                onValueChange = {
                    query = it
                    viewModel.search(it)
                },
                singleLine = true,
                textStyle = TextStyle(color = TextPrimary, fontSize = 20.sp),
                cursorBrush = androidx.compose.ui.graphics.SolidColor(Accent),
                keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
                modifier = Modifier.fillMaxWidth(),
                decorationBox = { inner ->
                    if (query.isEmpty()) {
                        Text("Titlu, gen, an…", color = TextSecondary, fontSize = 20.sp)
                    }
                    inner()
                },
            )
        }

        Spacer(Modifier.height(8.dp))

        Box(modifier = Modifier.fillMaxSize()) {
            when (val s = state) {
                CatalogUiState.Loading ->
                    if (query.isNotBlank()) {
                        Text(
                            "Se caută…",
                            color = TextSecondary,
                            fontSize = 18.sp,
                            modifier = Modifier.align(Alignment.Center),
                        )
                    }
                is CatalogUiState.Error -> Text(
                    s.message,
                    color = TextPrimary,
                    fontSize = 18.sp,
                    modifier = Modifier.align(Alignment.Center),
                )
                is CatalogUiState.Ready ->
                    if (s.items.isEmpty() && query.isNotBlank()) {
                        Text(
                            "Niciun rezultat pentru „$query”.",
                            color = TextSecondary,
                            fontSize = 18.sp,
                            modifier = Modifier.align(Alignment.Center),
                        )
                    } else {
                        PosterGrid(
                            items = s.items,
                            onOpen = onOpenDetail,
                            modifier = Modifier.fillMaxSize(),
                            columns = 6,
                        )
                    }
            }
        }
    }
}
