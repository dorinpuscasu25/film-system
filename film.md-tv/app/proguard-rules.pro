# Retrofit / OkHttp / Moshi
-keepattributes Signature, InnerClasses, EnclosingMethod, RuntimeVisibleAnnotations
-keepclassmembers,allowshrinking,allowobfuscation interface * {
    @retrofit2.http.* <methods>;
}
-dontwarn org.codehaus.mojo.animal_sniffer.IgnoreJRERequirement
-dontwarn javax.annotation.**
-dontwarn kotlin.Unit

# Moshi generated adapters + our DTOs are reflection-friendly
-keep class md.film.tv.data.remote.dto.** { *; }
-keep class **JsonAdapter { *; }
-keepnames @com.squareup.moshi.JsonClass class *

# Media3
-dontwarn androidx.media3.**
