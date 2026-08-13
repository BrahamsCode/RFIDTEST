package pe.vivatech.traza.core.domain

/**
 * Contenido del QR de alta. Ver `docs/09` §10.
 *
 * ```json
 * {"v":1,"url":"https://traza.ejemplo.pe","device_code":"HH-LIM01-02",
 *  "enrollment_token":"...","location_id":1}
 * ```
 */
data class EnrollmentPayload(
    val version: Int,
    val url: String,
    val deviceCode: String,
    val enrollmentToken: String,
    val locationId: Long,
)

sealed interface EnrollmentParse {
    data class Ok(val payload: EnrollmentPayload) : EnrollmentParse

    /** El mensaje va a la pantalla tal cual: quien escanea está de pie en la tienda. */
    data class Invalid(val reason: String) : EnrollmentParse
}

object Enrollment {

    const val SUPPORTED_VERSION = 1

    /**
     * Valida los campos ya extraídos del JSON. El parseo del JSON lo hace la
     * capa de Android, que tiene librería para eso; aquí queda la parte que
     * decide, y que por tanto conviene poder probar sin emulador.
     *
     * La URL se exige **https** salvo en `localhost`: el token permanente
     * viaja en esa respuesta, y aceptar http significaría entregarlo a
     * cualquiera que esté en el wifi de la galería.
     */
    fun validate(
        version: Int?,
        url: String?,
        deviceCode: String?,
        enrollmentToken: String?,
        locationId: Long?,
    ): EnrollmentParse {
        if (version == null || version != SUPPORTED_VERSION) {
            return EnrollmentParse.Invalid(
                "Este QR es de otra versión de TRAZA (v$version). Actualiza la aplicación.",
            )
        }

        if (url.isNullOrBlank()) {
            return EnrollmentParse.Invalid("El QR no trae la dirección del servidor.")
        }

        if (!isSecure(url)) {
            return EnrollmentParse.Invalid("La dirección del servidor debe ser https.")
        }

        if (deviceCode.isNullOrBlank()) {
            return EnrollmentParse.Invalid("El QR no trae el código del dispositivo.")
        }

        if (enrollmentToken.isNullOrBlank()) {
            return EnrollmentParse.Invalid("El QR no trae el token de alta.")
        }

        if (locationId == null || locationId <= 0) {
            return EnrollmentParse.Invalid("El QR no trae la tienda.")
        }

        return EnrollmentParse.Ok(
            EnrollmentPayload(
                version = version,
                url = url.trimEnd('/'),
                deviceCode = deviceCode.trim(),
                enrollmentToken = enrollmentToken.trim(),
                locationId = locationId,
            ),
        )
    }

    private fun isSecure(url: String): Boolean {
        if (url.startsWith("https://")) return true

        // Se admite http contra la máquina de desarrollo y nada más.
        val local = url.removePrefix("http://").substringBefore('/').substringBefore(':')
        return url.startsWith("http://") && (local == "localhost" || local == "127.0.0.1")
    }
}
