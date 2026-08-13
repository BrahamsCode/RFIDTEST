/*
 * Las versiones de todos los plugins se fijan aquí una sola vez.
 *
 * Declararlas también en `app/build.gradle.kts` rompe la compilación en
 * cuanto el módulo Android entra en juego: Gradle ve el plugin de Kotlin ya
 * en el classpath «con versión desconocida» y se niega a comprobar la
 * compatibilidad.
 */
plugins {
    kotlin("jvm") version "2.0.21" apply false
    kotlin("android") version "2.0.21" apply false
    id("org.jetbrains.kotlin.plugin.compose") version "2.0.21" apply false
    id("com.android.application") version "8.7.3" apply false
    id("com.google.devtools.ksp") version "2.0.21-1.0.28" apply false
    id("com.google.dagger.hilt.android") version "2.52" apply false
}
