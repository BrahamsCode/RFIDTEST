package pe.vivatech.traza.handheld.ui.inventory

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import pe.vivatech.traza.handheld.ui.common.BigCount
import pe.vivatech.traza.handheld.ui.common.CycleProgressBar
import pe.vivatech.traza.handheld.ui.common.KeepScreenOn
import pe.vivatech.traza.handheld.ui.common.SyncStatusBar
import pe.vivatech.traza.handheld.ui.common.formatThousands

/** Pantalla de inventario de `docs/09` §5. */
@Composable
fun InventoryScreen(
    cycleId: Long,
    cycleCode: String,
    expectedCount: Int,
    viewModel: InventoryViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val haptics = LocalHapticFeedback.current

    LaunchedEffect(cycleId) { viewModel.open(cycleId, expectedCount) }

    // Realimentación al encontrar tags nuevos: quien barre mira la estantería,
    // no la pantalla, y necesita saber por la mano que el equipo va leyendo.
    LaunchedEffect(state.newTagsInBurst) {
        if (state.newTagsInBurst > 0) {
            haptics.performHapticFeedback(HapticFeedbackType.TextHandleMove)
        }
    }

    KeepScreenOn(enabled = state.isScanning)

    Column(
        modifier = Modifier.fillMaxSize().padding(16.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(text = "Ciclo $cycleCode", style = MaterialTheme.typography.titleMedium)

        Spacer(Modifier.weight(1f))

        BigCount(value = state.scannedCount, label = "escaneados")

        AnimatedVisibility(visible = state.newTagsInBurst > 0) {
            Text(
                text = "+${state.newTagsInBurst.formatThousands()} en esta pasada",
                color = MaterialTheme.colorScheme.primary,
                style = MaterialTheme.typography.titleMedium,
            )
        }

        Spacer(Modifier.weight(1f))

        CycleProgressBar(scanned = state.scannedCount, expected = state.expectedCount)

        Row(
            modifier = Modifier.fillMaxWidth().padding(vertical = 16.dp),
            horizontalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            // 56 dp de alto: se pulsan con guantes y sin mirar.
            OutlinedButton(
                onClick = { viewModel.changeZone(null) },
                modifier = Modifier.weight(1f).height(56.dp),
            ) {
                Text("Cambiar zona")
            }

            Button(
                onClick = viewModel::togglePause,
                modifier = Modifier.weight(1f).height(56.dp),
            ) {
                Text(if (state.isPaused) "Reanudar" else "Pausar")
            }
        }

        SyncStatusBar(pending = state.pendingUploads)

        Text(
            text = "Mantener el gatillo para escanear",
            style = MaterialTheme.typography.bodySmall,
        )
    }
}
