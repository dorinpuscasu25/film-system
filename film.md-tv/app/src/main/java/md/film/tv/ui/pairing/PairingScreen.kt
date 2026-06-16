package md.film.tv.ui.pairing

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.tv.material3.Button
import androidx.tv.material3.Text
import md.film.tv.ui.AppViewModelFactory
import md.film.tv.ui.components.QrCode
import md.film.tv.ui.theme.Accent
import md.film.tv.ui.theme.Background
import md.film.tv.ui.theme.Surface
import md.film.tv.ui.theme.TextPrimary
import md.film.tv.ui.theme.TextSecondary

@Composable
fun PairingScreen(
    onPaired: () -> Unit,
    viewModel: PairingViewModel = viewModel(factory = AppViewModelFactory.factory),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()

    LaunchedEffect(state) {
        if (state is PairingUiState.Authorized) onPaired()
    }

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(Background)
            .padding(64.dp),
        contentAlignment = Alignment.Center,
    ) {
        when (val s = state) {
            PairingUiState.Loading -> Text(
                text = "Se pregătește conectarea…",
                color = TextSecondary,
                fontSize = 20.sp,
            )

            is PairingUiState.AwaitingApproval -> AwaitingApproval(s)

            PairingUiState.Authorized -> Text(
                text = "Conectat!",
                color = TextPrimary,
                fontSize = 28.sp,
                fontWeight = FontWeight.Bold,
            )

            is PairingUiState.Failed -> Failed(s.message, onRetry = viewModel::start)
        }
    }
}

@Composable
private fun AwaitingApproval(state: PairingUiState.AwaitingApproval) {
    Row(
        horizontalArrangement = Arrangement.spacedBy(72.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(modifier = Modifier.width(560.dp)) {
            Text(
                text = "FILMOTECA.md",
                color = TextPrimary,
                fontSize = 18.sp,
                fontWeight = FontWeight.Bold,
            )
            Spacer(Modifier.height(24.dp))
            Text(
                text = "Conectează-te",
                color = TextPrimary,
                fontSize = 44.sp,
                fontWeight = FontWeight.Bold,
            )
            Spacer(Modifier.height(16.dp))
            Text(
                text = "1.  Pe telefon sau computer deschide:",
                color = TextSecondary,
                fontSize = 20.sp,
            )
            Text(
                text = state.verificationUri.removePrefix("http://").removePrefix("https://"),
                color = Accent,
                fontSize = 24.sp,
                fontWeight = FontWeight.SemiBold,
            )
            Spacer(Modifier.height(20.dp))
            Text(
                text = "2.  Introdu codul de mai jos:",
                color = TextSecondary,
                fontSize = 20.sp,
            )
            Spacer(Modifier.height(16.dp))
            Box(
                modifier = Modifier
                    .clip(RoundedCornerShape(16.dp))
                    .background(Surface)
                    .padding(horizontal = 32.dp, vertical = 20.dp),
            ) {
                Text(
                    text = state.userCode,
                    color = TextPrimary,
                    fontSize = 52.sp,
                    fontWeight = FontWeight.Bold,
                    fontFamily = FontFamily.Monospace,
                )
            }
            Spacer(Modifier.height(24.dp))
            Text(
                text = "Lasă acest ecran deschis — televizorul se conectează automat.",
                color = TextSecondary,
                fontSize = 16.sp,
            )
        }

        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Box(
                modifier = Modifier
                    .clip(RoundedCornerShape(16.dp))
                    .background(TextPrimary)
                    .padding(16.dp),
            ) {
                QrCode(
                    content = state.verificationUriComplete,
                    modifier = Modifier.size(260.dp),
                )
            }
            Spacer(Modifier.height(16.dp))
            Text(
                text = "Scanează cu telefonul",
                color = TextSecondary,
                fontSize = 16.sp,
            )
        }
    }
}

@Composable
private fun Failed(message: String, onRetry: () -> Unit) {
    Column(
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(20.dp),
    ) {
        Text(
            text = message,
            color = TextPrimary,
            fontSize = 22.sp,
            textAlign = TextAlign.Center,
        )
        Button(onClick = onRetry) {
            Text("Încearcă din nou")
        }
    }
}
