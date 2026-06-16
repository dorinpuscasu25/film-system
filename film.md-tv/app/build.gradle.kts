import java.util.Properties

plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
    alias(libs.plugins.kotlin.compose)
    alias(libs.plugins.ksp)
}

// Bunny movies-library access key, read from local.properties (git-ignored) so
// the secret never lands in source control. Add: bunnyAccessKey=XXXX
val bunnyAccessKey: String = Properties().apply {
    val f = rootProject.file("local.properties")
    if (f.exists()) f.inputStream().use { load(it) }
}.getProperty("bunnyAccessKey", "").trim()

android {
    namespace = "md.film.tv"
    compileSdk = 35

    defaultConfig {
        applicationId = "md.film.tv"
        minSdk = 26 // required by the Bunny Stream player SDK
        targetSdk = 34
        versionCode = 1
        versionName = "1.0.0"
    }

    buildTypes {
        debug {
            buildConfigField("String", "API_BASE_URL", "\"https://filmmd-api.veezify.com/api/v1/\"")
            buildConfigField("String", "WEB_BASE_URL", "\"https://filmoteca.md\"")
            // Optional Bunny Stream library read API key (Library → API). Leave empty
            // to play via the embed/referrer path; fill in if metadata fetch needs auth.
            buildConfigField("String", "BUNNY_ACCESS_KEY", "\"$bunnyAccessKey\"")
        }
        release {
            isMinifyEnabled = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro",
            )
            buildConfigField("String", "API_BASE_URL", "\"https://filmmd-api.veezify.com/api/v1/\"")
            buildConfigField("String", "WEB_BASE_URL", "\"https://filmoteca.md\"")
            buildConfigField("String", "BUNNY_ACCESS_KEY", "\"$bunnyAccessKey\"")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = "17"
    }

    buildFeatures {
        compose = true
        buildConfig = true
    }
}

dependencies {
    implementation(libs.androidx.core.ktx)
    // Bunny player inflates a Cast (MediaRouteButton) which needs an AppCompat theme.
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation(libs.androidx.lifecycle.runtime.ktx)
    implementation(libs.androidx.lifecycle.runtime.compose)
    implementation(libs.androidx.lifecycle.viewmodel.compose)
    implementation(libs.androidx.activity.compose)

    implementation(platform(libs.androidx.compose.bom))
    implementation(libs.androidx.compose.ui)
    implementation(libs.androidx.compose.ui.graphics)
    implementation(libs.androidx.compose.ui.tooling.preview)
    implementation(libs.androidx.compose.foundation)
    implementation(libs.androidx.compose.material.icons)
    debugImplementation(libs.androidx.compose.ui.tooling)

    implementation(libs.androidx.tv.material)
    implementation(libs.androidx.navigation.compose)

    // Native HLS + Widevine playback (the Bunny content is Widevine SAMPLE-AES,
    // L3-allowed). We resolve the playlist via Bunny's /play endpoint and play it
    // with ExoPlayer, sending a Referer header so Bunny authorizes it.
    implementation(libs.androidx.media3.exoplayer)
    implementation(libs.androidx.media3.exoplayer.hls)
    implementation(libs.androidx.media3.ui)
    implementation(libs.androidx.media3.common)

    implementation(libs.retrofit)
    implementation(libs.retrofit.moshi)
    implementation(libs.okhttp.logging)
    implementation(libs.moshi)
    ksp(libs.moshi.kotlin.codegen)

    implementation(libs.androidx.datastore.preferences)
    implementation(libs.coil.compose)
    implementation(libs.kotlinx.coroutines.android)
    implementation(libs.zxing.core)
}
