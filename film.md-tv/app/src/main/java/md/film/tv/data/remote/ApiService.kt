package md.film.tv.data.remote

import md.film.tv.data.remote.dto.AccountResponse
import md.film.tv.data.remote.dto.CatalogResponse
import md.film.tv.data.remote.dto.ContentDetailDto
import md.film.tv.data.remote.dto.ContinueWatchingResponse
import md.film.tv.data.remote.dto.DeviceCodeRequest
import md.film.tv.data.remote.dto.DeviceCodeResponse
import md.film.tv.data.remote.dto.DeviceTokenRequest
import md.film.tv.data.remote.dto.DeviceTokenResponse
import md.film.tv.data.remote.dto.HomeResponse
import md.film.tv.data.remote.dto.ParentalUnlockRequest
import md.film.tv.data.remote.dto.PlaybackResponse
import md.film.tv.data.remote.dto.ReviewsResponse
import md.film.tv.data.remote.dto.WatchProgressRequest
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query

interface ApiService {

    // --- Device pairing (no auth) ---
    @POST("auth/device/code")
    suspend fun requestDeviceCode(@Body body: DeviceCodeRequest): DeviceCodeResponse

    @POST("auth/device/token")
    suspend fun pollDeviceToken(@Body body: DeviceTokenRequest): Response<DeviceTokenResponse>

    // --- Public catalogue (no auth) ---
    @GET("public/home")
    suspend fun home(@Query("locale") locale: String? = null): HomeResponse

    @GET("public/catalog")
    suspend fun catalog(
        @Query("locale") locale: String? = null,
        @Query("query") query: String? = null,
        @Query("type") type: String? = null,
        @Query("genre") genre: String? = null,
        @Query("access") access: String? = null,
        @Query("page") page: Int = 1,
        @Query("page_size") pageSize: Int = 60,
    ): CatalogResponse

    @GET("public/content/{slug}")
    suspend fun content(
        @Path("slug") slug: String,
        @Query("locale") locale: String? = null,
    ): ContentDetailDto

    @GET("public/content/{slug}/reviews")
    suspend fun reviews(@Path("slug") slug: String): Response<ReviewsResponse>

    // --- Authenticated storefront ---
    @GET("storefront/content/{identifier}/playback")
    suspend fun playback(
        @Path("identifier") identifier: String,
        @Query("locale") locale: String? = null,
        @Query("episode_id") episodeId: String? = null,
    ): Response<PlaybackResponse>

    @GET("storefront/continue-watching")
    suspend fun continueWatching(@Query("locale") locale: String? = null): Response<ContinueWatchingResponse>

    @GET("storefront/account")
    suspend fun account(@Query("locale") locale: String? = null): Response<AccountResponse>

    @POST("storefront/tracking/watch-progress")
    suspend fun watchProgress(@Body body: WatchProgressRequest): Response<Unit>

    @POST("storefront/profiles/{profile}/parental/unlock")
    suspend fun parentalUnlock(
        @Path("profile") profileId: String,
        @Body body: ParentalUnlockRequest,
    ): Response<Unit>

    @POST("auth/logout")
    suspend fun logout(): Response<Unit>
}
