package md.film.tv.ui.components

import androidx.compose.foundation.Image
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.graphics.painter.BitmapPainter
import androidx.core.graphics.createBitmap
import androidx.core.graphics.set
import com.google.zxing.BarcodeFormat
import com.google.zxing.EncodeHintType
import com.google.zxing.qrcode.QRCodeWriter
import com.google.zxing.qrcode.decoder.ErrorCorrectionLevel

private fun generateQrBitmap(content: String, sizePx: Int): ImageBitmap {
    val hints = mapOf(
        EncodeHintType.ERROR_CORRECTION to ErrorCorrectionLevel.M,
        EncodeHintType.MARGIN to 1,
    )
    val matrix = QRCodeWriter().encode(content, BarcodeFormat.QR_CODE, sizePx, sizePx, hints)
    val bitmap = createBitmap(sizePx, sizePx)
    val black = 0xFF000000.toInt()
    val white = 0xFFFFFFFF.toInt()
    for (x in 0 until sizePx) {
        for (y in 0 until sizePx) {
            bitmap[x, y] = if (matrix[x, y]) black else white
        }
    }
    return bitmap.asImageBitmap()
}

@Composable
fun QrCode(
    content: String,
    modifier: Modifier = Modifier,
    sizePx: Int = 320,
) {
    val image = remember(content, sizePx) { generateQrBitmap(content, sizePx) }
    Image(
        painter = BitmapPainter(image),
        contentDescription = "QR code",
        modifier = modifier,
    )
}
