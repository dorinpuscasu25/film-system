package md.film.tv.data.remote

import com.squareup.moshi.Moshi
import okhttp3.Interceptor
import okhttp3.OkHttpClient
import okhttp3.Response
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.moshi.MoshiConverterFactory
import java.util.concurrent.TimeUnit

/**
 * Holds the bearer token in memory for the OkHttp interceptor. The repository
 * keeps this in sync with the persisted [md.film.tv.data.TokenStore].
 */
object TokenHolder {
    @Volatile
    var token: String? = null
}

/** Attaches Accept + Authorization headers to every request. */
private class AuthInterceptor : Interceptor {
    override fun intercept(chain: Interceptor.Chain): Response {
        val builder = chain.request().newBuilder()
            .header("Accept", "application/json")
        TokenHolder.token?.let { builder.header("Authorization", "Bearer $it") }
        return chain.proceed(builder.build())
    }
}

object Network {

    fun create(baseUrl: String, debug: Boolean): ApiService {
        val moshi = Moshi.Builder().build()

        val logging = HttpLoggingInterceptor().apply {
            level = if (debug) HttpLoggingInterceptor.Level.BASIC else HttpLoggingInterceptor.Level.NONE
        }

        val client = OkHttpClient.Builder()
            .addInterceptor(AuthInterceptor())
            .addInterceptor(logging)
            .connectTimeout(15, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .build()

        return Retrofit.Builder()
            .baseUrl(baseUrl)
            .client(client)
            .addConverterFactory(MoshiConverterFactory.create(moshi))
            .build()
            .create(ApiService::class.java)
    }
}
