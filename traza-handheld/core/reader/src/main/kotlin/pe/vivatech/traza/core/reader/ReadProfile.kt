package pe.vivatech.traza.core.reader

data class ReadProfile(
    val session: Int,
    val target: String,
    val initialQ: Int,
    val txPowerDbm: Int,
    val dedupWindowMs: Long,
    val minReadCount: Int,
    val rssiThreshold: Int? = null,
    val readTid: Boolean = false,
) {
    init {
        require(session in 0..3) { "La sesión Gen2 debe estar entre S0 y S3, no $session." }
        require(target == "A" || target == "B") { "El target debe ser A o B, no $target." }
    }

    companion object {
        /** S2 evita releer el mismo tag en la misma pasada del handheld. */
        val INVENTARIO = ReadProfile(
            session = 2,
            target = "A",
            initialQ = 7,
            txPowerDbm = 30,
            dedupWindowMs = 2000,
            minReadCount = 1,
        )

        /**
         * Potencia deliberadamente baja: en tarado hay que leer solo el tag
         * que se tiene delante, no la caja entera de la trastienda.
         */
        val TARADO = ReadProfile(
            session = 1,
            target = "A",
            initialQ = 2,
            txPowerDbm = 15,
            dedupWindowMs = 500,
            minReadCount = 2,
            rssiThreshold = -50,
            readTid = true,
        )

        /** Búsqueda tipo Geiger: potencia media y ventana corta para reaccionar rápido. */
        val BUSQUEDA = ReadProfile(
            session = 0,
            target = "A",
            initialQ = 2,
            txPowerDbm = 25,
            dedupWindowMs = 100,
            minReadCount = 1,
        )
    }
}
