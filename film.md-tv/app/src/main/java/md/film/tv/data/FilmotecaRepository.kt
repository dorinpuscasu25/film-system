package md.film.tv.data

import kotlinx.coroutines.flow.first
import md.film.tv.data.remote.ApiService
import md.film.tv.data.remote.TokenHolder
import md.film.tv.data.remote.dto.AccountResponse
import md.film.tv.data.remote.dto.ContentCardDto
import md.film.tv.data.remote.dto.ContentDetailDto
import md.film.tv.data.remote.dto.ContinueWatchingItemDto
import md.film.tv.data.remote.dto.DeviceCodeRequest
import md.film.tv.data.remote.dto.DeviceCodeResponse
import md.film.tv.data.remote.dto.DeviceTokenRequest
import md.film.tv.data.remote.dto.HomeResponse
import md.film.tv.data.remote.dto.ParentalUnlockRequest
import md.film.tv.data.remote.dto.PlaybackResponse
import md.film.tv.data.remote.dto.ReviewsResponse
import md.film.tv.data.remote.dto.WatchProgressRequest

/** Outcome of one device-token poll, mapped from RFC 8628 semantics. */
sealed interface PollResult {
    data class Authorized(val token: String, val userName: String?) : PollResult
    data object Pending : PollResult
    data object SlowDown : PollResult
    data object Expired : PollResult
    data object Denied : PollResult
    data class Error(val message: String) : PollResult
}

/** Outcome of requesting playback for a title. */
sealed interface PlaybackResult {
    data class Ready(val data: PlaybackResponse) : PlaybackResult
    /** The user is logged in but has not purchased / has no active access. */
    data object NoAccess : PlaybackResult
    data object Unauthorized : PlaybackResult
    data class Error(val message: String) : PlaybackResult
}

class FilmotecaRepository(
    private val api: ApiService,
    private val tokenStore: TokenStore,
    private val profileStore: ProfileStore,
) {
    val activeProfileName get() = profileStore.activeProfileName
    val activeProfileId get() = profileStore.activeProfileId

    suspend fun setActiveProfile(id: String, name: String) = profileStore.setActive(id, name)
    suspend fun requestDeviceCode(deviceName: String): DeviceCodeResponse =
        api.requestDeviceCode(DeviceCodeRequest(deviceName))

    suspend fun pollDeviceToken(deviceCode: String): PollResult {
        val response = api.pollDeviceToken(DeviceTokenRequest(deviceCode))
        val body = response.body()

        return when {
            response.isSuccessful && body?.token != null ->
                PollResult.Authorized(body.token, body.user?.name)

            // 202 Accepted — still waiting (or polling too fast).
            response.code() == 202 ->
                if (body?.error == "slow_down") PollResult.SlowDown else PollResult.Pending

            response.code() == 410 -> PollResult.Expired
            response.code() == 403 -> PollResult.Denied
            else -> PollResult.Error(body?.message ?: "Conectarea a eșuat (${response.code()}).")
        }
    }

    /** Persist the token and make it active for all subsequent requests. */
    suspend fun persistSession(token: String, userName: String?) {
        TokenHolder.token = token
        tokenStore.save(token, userName)
    }

    /** Load any saved token at app start so the user stays signed in. */
    suspend fun restoreSession(): Boolean {
        val saved = tokenStore.tokenFlow.first()
        TokenHolder.token = saved
        return saved != null
    }

    suspend fun signOut() {
        runCatching { api.logout() }
        TokenHolder.token = null
        tokenStore.clear()
        profileStore.clear()
    }

    suspend fun home(locale: String? = null): HomeResponse = api.home(locale)

    suspend fun catalog(
        query: String? = null,
        type: String? = null,
        genre: String? = null,
        access: String? = null,
    ): List<ContentCardDto> =
        api.catalog(query = query, type = type, genre = genre, access = access).items

    /** Returns the continue-watching rail, or an empty list if unavailable. */
    suspend fun continueWatching(): List<ContinueWatchingItemDto> =
        runCatching { api.continueWatching().body()?.items ?: emptyList() }.getOrDefault(emptyList())

    suspend fun account(): AccountResponse? =
        runCatching { api.account().body() }.getOrNull()

    suspend fun reviews(slug: String): ReviewsResponse? =
        runCatching { api.reviews(slug).body() }.getOrNull()

    /** Best-effort progress ping; failures never interrupt playback. */
    suspend fun reportProgress(request: WatchProgressRequest) {
        runCatching { api.watchProgress(request) }
    }

    /** Returns true if the PIN unlocked the profile. */
    suspend fun unlockParental(profileId: String, pin: String): Boolean =
        runCatching { api.parentalUnlock(profileId, ParentalUnlockRequest(pin)).isSuccessful }
            .getOrDefault(false)

    suspend fun content(slug: String, locale: String? = null): ContentDetailDto =
        api.content(slug, locale)

    suspend fun playback(identifier: String, episodeId: String? = null): PlaybackResult {
        val response = api.playback(identifier, episodeId = episodeId)
        return when {
            response.isSuccessful && response.body() != null ->
                PlaybackResult.Ready(response.body()!!)
            response.code() == 403 -> PlaybackResult.NoAccess
            response.code() == 401 -> PlaybackResult.Unauthorized
            else -> PlaybackResult.Error("Redarea nu este disponibilă (${response.code()}).")
        }
    }
}
