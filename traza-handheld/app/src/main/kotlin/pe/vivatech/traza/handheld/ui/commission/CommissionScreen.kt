package pe.vivatech.traza.handheld.ui.commission

import android.media.AudioManager
import android.media.ToneGenerator
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import kotlinx.coroutines.delay
import pe.vivatech.traza.core.domain.FeedbackSignal
import pe.vivatech.traza.handheld.ui.common.BigCount

/** Pantalla de tarado de `docs/09` §8. */
@Composable
fun CommissionScreen(
    variantId: Long,
    variantLabel: String,
    viewModel: CommissionViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val signal by viewModel.signal.collectAsStateWithLifecycle()
    val haptics = LocalHapticFeedback.current

    LaunchedEffect(variantId) { viewModel.open(variantId) }

    val tones = rememberToneGenerator()

    LaunchedEffect(signal) {
        val current = signal ?: return@LaunchedEffect

        play(tones, current)
        if (current == FeedbackSignal.LONG_LOW_WITH_VIBRATION) {
            haptics.performHapticFeedback(HapticFeedbackType.LongPress)
        }

        viewModel.consumeSignal()
    }

    Column(
        modifier = Modifier.fillMaxSize().padding(16.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        // El SKU se fija una vez y se queda a la vista: taras trescientas
        // prendas seguidas y la duda de «¿sigo en el mismo?» sale cara.
        Card(modifier = Modifier.fillMaxWidth()) {
            Column(modifier = Modifier.padding(16.dp)) {
                Text("SKU activo", style = MaterialTheme.typography.labelMedium)
                Text(variantLabel, style = MaterialTheme.typography.titleLarge)
            }
        }

        Spacer(Modifier.weight(1f))

        BigCount(value = state.commissionedInSession, label = "taradas en esta sesión")

        state.lastEpc?.let { epc ->
            Text(
                text = "Última: ${epc.takeLast(8)}",
                style = MaterialTheme.typography.bodyMedium,
            )
        }

        Spacer(Modifier.weight(1f))

        Button(
            onClick = viewModel::commissionOne,
            modifier = Modifier.fillMaxWidth().height(64.dp),
        ) {
            Text("Tarar prenda")
        }

        Text(
            text = "Potencia baja: solo lee la prenda que tienes en la mano",
            style = MaterialTheme.typography.bodySmall,
            modifier = Modifier.padding(top = 12.dp),
        )
    }

    // El conflicto es el único caso que interrumpe el ritmo, y lo hace porque
    // reasignar un tag en silencio descuadra el inventario de dos variantes.
    state.pendingConflict?.let { conflict ->
        AlertDialog(
            onDismissRequest = viewModel::dismissConflict,
            title = { Text("Este tag ya es de otro producto") },
            text = {
                Text(
                    "El EPC ${conflict.epc.takeLast(8)} está tarado a la variante " +
                        "${conflict.existingVariantId}. Reasignarlo dejará mal el stock de " +
                        "las dos si te has equivocado de prenda.",
                )
            },
            confirmButton = {
                TextButton(onClick = viewModel::confirmReassignment) { Text("Reasignar") }
            },
            dismissButton = {
                TextButton(onClick = viewModel::dismissConflict) { Text("Cancelar") }
            },
        )
    }
}

@Composable
private fun rememberToneGenerator(): ToneGenerator {
    val generator = remember { ToneGenerator(AudioManager.STREAM_NOTIFICATION, 100) }

    DisposableEffect(Unit) {
        onDispose { generator.release() }
    }

    return generator
}

/**
 * Cuatro señales distinguibles sin mirar. El silencio del `NoTag` es
 * deliberado: castigar con un pitido cada gatillo que no engancha vuelve
 * insufrible una sesión de trescientas prendas.
 */
private suspend fun play(tones: ToneGenerator, signal: FeedbackSignal) {
    when (signal) {
        FeedbackSignal.SHORT_HIGH ->
            tones.startTone(ToneGenerator.TONE_PROP_BEEP, 80)

        FeedbackSignal.DOUBLE_SHORT -> {
            tones.startTone(ToneGenerator.TONE_PROP_BEEP, 60)
            delay(140)
            tones.startTone(ToneGenerator.TONE_PROP_BEEP, 60)
        }

        FeedbackSignal.LONG_LOW_WITH_VIBRATION ->
            tones.startTone(ToneGenerator.TONE_SUP_ERROR, 600)

        FeedbackSignal.SILENT -> Unit
    }
}
