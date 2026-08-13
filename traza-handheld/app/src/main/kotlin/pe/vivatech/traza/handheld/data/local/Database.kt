package pe.vivatech.traza.handheld.data.local

import androidx.room.Dao
import androidx.room.Database
import androidx.room.Entity
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.PrimaryKey
import androidx.room.Query
import androidx.room.RoomDatabase

/**
 * Escaneo pendiente de subir.
 *
 * Todo lo que lee el equipo se escribe aquí **antes** de intentar la red.
 * Es lo que permite terminar un ciclo entero en la trastienda sin cobertura
 * y que no se pierda una sola lectura.
 */
@Entity(tableName = "scans")
data class ScanEntity(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val epc: String,
    val tid: String?,
    val rssi: Int,
    val seenAt: Long,
    val cycleId: Long,
    val zoneId: Long?,
    /** `pendiente`, `subido` o `fallido`. */
    val status: String = STATUS_PENDING,
    val failureReason: String? = null,
) {
    companion object {
        const val STATUS_PENDING = "pendiente"
        const val STATUS_UPLOADED = "subido"
        const val STATUS_FAILED = "fallido"
    }
}

/**
 * Tag ya tarado por este equipo.
 *
 * Existe para poder detectar el conflicto de `docs/09` §8 sin red: si hay
 * que preguntarle al servidor en cada prenda, tarar en la trastienda deja de
 * funcionar en cuanto se cae el wifi.
 */
@Entity(tableName = "tags")
data class TagEntity(
    @PrimaryKey val epc: String,
    val tid: String?,
    val productVariantId: Long,
    val locationId: Long,
    val zoneId: Long?,
    val commissionedAt: Long,
    val pendingSync: Boolean = true,
)

@Dao
interface ScanDao {

    @Insert(onConflict = OnConflictStrategy.IGNORE)
    suspend fun insertAll(scans: List<ScanEntity>)

    @Query("SELECT * FROM scans WHERE status = :status ORDER BY id LIMIT :limit")
    suspend fun pending(
        limit: Int = 500,
        status: String = ScanEntity.STATUS_PENDING,
    ): List<ScanEntity>

    @Query("SELECT COUNT(*) FROM scans WHERE status = :status")
    suspend fun pendingCount(status: String = ScanEntity.STATUS_PENDING): Int

    @Query("UPDATE scans SET status = '${ScanEntity.STATUS_UPLOADED}' WHERE id IN (:ids)")
    suspend fun markUploaded(ids: List<Long>)

    /**
     * Los lotes fallidos **se conservan**. Borrarlos destruiría la prueba de
     * un error que probablemente sea nuestro, y quien lo sufre no tiene forma
     * de reproducirlo después.
     */
    @Query("UPDATE scans SET status = '${ScanEntity.STATUS_FAILED}', failureReason = :reason WHERE id IN (:ids)")
    suspend fun markFailed(ids: List<Long>, reason: String)

    /** EPC ya leídos en el ciclo. Es lo que reanuda la sesión tras un cierre. */
    @Query("SELECT epc FROM scans WHERE cycleId = :cycleId")
    suspend fun epcsForCycle(cycleId: Long): List<String>
}

@Dao
interface TagDao {

    @Query("SELECT * FROM tags WHERE epc = :epc LIMIT 1")
    suspend fun findByEpc(epc: String): TagEntity?

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insert(tag: TagEntity)

    @Query("SELECT COUNT(*) FROM tags WHERE pendingSync = 1")
    suspend fun pendingSyncCount(): Int
}

@Database(entities = [ScanEntity::class, TagEntity::class], version = 1, exportSchema = false)
abstract class TrazaDatabase : RoomDatabase() {
    abstract fun scanDao(): ScanDao

    abstract fun tagDao(): TagDao
}
