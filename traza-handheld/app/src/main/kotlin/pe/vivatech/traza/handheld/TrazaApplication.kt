package pe.vivatech.traza.handheld

import android.app.Application
import androidx.hilt.work.HiltWorkerFactory
import androidx.work.Configuration
import dagger.hilt.android.HiltAndroidApp
import javax.inject.Inject

@HiltAndroidApp
class TrazaApplication : Application(), Configuration.Provider {

    /**
     * WorkManager tiene que saber construir `UploadScansWorker`, que recibe
     * el DAO y el cliente por inyección. Sin esta fábrica, el trabajo falla
     * al instanciarse y la cola de escaneos no sube nunca — en silencio.
     */
    @Inject
    lateinit var workerFactory: HiltWorkerFactory

    override val workManagerConfiguration: Configuration
        get() = Configuration.Builder()
            .setWorkerFactory(workerFactory)
            .build()
}
