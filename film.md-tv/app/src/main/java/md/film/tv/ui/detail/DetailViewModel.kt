package md.film.tv.ui.detail

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import md.film.tv.data.FilmotecaRepository
import md.film.tv.data.remote.dto.ContentDetailDto
import md.film.tv.data.remote.dto.ReviewDto
import md.film.tv.data.remote.dto.ReviewSummaryDto

sealed interface DetailUiState {
    data object Loading : DetailUiState
    data class Ready(
        val content: ContentDetailDto,
        val reviews: List<ReviewDto> = emptyList(),
        val reviewSummary: ReviewSummaryDto? = null,
    ) : DetailUiState
    data class Error(val message: String) : DetailUiState
}

class DetailViewModel(private val repository: FilmotecaRepository) : ViewModel() {

    private val _state = MutableStateFlow<DetailUiState>(DetailUiState.Loading)
    val state: StateFlow<DetailUiState> = _state.asStateFlow()

    private var loadedSlug: String? = null

    fun load(slug: String) {
        if (loadedSlug == slug && _state.value is DetailUiState.Ready) return
        loadedSlug = slug
        _state.value = DetailUiState.Loading
        viewModelScope.launch {
            try {
                val content = repository.content(slug)
                val reviews = repository.reviews(slug)
                _state.value = DetailUiState.Ready(
                    content = content,
                    reviews = reviews?.items.orEmpty(),
                    reviewSummary = reviews?.summary,
                )
            } catch (e: Exception) {
                _state.value = DetailUiState.Error(e.message ?: "Nu am putut încărca titlul.")
            }
        }
    }
}
