package md.film.tv.ui.library

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import md.film.tv.BuildConfig
import androidx.tv.material3.Button
import androidx.tv.material3.Text
import md.film.tv.ui.AppViewModelFactory
import md.film.tv.ui.components.PosterGrid
import md.film.tv.ui.components.QrCode
import md.film.tv.ui.theme.AccentGreen
import md.film.tv.ui.theme.Surface
import md.film.tv.ui.theme.TextPrimary
import md.film.tv.ui.theme.TextSecondary

@Composable
fun LibraryScreen(
    onOpenDetail: (String) -> Unit,
    onSwitchProfile: () -> Unit,
    onSignOut: () -> Unit,
    viewModel: LibraryViewModel = viewModel(key = "library", factory = AppViewModelFactory.factory),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()

    LaunchedEffect(Unit) { viewModel.load() }

    Box(modifier = Modifier.fillMaxSize()) {
        when (val s = state) {
            LibraryUiState.Loading -> Text(
                "Se încarcă…",
                color = TextSecondary,
                fontSize = 20.sp,
                modifier = Modifier.align(Alignment.Center),
            )
            is LibraryUiState.Error -> Column(
                modifier = Modifier.align(Alignment.Center),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.spacedBy(16.dp),
            ) {
                Text(s.message, color = TextPrimary, fontSize = 20.sp)
                Button(onClick = viewModel::load) { Text("Reîncearcă") }
            }
            is LibraryUiState.Ready -> Column(modifier = Modifier.fillMaxSize()) {
                Header(s, onSwitchProfile, onSignOut)
                if (s.items.isEmpty()) {
                    Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                        Text("Încă nu ai titluri cumpărate.", color = TextSecondary, fontSize = 18.sp)
                    }
                } else {
                    PosterGrid(items = s.items, onOpen = onOpenDetail)
                }
            }
        }
    }
}

@Composable
private fun Header(
    state: LibraryUiState.Ready,
    onSwitchProfile: () -> Unit,
    onSignOut: () -> Unit,
) {
    Row(
        modifier = Modifier.fillMaxWidth().padding(horizontal = 48.dp, vertical = 24.dp),
        horizontalArrangement = Arrangement.spacedBy(32.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(modifier = Modifier.weight(1f)) {
            Text(
                text = state.userName?.let { "Biblioteca lui $it" } ?: "Biblioteca mea",
                color = TextPrimary,
                fontSize = 28.sp,
                fontWeight = FontWeight.Bold,
            )
            if (state.walletBalance != null) {
                Spacer(Modifier.height(6.dp))
                Text(
                    text = "Sold portofel: ${"%.2f".format(state.walletBalance)} ${state.walletCurrency ?: "MDL"}",
                    color = AccentGreen,
                    fontSize = 16.sp,
                    fontWeight = FontWeight.SemiBold,
                )
            }
            Spacer(Modifier.height(14.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                Button(onClick = onSwitchProfile) { Text("Schimbă profilul") }
                Button(onClick = onSignOut) { Text("Deconectează-te") }
            }
        }

        // Top-ups happen on web/phone; show a QR to the wallet page.
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Box(
                modifier = Modifier.clip(RoundedCornerShape(12.dp)).background(TextPrimary).padding(10.dp),
            ) {
                QrCode(content = "${BuildConfig.WEB_BASE_URL}/dashboard?tab=wallet", modifier = Modifier.size(120.dp))
            }
            Spacer(Modifier.height(8.dp))
            Box(
                modifier = Modifier.clip(RoundedCornerShape(8.dp)).background(Surface)
                    .padding(horizontal = 12.dp, vertical = 6.dp),
            ) {
                Text("Adaugă fonduri", color = TextSecondary, fontSize = 13.sp)
            }
        }
    }
}
