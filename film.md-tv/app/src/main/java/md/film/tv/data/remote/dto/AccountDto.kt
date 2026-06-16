package md.film.tv.data.remote.dto

import com.squareup.moshi.Json
import com.squareup.moshi.JsonClass

@JsonClass(generateAdapter = true)
data class ContinueWatchingResponse(
    @Json(name = "items") val items: List<ContinueWatchingItemDto> = emptyList(),
)

@JsonClass(generateAdapter = true)
data class ContinueWatchingItemDto(
    @Json(name = "content_id") val contentId: String? = null,
    @Json(name = "content_slug") val contentSlug: String,
    @Json(name = "title") val title: String? = null,
    @Json(name = "poster_url") val posterUrl: String? = null,
    @Json(name = "position_seconds") val positionSeconds: Double = 0.0,
    @Json(name = "duration_seconds") val durationSeconds: Double = 0.0,
    @Json(name = "progress_percent") val progressPercent: Double = 0.0,
)

@JsonClass(generateAdapter = true)
data class AccountResponse(
    @Json(name = "user") val user: AccountUserDto? = null,
    @Json(name = "wallet") val wallet: WalletDto? = null,
    @Json(name = "library") val library: List<LibraryItemDto> = emptyList(),
)

@JsonClass(generateAdapter = true)
data class AccountUserDto(
    @Json(name = "id") val id: Long? = null,
    @Json(name = "name") val name: String? = null,
    @Json(name = "email") val email: String? = null,
    @Json(name = "profiles") val profiles: List<ProfileDto> = emptyList(),
)

@JsonClass(generateAdapter = true)
data class ProfileDto(
    @Json(name = "id") val id: String,
    @Json(name = "name") val name: String,
    @Json(name = "avatar_label") val avatarLabel: String? = null,
    @Json(name = "avatar_color") val avatarColor: String? = null,
    @Json(name = "is_kids") val isKids: Boolean = false,
    @Json(name = "is_default") val isDefault: Boolean = false,
    @Json(name = "has_pin") val hasPin: Boolean = false,
    @Json(name = "max_age_rating") val maxAgeRating: String? = null,
)

@JsonClass(generateAdapter = true)
data class WalletDto(
    @Json(name = "currency") val currency: String? = null,
    @Json(name = "balance_amount") val balanceAmount: Double = 0.0,
)

@JsonClass(generateAdapter = true)
data class LibraryItemDto(
    @Json(name = "content_id") val contentId: String? = null,
    @Json(name = "content_slug") val contentSlug: String,
    @Json(name = "content_title") val contentTitle: String? = null,
    @Json(name = "content_type") val contentType: String? = null,
    @Json(name = "poster_url") val posterUrl: String? = null,
    @Json(name = "access_type") val accessType: String? = null,
    @Json(name = "status") val status: String? = null,
    @Json(name = "is_active") val isActive: Boolean = false,
    @Json(name = "expires_at") val expiresAt: String? = null,
)
