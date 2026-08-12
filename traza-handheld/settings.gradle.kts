pluginManagement {
    repositories {
        google()
        mavenCentral()
        gradlePluginPortal()
    }
}

dependencyResolutionManagement {
    repositories {
        google()
        mavenCentral()
    }
}

rootProject.name = "traza-handheld"

// core:reader es Kotlin JVM puro y sin dependencias de Android: así la
// abstracción del lector se compila y se prueba sin SDK ni hardware.
include(":core:reader")

// El módulo Android solo entra en la compilación si hay SDK. Sin esto, la
// etapa de pruebas de CI no podría ejecutar core:reader.
val androidSdk = System.getenv("ANDROID_HOME")
    ?: System.getenv("ANDROID_SDK_ROOT")
    ?: file("local.properties")
        .takeIf { it.exists() }
        ?.readLines()
        ?.firstOrNull { it.startsWith("sdk.dir=") }
        ?.substringAfter("=")

if (androidSdk != null) {
    include(":app")
} else {
    logger.lifecycle("ANDROID_HOME no definido: se omite el módulo :app y solo se compila :core:reader.")
}
