package md.film.tv.ui.splash

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.size
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.tv.material3.Text
import kotlinx.coroutines.delay
import md.film.tv.ui.theme.Accent
import md.film.tv.ui.theme.Background
import md.film.tv.ui.theme.MdBlue
import md.film.tv.ui.theme.MdRed
import md.film.tv.ui.theme.MdYellow
import md.film.tv.ui.theme.TextPrimary
import md.film.tv.ui.theme.TextSecondary

private val MotifColors = listOf(MdBlue, MdRed, MdYellow, MdRed, MdBlue)

@Composable
fun SplashScreen(onDone: () -> Unit) {
    val reveal = remember { Animatable(0f) }

    LaunchedEffect(Unit) {
        SplashSound.play()
        reveal.animateTo(1f, animationSpec = tween(900, easing = FastOutSlowInEasing))
        delay(1700)
        onDone()
    }

    // A slow light sweep across the wordmark for a premium feel.
    val shimmer by rememberInfiniteTransition(label = "shimmer").animateFloat(
        initialValue = -1f,
        targetValue = 2f,
        animationSpec = infiniteRepeatable(
            animation = tween(2600, easing = LinearEasing),
            repeatMode = RepeatMode.Restart,
        ),
        label = "sweep",
    )

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(
                Brush.radialGradient(
                    colors = listOf(Color(0xFF11162B), Background, Color.Black),
                    radius = 1400f,
                ),
            ),
        contentAlignment = Alignment.Center,
    ) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            // Traditional Moldovan "romb" embroidery strip.
            MotifStrip(progress = reveal.value)

            Spacer(Modifier.height(36.dp))

            val scale = 0.82f + 0.18f * reveal.value
            Box(contentAlignment = Alignment.Center) {
                Text(
                    text = "FILMOTECA",
                    color = TextPrimary.copy(alpha = reveal.value),
                    fontSize = (54 * scale).sp,
                    fontWeight = FontWeight.Black,
                )
                // Moving shimmer highlight.
                Box(
                    modifier = Modifier
                        .matchParentSize()
                        .background(
                            Brush.linearGradient(
                                colors = listOf(
                                    Color.Transparent,
                                    Color.White.copy(alpha = 0.20f * reveal.value),
                                    Color.Transparent,
                                ),
                                start = Offset(shimmer * 600f, 0f),
                                end = Offset(shimmer * 600f + 220f, 220f),
                            ),
                        ),
                )
            }

            Spacer(Modifier.height(6.dp))
            Text(
                text = ".md",
                color = Accent.copy(alpha = reveal.value),
                fontSize = (26 * scale).sp,
                fontWeight = FontWeight.Bold,
            )
            Spacer(Modifier.height(18.dp))
            Text(
                text = "C I N E M A T O G R A F I A   M O L D O V E I",
                color = TextSecondary.copy(alpha = reveal.value * 0.9f),
                fontSize = 15.sp,
                fontWeight = FontWeight.Medium,
            )
        }
    }
}

/**
 * A row of nested diamonds in the national tricolour — the "romb" motif found on
 * Moldovan traditional rugs (covor) and embroidered shirts (ie). Each diamond
 * scales in, staggered, as [progress] goes 0 → 1.
 */
@Composable
private fun MotifStrip(progress: Float) {
    Canvas(modifier = Modifier.size(width = 360.dp, height = 64.dp)) {
        val count = MotifColors.size
        val cell = size.width / count
        val cy = size.height / 2f
        val maxR = (size.height / 2f) * 0.92f

        MotifColors.forEachIndexed { i, color ->
            // Staggered reveal per diamond.
            val local = ((progress - i * 0.12f) / 0.55f).coerceIn(0f, 1f)
            if (local <= 0f) return@forEachIndexed
            val cx = cell * i + cell / 2f
            val r = maxR * local

            drawDiamond(cx, cy, r, color.copy(alpha = local), filled = true)
            drawDiamond(cx, cy, r * 0.55f, MdYellow.copy(alpha = local), filled = true)
            drawDiamond(cx, cy, r, TextPrimary.copy(alpha = 0.25f * local), filled = false)
        }
    }
}

private fun androidx.compose.ui.graphics.drawscope.DrawScope.drawDiamond(
    cx: Float,
    cy: Float,
    r: Float,
    color: Color,
    filled: Boolean,
) {
    val path = Path().apply {
        moveTo(cx, cy - r)
        lineTo(cx + r, cy)
        lineTo(cx, cy + r)
        lineTo(cx - r, cy)
        close()
    }
    if (filled) drawPath(path, color) else drawPath(path, color, style = Stroke(width = 2f))
}
