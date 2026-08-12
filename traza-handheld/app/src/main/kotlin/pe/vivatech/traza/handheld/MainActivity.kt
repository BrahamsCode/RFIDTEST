package pe.vivatech.traza.handheld

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import dagger.hilt.android.AndroidEntryPoint

@AndroidEntryPoint
class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent {
            MaterialTheme(colorScheme = darkColorScheme()) {
                PlaceholderScreen()
            }
        }
    }
}

/**
 * Marcador de posición. Las pantallas reales (inventario, tarado, búsqueda)
 * llegan en las tareas 5.2, 5.4 y 5.5.
 */
@Composable
private fun PlaceholderScreen() {
    Column(modifier = Modifier.padding(24.dp)) {
        Text(text = "TRAZA", fontSize = 32.sp)
        Text(text = "Lector no inicializado", fontSize = 16.sp)
    }
}
