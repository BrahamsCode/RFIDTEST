package pe.vivatech.traza.handheld.di

import android.content.Context
import androidx.room.Room
import com.squareup.moshi.Moshi
import com.squareup.moshi.kotlin.reflect.KotlinJsonAdapterFactory
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.android.qualifiers.ApplicationContext
import dagger.hilt.components.SingletonComponent
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.SupervisorJob
import okhttp3.OkHttpClient
import pe.vivatech.traza.core.reader.FakeRfidReader
import pe.vivatech.traza.core.reader.RfidReader
import pe.vivatech.traza.handheld.BuildConfig
import pe.vivatech.traza.handheld.data.local.ScanDao
import pe.vivatech.traza.handheld.data.local.TagDao
import pe.vivatech.traza.handheld.data.local.TrazaDatabase
import pe.vivatech.traza.handheld.data.remote.DeviceCredentials
import pe.vivatech.traza.handheld.data.remote.TrazaApi
import retrofit2.Retrofit
import retrofit2.converter.moshi.MoshiConverterFactory
import javax.inject.Singleton

@Module
@InstallIn(SingletonComponent::class)
object AppModule {

    @Provides
    @Singleton
    fun database(@ApplicationContext context: Context): TrazaDatabase =
        Room.databaseBuilder(context, TrazaDatabase::class.java, "traza.db").build()

    @Provides
    fun scanDao(db: TrazaDatabase): ScanDao = db.scanDao()

    @Provides
    fun tagDao(db: TrazaDatabase): TagDao = db.tagDao()

    @Provides
    @Singleton
    fun okHttp(credentials: DeviceCredentials): OkHttpClient = OkHttpClient.Builder()
        .addInterceptor { chain ->
            // Las credenciales van aquí y no en cada llamada: así no hay
            // forma de olvidarse en una, y al canjear el QR el token nuevo
            // se aplica sin reconstruir el cliente.
            val request = chain.request().newBuilder().apply {
                credentials.deviceCode?.let { header("X-Device-Code", it) }
                credentials.apiToken?.let { header("X-Device-Token", it) }
                header("Accept", "application/json")
            }.build()

            chain.proceed(request)
        }
        .build()

    @Provides
    @Singleton
    fun api(client: OkHttpClient, credentials: DeviceCredentials): TrazaApi {
        val moshi = Moshi.Builder().add(KotlinJsonAdapterFactory()).build()

        return Retrofit.Builder()
            // Antes del alta no hay servidor todavía; se pone un marcador
            // válido para que Retrofit se construya, y el alta lo sustituye.
            .baseUrl((credentials.baseUrl ?: "https://sin-configurar.invalid").trimEnd('/') + "/")
            .client(client)
            .addConverterFactory(MoshiConverterFactory.create(moshi))
            .build()
            .create(TrazaApi::class.java)
    }

    /**
     * Sin SDK del fabricante se usa el lector falso, que es un entregable de
     * primera clase: permite desarrollar y probar los cinco modos sin
     * hardware. El real llega en la tarea 5.7, cuando se sepa qué equipo se
     * compra (tarea 0.1).
     */
    @Provides
    @Singleton
    fun reader(): RfidReader = FakeRfidReader(
        scope = CoroutineScope(SupervisorJob()),
        populationSize = if (BuildConfig.DEBUG) 500 else 50,
    )
}
