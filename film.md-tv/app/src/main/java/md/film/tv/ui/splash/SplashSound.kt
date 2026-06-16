package md.film.tv.ui.splash

import android.media.AudioAttributes
import android.media.AudioFormat
import android.media.AudioManager
import android.media.AudioTrack
import kotlin.math.PI
import kotlin.math.exp
import kotlin.math.sin

/**
 * A short branded startup "sting" — a warm ascending arpeggio, synthesized on
 * the fly so it ships with no audio asset. Evokes a premium intro (Netflix-like)
 * while staying tasteful.
 *
 * To use a custom recorded jingle instead (e.g. a nai/pan-flute motif), drop an
 * mp3 at res/raw/intro.mp3 and play it with MediaPlayer.create(ctx, R.raw.intro).
 */
object SplashSound {

    private const val SAMPLE_RATE = 44_100

    /** Plays the sting on a background thread; safe to call once from the splash. */
    fun play() {
        Thread {
            runCatching {
                val samples = render()
                val track = AudioTrack.Builder()
                    .setAudioAttributes(
                        AudioAttributes.Builder()
                            .setUsage(AudioAttributes.USAGE_MEDIA)
                            .setContentType(AudioAttributes.CONTENT_TYPE_MUSIC)
                            .build(),
                    )
                    .setAudioFormat(
                        AudioFormat.Builder()
                            .setSampleRate(SAMPLE_RATE)
                            .setEncoding(AudioFormat.ENCODING_PCM_16BIT)
                            .setChannelMask(AudioFormat.CHANNEL_OUT_MONO)
                            .build(),
                    )
                    .setBufferSizeInBytes(samples.size * 2)
                    .setTransferMode(AudioTrack.MODE_STATIC)
                    .build()

                track.write(samples, 0, samples.size)
                track.setVolume(AudioManager.STREAM_MUSIC.toFloat().coerceAtMost(1f))
                track.play()
            }
        }.apply { isDaemon = true }.start()
    }

    private fun render(): ShortArray {
        // A-major arpeggio with an octave finish: A4, C#5, E5, A5 + a soft bass A2.
        val melody = doubleArrayOf(440.0, 554.37, 659.25, 880.0)
        val bass = 110.0
        val noteStep = 0.16            // seconds between note onsets (they overlap)
        val tail = 0.9                 // ring-out after the last note
        val totalSeconds = melody.size * noteStep + tail
        val totalSamples = (totalSeconds * SAMPLE_RATE).toInt()
        val buffer = DoubleArray(totalSamples)

        // Layer each melody note with an exponential decay envelope.
        melody.forEachIndexed { i, freq ->
            val start = (i * noteStep * SAMPLE_RATE).toInt()
            addTone(buffer, start, freq, durationSec = 1.1, amp = 0.5, decay = 4.0)
            // gentle fifth above for shimmer
            addTone(buffer, start, freq * 1.5, durationSec = 1.1, amp = 0.12, decay = 5.0)
        }
        // Warm bass underneath the whole sting.
        addTone(buffer, 0, bass, durationSec = totalSeconds, amp = 0.22, decay = 1.2)

        // Normalize to avoid clipping, then convert to 16-bit PCM.
        val peak = buffer.maxOf { kotlin.math.abs(it) }.coerceAtLeast(1e-6)
        val gain = 0.85 / peak
        return ShortArray(totalSamples) { idx ->
            (buffer[idx] * gain * Short.MAX_VALUE).toInt().toShort()
        }
    }

    private fun addTone(
        buffer: DoubleArray,
        startSample: Int,
        freq: Double,
        durationSec: Double,
        amp: Double,
        decay: Double,
    ) {
        val length = (durationSec * SAMPLE_RATE).toInt()
        for (n in 0 until length) {
            val idx = startSample + n
            if (idx >= buffer.size) break
            val t = n.toDouble() / SAMPLE_RATE
            val env = exp(-decay * t)            // percussive decay
            val attack = (t / 0.01).coerceAtMost(1.0) // 10ms attack to avoid clicks
            buffer[idx] += amp * env * attack * sin(2.0 * PI * freq * t)
        }
    }
}
