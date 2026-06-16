package md.film.tv.data.remote.dto

import com.squareup.moshi.Json
import com.squareup.moshi.JsonClass

@JsonClass(generateAdapter = true)
data class ReviewsResponse(
    @Json(name = "items") val items: List<ReviewDto> = emptyList(),
    @Json(name = "summary") val summary: ReviewSummaryDto? = null,
)

@JsonClass(generateAdapter = true)
data class ReviewDto(
    @Json(name = "id") val id: Long? = null,
    @Json(name = "user_name") val userName: String? = null,
    @Json(name = "user_avatar") val userAvatar: String? = null,
    @Json(name = "rating") val rating: Int = 0,
    @Json(name = "comment") val comment: String? = null,
    @Json(name = "created_at") val createdAt: String? = null,
)

@JsonClass(generateAdapter = true)
data class ReviewSummaryDto(
    @Json(name = "count") val count: Int = 0,
    @Json(name = "average_rating") val averageRating: Double = 0.0,
)

/** Body for POST /storefront/tracking/watch-progress. */
@JsonClass(generateAdapter = true)
data class WatchProgressRequest(
    @Json(name = "session_token") val sessionToken: String,
    @Json(name = "content_id") val contentId: Long,
    @Json(name = "content_format_id") val contentFormatId: Int? = null,
    @Json(name = "episode_id") val episodeId: String? = null,
    @Json(name = "position_seconds") val positionSeconds: Int = 0,
    @Json(name = "duration_seconds") val durationSeconds: Int = 0,
    @Json(name = "watch_time_seconds") val watchTimeSeconds: Int = 0,
    @Json(name = "event_type") val eventType: String,
)

@JsonClass(generateAdapter = true)
data class ParentalUnlockRequest(
    @Json(name = "pin") val pin: String,
)
