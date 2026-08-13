package pe.vivatech.traza.core.domain

/** Resultado de intentar subir un lote, tal como lo ve el trabajador. */
sealed interface UploadAttempt {
    data object Success : UploadAttempt

    /** Sin red, DNS caído, socket cortado. */
    data class Network(val message: String) : UploadAttempt

    data class Http(val code: Int, val message: String = "") : UploadAttempt
}

/**
 * Qué hacer con el lote. Ver `docs/09` §6.
 *
 * El reparto no es cosmético: reintentar indefinidamente un lote que el
 * servidor nunca va a aceptar deja al equipo dando vueltas y la cola no baja
 * jamás, mientras el operario ve «12 340 pendientes» sin entender por qué.
 */
sealed interface UploadDecision {
    /** Lote aceptado: se marca subido. */
    data object MarkUploaded : UploadDecision

    /** Se conserva y se reintenta con retroceso exponencial. */
    data class Retry(val reason: String) : UploadDecision

    /**
     * Problema de credenciales. No es culpa del lote: hay que avisar a la
     * persona, porque nada va a subir hasta que alguien reautentique el
     * equipo.
     */
    data class AuthProblem(val reason: String) : UploadDecision

    /**
     * El lote es inválido y reintentarlo no lo arregla. Se marca fallido y
     * **se conserva** para diagnóstico: borrarlo destruiría la prueba de un
     * error que probablemente sea nuestro.
     */
    data class MarkFailed(val reason: String) : UploadDecision
}

object UploadPolicy {

    /** Retroceso exponencial de 15 s, con techo para que no se duerma media hora. */
    const val INITIAL_BACKOFF_SECONDS: Long = 15
    const val MAX_BACKOFF_SECONDS: Long = 900

    fun decide(attempt: UploadAttempt): UploadDecision = when (attempt) {
        is UploadAttempt.Success -> UploadDecision.MarkUploaded

        is UploadAttempt.Network -> UploadDecision.Retry(attempt.message)

        is UploadAttempt.Http -> when (attempt.code) {
            in 200..299 -> UploadDecision.MarkUploaded

            // 408 y 429 son transitorios por definición: el servidor está
            // pidiendo explícitamente que se vuelva más tarde.
            408, 425, 429 -> UploadDecision.Retry("El servidor pide reintentar (${attempt.code}).")

            401, 403 -> UploadDecision.AuthProblem(
                "El dispositivo no está autorizado (${attempt.code}). Hay que volver a darlo de alta.",
            )

            in 500..599 -> UploadDecision.Retry("Error del servidor (${attempt.code}).")

            else -> UploadDecision.MarkFailed(
                "El servidor rechazó el lote (${attempt.code})${
                    if (attempt.message.isBlank()) "" else ": ${attempt.message}"
                }",
            )
        }
    }

    /**
     * Espera antes del siguiente intento. `attempt` empieza en 1.
     *
     * Sin techo, ocho fallos seguidos —una mañana entera sin cobertura en la
     * trastienda— dejarían el siguiente intento a más de una hora vista, y
     * el operario habría terminado el ciclo mucho antes.
     */
    fun backoffSeconds(attempt: Int): Long {
        require(attempt >= 1) { "El número de intento empieza en 1, no en $attempt." }

        val exponent = (attempt - 1).coerceAtMost(20)
        val delay = INITIAL_BACKOFF_SECONDS shl exponent

        return if (delay <= 0 || delay > MAX_BACKOFF_SECONDS) MAX_BACKOFF_SECONDS else delay
    }
}
