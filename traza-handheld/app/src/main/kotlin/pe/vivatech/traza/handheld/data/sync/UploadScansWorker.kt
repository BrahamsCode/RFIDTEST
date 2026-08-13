package pe.vivatech.traza.handheld.data.sync

import android.content.Context
import androidx.hilt.work.HiltWorker
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import dagger.assisted.Assisted
import dagger.assisted.AssistedInject
import pe.vivatech.traza.core.domain.UploadAttempt
import pe.vivatech.traza.core.domain.UploadDecision
import pe.vivatech.traza.core.domain.UploadPolicy
import pe.vivatech.traza.handheld.data.local.ScanDao
import pe.vivatech.traza.handheld.data.local.ScanEntity
import pe.vivatech.traza.handheld.data.remote.DeviceCredentials
import pe.vivatech.traza.handheld.data.remote.IngestRequest
import pe.vivatech.traza.handheld.data.remote.ReadDto
import pe.vivatech.traza.handheld.data.remote.TrazaApi
import retrofit2.HttpException
import java.io.IOException
import java.time.Instant
import java.util.UUID

/**
 * Sube los escaneos pendientes. Ver `docs/09` §6.
 *
 * La decisión de qué hacer con cada fallo no está aquí sino en
 * `UploadPolicy`, en `core:domain`: ahí se puede probar sin Android, y es la
 * parte donde equivocarse tiene consecuencias —una cola que nunca baja o
 * escaneos tirados a la basura.
 */
@HiltWorker
class UploadScansWorker @AssistedInject constructor(
    @Assisted context: Context,
    @Assisted params: WorkerParameters,
    private val scanDao: ScanDao,
    private val api: TrazaApi,
    private val credentials: DeviceCredentials,
) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val deviceCode = credentials.deviceCode ?: return Result.failure()

        val batch = scanDao.pending(limit = BATCH_SIZE)
        if (batch.isEmpty()) return Result.success()

        val attempt = try {
            api.ingestReads(
                IngestRequest(
                    deviceCode = deviceCode,
                    // El identificador de lote es lo que hace idempotente la
                    // ingesta: si la respuesta se pierde por un timeout, el
                    // servidor reconoce el reintento y no duplica lecturas.
                    batchId = UUID.randomUUID().toString(),
                    inventoryCycleId = batch.first().cycleId,
                    reads = batch.map { it.toDto() },
                ),
            )
            UploadAttempt.Success
        } catch (e: IOException) {
            UploadAttempt.Network(e.message ?: "sin red")
        } catch (e: HttpException) {
            UploadAttempt.Http(e.code(), e.message())
        }

        return when (val decision = UploadPolicy.decide(attempt)) {
            is UploadDecision.MarkUploaded -> {
                scanDao.markUploaded(batch.map { it.id })
                // Si queda cola, se encadena otra ejecución inmediata en vez
                // de esperar al siguiente disparo.
                if (scanDao.pendingCount() > 0) Result.retry() else Result.success()
            }

            is UploadDecision.Retry -> Result.retry()

            is UploadDecision.AuthProblem -> Result.failure(
                androidx.work.Data.Builder().putString(KEY_ERROR, decision.reason).build(),
            )

            is UploadDecision.MarkFailed -> {
                scanDao.markFailed(batch.map { it.id }, decision.reason)
                Result.failure(
                    androidx.work.Data.Builder().putString(KEY_ERROR, decision.reason).build(),
                )
            }
        }
    }

    private fun ScanEntity.toDto() = ReadDto(
        epc = epc,
        tid = tid,
        rssi = rssi,
        readAt = Instant.ofEpochMilli(seenAt).toString(),
    )

    companion object {
        const val KEY_ERROR = "error"
        const val BATCH_SIZE = 500
    }
}
