import java.net.URI
import java.util.Properties
import org.jetbrains.kotlin.gradle.dsl.JvmTarget

plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
}

// Push notifications need app/google-services.json from the Firebase console (never committed — see ../README.md).
// Without it the app builds and works normally, just without push notifications.
val hasFirebase = file("google-services.json").exists()
if (hasFirebase) {
    apply(plugin = "com.google.gms.google-services")
}

// The website the app opens: gradle.properties, or -Pgrazex.baseUrl=… for a test server
val baseUrl = (findProperty("grazex.baseUrl") as String? ?: "https://www.grazex.co.za").trim().trimEnd('/')
require(Regex("^https?://[A-Za-z0-9.-]+(:\\d{1,5})?$").matches(baseUrl)) { "grazex.baseUrl must look like https://www.example.com (no path)" }
val siteHost: String = URI(baseUrl).host.lowercase()
val siteHostAlt: String = if (siteHost.startsWith("www.")) siteHost.removePrefix("www.") else "www.$siteHost"

// Release signing: appfiles/android/keystore.properties (never committed — see ../README.md)
val keystoreProps = Properties().apply {
    val f = rootProject.file("keystore.properties")
    if (f.exists()) f.inputStream().use { load(it) }
}

android {
    namespace = "za.co.grazex.app"
    compileSdk = 36

    defaultConfig {
        applicationId = "za.co.grazex.app"
        minSdk = 26
        targetSdk = 36
        versionCode = 1
        versionName = "1.0.0"
        buildConfigField("String", "BASE_URL", "\"$baseUrl\"")
        buildConfigField("boolean", "PUSH_CONFIGURED", hasFirebase.toString())
        // App Links: https links to the site (www and without www) open in the app
        manifestPlaceholders["siteHost"] = siteHost
        manifestPlaceholders["siteHostAlt"] = siteHostAlt
    }

    signingConfigs {
        if (keystoreProps.getProperty("storeFile") != null) {
            create("release") {
                storeFile = rootProject.file(keystoreProps.getProperty("storeFile"))
                storePassword = keystoreProps.getProperty("storePassword")
                keyAlias = keystoreProps.getProperty("keyAlias")
                keyPassword = keystoreProps.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            signingConfigs.findByName("release")?.let { signingConfig = it }
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    buildFeatures {
        buildConfig = true
    }
    testOptions {
        unitTests.isReturnDefaultValues = true
    }
    lint {
        // A WebView app: the site's own pages are the UI
        disable += "SetJavaScriptEnabled"
    }
}

// A release without the signing key can't go to Play: stop with a clear message instead of an unsigned file.
// (-Pgrazex.allowUnsigned=true for a test build of the release variant.)
gradle.taskGraph.whenReady {
    val releasing = allTasks.any { it.project == project && it.name in setOf("bundleRelease", "assembleRelease") }
    if (releasing && android.signingConfigs.findByName("release") == null && findProperty("grazex.allowUnsigned") != "true") {
        throw GradleException("Release builds need appfiles/android/keystore.properties — see appfiles/README.md, step 2.")
    }
}

kotlin {
    compilerOptions {
        jvmTarget.set(JvmTarget.JVM_17)
    }
}

dependencies {
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.appcompat)
    implementation(libs.androidx.activity)
    implementation(libs.androidx.webkit)
    implementation(libs.androidx.swiperefreshlayout)
    implementation(platform(libs.firebase.bom))
    implementation(libs.firebase.messaging)

    testImplementation(libs.junit)
}
