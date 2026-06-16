package md.film.tv.data.remote.dto

import com.squareup.moshi.Json
import com.squareup.moshi.JsonClass

/** A catalogue card as returned by publicContentCardData on the API. */
@JsonClass(generateAdapter = true)
data class ContentCardDto(
    @Json(name = "id") val id: String,
    @Json(name = "slug") val slug: String,
    @Json(name = "type") val type: String? = null,
    @Json(name = "type_label") val typeLabel: String? = null,
    @Json(name = "title") val title: String? = null,
    @Json(name = "short_description") val shortDescription: String? = null,
    @Json(name = "release_year") val releaseYear: Int? = null,
    @Json(name = "imdb_rating") val imdbRating: Double? = null,
    @Json(name = "platform_rating") val platformRating: Double? = null,
    @Json(name = "is_free") val isFree: Boolean = false,
    @Json(name = "poster_url") val posterUrl: String? = null,
    @Json(name = "backdrop_url") val backdropUrl: String? = null,
    @Json(name = "lowest_price") val lowestPrice: Double? = null,
    @Json(name = "currency") val currency: String? = null,
    @Json(name = "genres") val genres: List<String> = emptyList(),
)

@JsonClass(generateAdapter = true)
data class HomeSectionDto(
    @Json(name = "id") val id: String,
    @Json(name = "title") val title: String? = null,
    @Json(name = "subtitle") val subtitle: String? = null,
    @Json(name = "items") val items: List<ContentCardDto> = emptyList(),
)

@JsonClass(generateAdapter = true)
data class HomeResponse(
    @Json(name = "locale") val locale: String? = null,
    @Json(name = "hero") val hero: ContentCardDto? = null,
    @Json(name = "sections") val sections: List<HomeSectionDto> = emptyList(),
    @Json(name = "featured") val featured: List<ContentCardDto> = emptyList(),
    @Json(name = "free_to_watch") val freeToWatch: List<ContentCardDto> = emptyList(),
    @Json(name = "latest") val latest: List<ContentCardDto> = emptyList(),
    @Json(name = "movies") val movies: List<ContentCardDto> = emptyList(),
    @Json(name = "series") val series: List<ContentCardDto> = emptyList(),
)

@JsonClass(generateAdapter = true)
data class CatalogResponse(
    @Json(name = "items") val items: List<ContentCardDto> = emptyList(),
    @Json(name = "page") val page: Int = 1,
    @Json(name = "page_size") val pageSize: Int = 0,
    @Json(name = "total") val total: Int = 0,
)

@JsonClass(generateAdapter = true)
data class OfferDto(
    @Json(name = "id") val id: String,
    @Json(name = "name") val name: String? = null,
    @Json(name = "offer_type") val offerType: String? = null,
    @Json(name = "quality") val quality: String? = null,
    @Json(name = "currency") val currency: String? = null,
    @Json(name = "price_amount") val priceAmount: Double? = null,
    @Json(name = "rental_days") val rentalDays: Int? = null,
)

@JsonClass(generateAdapter = true)
data class EpisodeDto(
    @Json(name = "id") val id: String,
    @Json(name = "episode_number") val episodeNumber: Int = 1,
    @Json(name = "title") val title: String? = null,
    @Json(name = "description") val description: String? = null,
    @Json(name = "runtime_minutes") val runtimeMinutes: Int? = null,
    @Json(name = "thumbnail_url") val thumbnailUrl: String? = null,
    @Json(name = "backdrop_url") val backdropUrl: String? = null,
)

@JsonClass(generateAdapter = true)
data class SeasonDto(
    @Json(name = "id") val id: String,
    @Json(name = "season_number") val seasonNumber: Int = 1,
    @Json(name = "title") val title: String? = null,
    @Json(name = "poster_url") val posterUrl: String? = null,
    @Json(name = "episodes") val episodes: List<EpisodeDto> = emptyList(),
)

/** The /public/content/{slug} detail payload (a superset of a card). */
@JsonClass(generateAdapter = true)
data class ContentDetailDto(
    @Json(name = "id") val id: String,
    @Json(name = "slug") val slug: String,
    @Json(name = "type") val type: String? = null,
    @Json(name = "type_label") val typeLabel: String? = null,
    @Json(name = "title") val title: String? = null,
    @Json(name = "original_title") val originalTitle: String? = null,
    @Json(name = "description") val description: String? = null,
    @Json(name = "short_description") val shortDescription: String? = null,
    @Json(name = "tagline") val tagline: String? = null,
    @Json(name = "release_year") val releaseYear: Int? = null,
    @Json(name = "runtime_minutes") val runtimeMinutes: Int? = null,
    @Json(name = "age_rating") val ageRating: String? = null,
    @Json(name = "imdb_rating") val imdbRating: Double? = null,
    @Json(name = "platform_rating") val platformRating: Double? = null,
    @Json(name = "is_free") val isFree: Boolean = false,
    @Json(name = "poster_url") val posterUrl: String? = null,
    @Json(name = "backdrop_url") val backdropUrl: String? = null,
    @Json(name = "hero_desktop_url") val heroDesktopUrl: String? = null,
    @Json(name = "trailer_url") val trailerUrl: String? = null,
    @Json(name = "lowest_price") val lowestPrice: Double? = null,
    @Json(name = "currency") val currency: String? = null,
    @Json(name = "genres") val genres: List<String> = emptyList(),
    @Json(name = "offers") val offers: List<OfferDto> = emptyList(),
    @Json(name = "seasons") val seasons: List<SeasonDto> = emptyList(),
)
