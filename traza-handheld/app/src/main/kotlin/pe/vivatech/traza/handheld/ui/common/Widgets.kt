package pe.vivatech.traza.handheld.ui.common

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import android.app.Activity
import android.view.WindowManager

/**
 * Mantiene la pantalla encendida mientras se escanea.
 *
 * Un barrido dura de 20 a 45 minutos y la persona no toca la pantalla en
 * todo ese rato: sin esto se apaga cada minuto y hay que desbloquear con la
 * mano que sujeta el equipo. Se libera al pausar, que es lo que evita
 * fundir la batería en un descanso.
 */
@Composable
fun KeepScreenOn(enabled: Boolean) {
    val view = LocalView.current
    val context = LocalContext.current

    DisposableEffect(enabled) {
        val window = (context as? Activity)?.window

        if (enabled) {
            window?.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        } else {
            window?.clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        }

        onDispose {
            window?.clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        }
    }
}

/**
 * El número dominante de la pantalla. 72 sp y cifras tabulares: se lee de
 * reojo, con el equipo a la altura de la cintura.
 */
@Composable
fun BigCount(value: Int, label: String, modifier: Modifier = Modifier) {
    Column(modifier = modifier, horizontalAlignment = Alignment.CenterHorizontally) {
        Text(
            text = value.formatThousands(),
            fontSize = 72.sp,
            textAlign = TextAlign.Center,
            style = MaterialTheme.typography.displayLarge.copy(fontFeatureSettings = "tnum"),
        )
        Text(text = label, style = MaterialTheme.typography.titleMedium)
    }
}

@Composable
fun CycleProgressBar(scanned: Int, expected: Int, modifier: Modifier = Modifier) {
    val fraction = if (expected <= 0) 0f else (scanned.toFloat() / expected).coerceIn(0f, 1f)

    Column(modifier = modifier.fillMaxWidth().padding(horizontal = 16.dp)) {
        LinearProgressIndicator(
            progress = { fraction },
            modifier = Modifier.fillMaxWidth(),
        )
        Text(
            text = "${scanned.formatThousands()} de ${expected.formatThousands()} esperados",
            style = MaterialTheme.typography.bodyMedium,
        )
    }
}

@Composable
fun SyncStatusBar(pending: Int, modifier: Modifier = Modifier) {
    // Sin este renglón nadie sabe si lo que acaba de leer está a salvo, y en
    // una tienda sin cobertura esa duda es constante.
    Text(
        text = if (pending == 0) "● Sincronizado" else "○ ${pending.formatThousands()} pendientes",
        style = MaterialTheme.typography.bodyMedium,
        modifier = modifier.padding(8.dp),
    )
}

fun Int.formatThousands(): String = toString()
    .reversed()
    .chunked(3)
    .joinToString(" ")
    .reversed()
