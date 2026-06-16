package md.film.tv.ui.player

import androidx.compose.foundation.background
import androidx.compose.foundation.focusable
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
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.tv.material3.Button
import androidx.tv.material3.Text
import md.film.tv.ui.components.QrCode
import md.film.tv.ui.theme.Accent
import md.film.tv.ui.theme.Background
import md.film.tv.ui.theme.Surface
import md.film.tv.ui.theme.TextPrimary
import md.film.tv.ui.theme.TextSecondary

/**
 * Shown when the user has no entitlement. We never sell on the TV (Play policy +
 * the user's wish) — instead point them to the web/phone and let them re-check.
 */
@Composable
fun PurchaseBlockedScreen(
    title: String?,
    movieUrl: String,
    onRefresh: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Box(
        modifier = modifier
            .fillMaxSize()
            .background(Background)
            .padding(64.dp),
        contentAlignment = Alignment.Center,
    ) {
        Row(
            horizontalArrangement = Arrangement.spacedBy(72.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(modifier = Modifier.width(620.dp)) {
                Text(
                    text = "Acest titlu se cumpără online",
                    color = TextPrimary,
                    fontSize = 38.sp,
                    fontWeight = FontWeight.Bold,
                )
                title?.let {
                    Spacer(Modifier.height(8.dp))
                    Text(text = it, color = Accent, fontSize = 22.sp, fontWeight = FontWeight.SemiBold)
                }
                Spacer(Modifier.height(20.dp))
                Text(
                    text = "Pentru a viziona, finalizează achiziția pe telefon sau computer. " +
                        "Scanează codul din dreapta sau deschide pagina filmului în contul tău. " +
                        "Apoi apasă „Verifică din nou”.",
                    color = TextSecondary,
                    fontSize = 18.sp,
                )
                Spacer(Modifier.height(12.dp))
                Text(
                    text = movieUrl.removePrefix("http://").removePrefix("https://"),
                    color = TextPrimary,
                    fontSize = 16.sp,
                    fontWeight = FontWeight.Medium,
                )
                Spacer(Modifier.height(28.dp))
                Button(onClick = onRefresh, modifier = Modifier.focusable()) {
                    Text("Verifică din nou", fontWeight = FontWeight.SemiBold)
                }
            }

            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                Box(
                    modifier = Modifier
                        .clip(RoundedCornerShape(16.dp))
                        .background(TextPrimary)
                        .padding(16.dp),
                ) {
                    QrCode(content = movieUrl, modifier = Modifier.size(240.dp))
                }
                Spacer(Modifier.height(12.dp))
                Box(
                    modifier = Modifier
                        .clip(RoundedCornerShape(8.dp))
                        .background(Surface)
                        .padding(horizontal = 16.dp, vertical = 8.dp),
                ) {
                    Text("Scanează pentru a cumpăra", color = TextSecondary, fontSize = 14.sp)
                }
            }
        }
    }
}
