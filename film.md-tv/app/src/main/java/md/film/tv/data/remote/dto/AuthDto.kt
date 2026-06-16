package md.film.tv.data.remote.dto

import com.squareup.moshi.Json
import com.squareup.moshi.JsonClass

@JsonClass(generateAdapter = true)
data class DeviceCodeResponse(
    @Json(name = "device_code") val deviceCode: String,
    @Json(name = "user_code") val userCode: String,
    @Json(name = "verification_uri") val verificationUri: String,
    @Json(name = "verification_uri_complete") val verificationUriComplete: String,
    @Json(name = "expires_in") val expiresIn: Int,
    @Json(name = "interval") val interval: Int,
)

@JsonClass(generateAdapter = true)
data class DeviceCodeRequest(
    @Json(name = "device_name") val deviceName: String,
)

@JsonClass(generateAdapter = true)
data class DeviceTokenRequest(
    @Json(name = "device_code") val deviceCode: String,
)

/**
 * Single shape covering every poll outcome. On 200 [token]/[user] are set; on
 * 202/4xx [error] carries the RFC 8628 code (authorization_pending, slow_down,
 * expired_token, access_denied, ...).
 */
@JsonClass(generateAdapter = true)
data class DeviceTokenResponse(
    @Json(name = "token") val token: String? = null,
    @Json(name = "user") val user: UserDto? = null,
    @Json(name = "error") val error: String? = null,
    @Json(name = "message") val message: String? = null,
)

@JsonClass(generateAdapter = true)
data class UserDto(
    @Json(name = "id") val id: Long,
    @Json(name = "name") val name: String? = null,
    @Json(name = "email") val email: String? = null,
    @Json(name = "avatar_url") val avatarUrl: String? = null,
)
