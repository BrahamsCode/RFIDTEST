package pe.vivatech.traza.handheld.data.sync

import android.content.Context
import dagger.hilt.android.qualifiers.ApplicationContext
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import pe.vivatech.traza.core.domain.UploadPolicy
import java.util.concurrent.TimeUnit
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class SyncScheduler @Inject constructor(
    @ApplicationContext private val context: Context,
) {

    /**
     * Durante un barrido esto se llama cientos de veces por minuto.
     *
     * `ExistingWorkPolicy.KEEP` es lo que evita encolar cientos de trabajos:
     * si ya hay uno pendiente, la llamada no hace nada. Con `REPLACE` cada
     * lectura cancelaría el trabajo en curso y la cola no bajaría nunca.
     */
    fun requestSync() {
        val request = OneTimeWorkRequestBuilder<UploadScansWorker>()
            .setConstraints(
                Constraints.Builder()
                    .setRequiredNetworkType(NetworkType.CONNECTED)
                    .build(),
            )
            .setBackoffCriteria(
                BackoffPolicy.EXPONENTIAL,
                UploadPolicy.INITIAL_BACKOFF_SECONDS,
                TimeUnit.SECONDS,
            )
            .build()

        WorkManager.getInstance(context)
            .enqueueUniqueWork(WORK_NAME, ExistingWorkPolicy.KEEP, request)
    }

    private companion object {
        const val WORK_NAME = "upload-scans"
    }
}
