package pe.vivatech.traza.handheld.ui.locate

import android.media.AudioManager
import android.media.ToneGenerator
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import kotlinx.coroutines.delay

/** Pantalla de búsqueda (Geiger) de `docs/09` §7. */
@Composable
fun LocateScreen(
    epc: String,
    productName: String,
    viewModel: LocateViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()

    LaunchedEffect(epc) { viewModel.locate(epc, productName) }

    val tones = remember { ToneGenerator(AudioManager.STREAM_MUSIC, 100) }
    DisposableEffect(Unit) { onDispose { tones.release() } }

    /*
     * El pitido va en su propio bucle y no atado a cada muestra de
     * proximidad: si dependiera de las lecturas, un hueco del lector callaría
     * el pitido justo cuando la persona está más cerca, que es lo contrario
     * de lo que tiene que pasar.
     */
    LaunchedEffect(state.isSearching) {
        while (state.isSearching) {
            tones.startTone(ToneGenerator.TONE_PROP_BEEP, 40)
            delay(state.beepIntervalMs)
        }
    }

    Column(
        modifier = Modifier.fillMaxSize().padding(16.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(productName, style = MaterialTheme.typography.titleLarge)
        Text(
            text = epc.chunked(4).joinToString(" "),
            style = MaterialTheme.typography.bodySmall,
        )

        Spacer(Modifier.weight(1f))

        ProximityDial(state.proximity)

        Text(
            text = state.label,
            fontSize = 32.sp,
            style = MaterialTheme.typography.displaySmall,
            modifier = Modifier.padding(top = 24.dp),
        )

        Spacer(Modifier.weight(1f))

        Text("El pitido acelera al acercarte", style = MaterialTheme.typography.bodySmall)

        Button(
            onClick = viewModel::stop,
            modifier = Modifier.fillMaxWidth().height(56.dp).padding(top = 12.dp),
        ) {
            Text("Detener")
        }
    }
}

/** Círculos concéntricos que crecen con la proximidad, y el número dentro. */
@Composable
private fun ProximityDial(proximity: Int) {
    // Animado para que no dé saltos: el valor ya viene suavizado, pero la
    // animación cubre el hueco entre muestras.
    val fraction by animateFloatAsState(
        targetValue = proximity / 100f,
        label = "proximity",
    )
    val color = MaterialTheme.colorScheme.primary

    Box(contentAlignment = Alignment.Center) {
        Canvas(modifier = Modifier.size(220.dp)) {
            val maxRadius = size.minDimension / 2

            repeat(4) { ring ->
                val step = (ring + 1) / 4f
                if (fraction >= step - 0.25f) {
                    drawCircle(
                        color = color,
                        radius = maxRadius * step,
                        alpha = 0.25f + 0.75f * fraction,
                        style = Stroke(width = 6f),
                    )
                }
            }
        }

        Text(text = proximity.toString(), fontSize = 64.sp)
    }
}
