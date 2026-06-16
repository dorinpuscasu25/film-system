package md.film.tv.ui.components

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsFocusedAsState
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.tv.material3.Border
import androidx.tv.material3.Card
import androidx.tv.material3.CardDefaults
import androidx.tv.material3.Glow
import androidx.tv.material3.Text
import coil.compose.AsyncImage
import md.film.tv.data.remote.dto.ContentCardDto
import md.film.tv.ui.theme.Accent
import md.film.tv.ui.theme.AccentGold
import md.film.tv.ui.theme.AccentGreen
import md.film.tv.ui.theme.TextPrimary
import md.film.tv.ui.theme.TextSecondary

/** A focusable poster card. The title + meta fade in over a scrim on focus. */
@Composable
fun MovieCard(
    card: ContentCardDto,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    fillWidth: Boolean = false,
) {
    val interaction = remember { MutableInteractionSource() }
    val focused by interaction.collectIsFocusedAsState()
    val rating = card.platformRating?.takeIf { it > 0 } ?: card.imdbRating?.takeIf { it > 0 }

    Card(
        onClick = onClick,
        interactionSource = interaction,
        modifier = if (fillWidth) modifier else modifier.width(184.dp),
        shape = CardDefaults.shape(RoundedCornerShape(14.dp)),
        scale = CardDefaults.scale(focusedScale = 1.12f),
        border = CardDefaults.border(
            focusedBorder = Border(BorderStroke(2.5.dp, Accent), shape = RoundedCornerShape(14.dp)),
        ),
        glow = CardDefaults.glow(focusedGlow = Glow(elevationColor = Accent, elevation = 14.dp)),
    ) {
        Box(
            modifier = Modifier
                .fillMaxWidth()
                .aspectRatio(2f / 3f),
        ) {
            if (card.posterUrl != null) {
                AsyncImage(
                    model = card.posterUrl,
                    contentDescription = card.title,
                    contentScale = ContentScale.Crop,
                    modifier = Modifier.fillMaxSize(),
                )
            } else {
                Text(
                    text = card.title ?: "",
                    color = TextSecondary,
                    fontSize = 14.sp,
                    fontWeight = FontWeight.SemiBold,
                    maxLines = 3,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.align(Alignment.Center).padding(12.dp),
                )
            }

            // Top badges: rating (left) and Free (right).
            if (rating != null) {
                Pill(
                    text = "★ $rating",
                    container = Color.Black.copy(alpha = 0.65f),
                    content = AccentGold,
                    modifier = Modifier.align(Alignment.TopStart).padding(8.dp),
                )
            }
            if (card.isFree) {
                Pill(
                    text = "GRATIS",
                    container = AccentGreen.copy(alpha = 0.9f),
                    content = Color.Black,
                    modifier = Modifier.align(Alignment.TopEnd).padding(8.dp),
                )
            }

            // On focus, reveal a scrim + title for context.
            if (focused) {
                Box(
                    modifier = Modifier
                        .matchParentSize()
                        .background(
                            Brush.verticalGradient(
                                0.45f to Color.Transparent,
                                1f to Color.Black.copy(alpha = 0.88f),
                            ),
                        ),
                )
                Text(
                    text = card.title ?: "",
                    color = TextPrimary,
                    fontSize = 13.sp,
                    fontWeight = FontWeight.SemiBold,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier
                        .align(Alignment.BottomStart)
                        .padding(10.dp),
                )
            }
        }
    }
}

@Composable
private fun Pill(
    text: String,
    container: Color,
    content: Color,
    modifier: Modifier = Modifier,
) {
    Box(
        modifier = modifier
            .background(container, RoundedCornerShape(6.dp))
            .padding(horizontal = 7.dp, vertical = 3.dp),
    ) {
        Text(text = text, color = content, fontSize = 11.sp, fontWeight = FontWeight.Bold)
    }
}
