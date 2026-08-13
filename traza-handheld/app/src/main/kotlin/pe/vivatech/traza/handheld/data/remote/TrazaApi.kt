package pe.vivatech.traza.handheld.data.remote

import com.squareup.moshi.Json
import retrofit2.http.Body
import retrofit2.http.Header
import retrofit2.http.POST

data class ReadDto(
    val epc: String,
    val tid: String?,
    val rssi: Int,
    @Json(name = "read_at") val readAt: String,
    @Json(name = "read_count") val readCount: Int = 1,
    val antenna: Int = 1,
)

data class IngestRequest(
    @Json(name = "device_code") val deviceCode: String,
    @Json(name = "batch_id") val batchId: String,
    @Json(name = "inventory_cycle_id") val inventoryCycleId: Long?,
    val reads: List<ReadDto>,
)

data class IngestResponse(
    val accepted: Int = 0,
    val rejected: Int = 0,
    @Json(name = "batch_id") val batchId: String? = null,
)

data class HeartbeatRequest(
    @Json(name = "device_code") val deviceCode: String,
    @Json(name = "battery_pct") val batteryPct: Int?,
    @Json(name = "buffer_depth") val bufferDepth: Int,
    val version: String,
)

data class EnrollRequest(
    @Json(name = "device_code") val deviceCode: String,
    @Json(name = "enrollment_token") val enrollmentToken: String,
)

data class EnrollResponse(
    @Json(name = "device_code") val deviceCode: String,
    @Json(name = "device_name") val deviceName: String,
    @Json(name = "location_id") val locationId: Long?,
    @Json(name = "api_token") val apiToken: String,
)

interface TrazaApi {

    /**
     * El código y el token van en cabecera y no en el cuerpo: así el mismo
     * interceptor los pone en todas las llamadas y no hay forma de olvidarse
     * en una.
     */
    @POST("api/v1/ingest/reads")
    suspend fun ingestReads(@Body body: IngestRequest): IngestResponse

    @POST("api/v1/ingest/heartbeat")
    suspend fun heartbeat(@Body body: HeartbeatRequest)

    /**
     * Canje del QR de alta. Es la única llamada sin credenciales, porque
     * sirve para obtenerlas; por eso lleva la URL base explícita, que sale
     * del propio QR y no de la configuración todavía inexistente.
     */
    @POST("api/v1/devices/enroll")
    suspend fun enroll(
        @Header("X-Base-Url") baseUrl: String,
        @Body body: EnrollRequest,
    ): EnrollResponse
}
