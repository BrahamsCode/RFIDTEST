package pe.vivatech.traza.handheld

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavHostController
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import dagger.hilt.android.AndroidEntryPoint
import pe.vivatech.traza.handheld.ui.commission.CommissionScreen
import pe.vivatech.traza.handheld.ui.inventory.InventoryScreen
import pe.vivatech.traza.handheld.ui.locate.LocateScreen

@AndroidEntryPoint
class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        setContent {
            // Modo oscuro por defecto: el equipo se usa en trastiendas con
            // mala luz y las sesiones son largas.
            MaterialTheme(colorScheme = darkColorScheme()) {
                Surface(modifier = Modifier.fillMaxSize()) {
                    TrazaNavHost(rememberNavController())
                }
            }
        }
    }
}

private object Routes {
    const val MENU = "menu"
    const val INVENTORY = "inventario"
    const val COMMISSION = "tarado"
    const val LOCATE = "buscar"
}

@Composable
private fun TrazaNavHost(navController: NavHostController) {
    NavHost(navController = navController, startDestination = Routes.MENU) {
        composable(Routes.MENU) {
            ModeMenu(
                onInventory = { navController.navigate(Routes.INVENTORY) },
                onCommission = { navController.navigate(Routes.COMMISSION) },
                onLocate = { navController.navigate(Routes.LOCATE) },
            )
        }

        /*
         * Los identificadores van fijos mientras no exista la pantalla de
         * selección: la aplicación arranca contra el lector falso y sirve
         * para recorrer los tres modos sin hardware ni servidor. La
         * selección real llega con el alta por QR (tarea 5.6).
         */
        composable(Routes.INVENTORY) {
            InventoryScreen(cycleId = 1, cycleCode = "INV-DEMO", expectedCount = 500)
        }

        composable(Routes.COMMISSION) {
            CommissionScreen(variantId = 1, variantLabel = "Variante de prueba")
        }

        composable(Routes.LOCATE) {
            LocateScreen(epc = "3035D919080C0E403B9ACA2A", productName = "Prenda de prueba")
        }
    }
}

@Composable
private fun ModeMenu(
    onInventory: () -> Unit,
    onCommission: () -> Unit,
    onLocate: () -> Unit,
) {
    Column(
        modifier = Modifier.fillMaxSize().padding(24.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp),
    ) {
        Text(text = "TRAZA", fontSize = 40.sp)

        // Botones grandes: se pulsan de pie, con una mano ocupada.
        Button(onClick = onInventory, modifier = Modifier.fillMaxWidth().height(72.dp)) {
            Text("Inventario", fontSize = 20.sp)
        }
        Button(onClick = onCommission, modifier = Modifier.fillMaxWidth().height(72.dp)) {
            Text("Tarar prendas", fontSize = 20.sp)
        }
        Button(onClick = onLocate, modifier = Modifier.fillMaxWidth().height(72.dp)) {
            Text("Buscar una prenda", fontSize = 20.sp)
        }
    }
}
