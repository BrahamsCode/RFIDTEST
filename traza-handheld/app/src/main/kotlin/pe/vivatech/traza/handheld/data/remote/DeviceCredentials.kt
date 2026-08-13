package pe.vivatech.traza.handheld.data.remote

import android.content.Context
import dagger.hilt.android.qualifiers.ApplicationContext
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Credenciales del equipo. Ver `docs/09` §10.
 *
 * En `EncryptedSharedPreferences` y no en uno normal: un handheld se pierde
 * o se lo llevan, y en claro el token de ingesta se lee con un cable y
 * `adb backup`. Se puede revocar desde la web, pero eso exige que alguien se
 * dé cuenta de que ha desaparecido.
 */
@Singleton
class DeviceCredentials @Inject constructor(@ApplicationContext context: Context) {

    private val prefs = EncryptedSharedPreferences.create(
        context,
        "traza-device",
        MasterKey.Builder(context).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build(),
        EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
        EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
    )

    val isEnrolled: Boolean get() = deviceCode != null && apiToken != null

    var baseUrl: String?
        get() = prefs.getString(KEY_URL, null)
        set(value) = prefs.edit().putString(KEY_URL, value).apply()

    var deviceCode: String?
        get() = prefs.getString(KEY_CODE, null)
        set(value) = prefs.edit().putString(KEY_CODE, value).apply()

    var apiToken: String?
        get() = prefs.getString(KEY_TOKEN, null)
        set(value) = prefs.edit().putString(KEY_TOKEN, value).apply()

    var locationId: Long
        get() = prefs.getLong(KEY_LOCATION, 0)
        set(value) = prefs.edit().putLong(KEY_LOCATION, value).apply()

    fun save(baseUrl: String, deviceCode: String, apiToken: String, locationId: Long) {
        prefs.edit()
            .putString(KEY_URL, baseUrl)
            .putString(KEY_CODE, deviceCode)
            .putString(KEY_TOKEN, apiToken)
            .putLong(KEY_LOCATION, locationId)
            .apply()
    }

    /** Baja del equipo. Se usa al reasignarlo a otra tienda. */
    fun clear() {
        prefs.edit().clear().apply()
    }

    private companion object {
        const val KEY_URL = "base_url"
        const val KEY_CODE = "device_code"
        const val KEY_TOKEN = "api_token"
        const val KEY_LOCATION = "location_id"
    }
}
