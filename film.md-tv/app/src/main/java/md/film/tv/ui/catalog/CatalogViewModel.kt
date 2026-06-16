package md.film.tv.ui.catalog

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import md.film.tv.data.FilmotecaRepository
import md.film.tv.data.remote.dto.ContentCardDto

sealed interface CatalogUiState {
    data object Loading : CatalogUiState
    data class Ready(val items: List<ContentCardDto>) : CatalogUiState
    data class Error(val message: String) : CatalogUiState
}

class CatalogViewModel(private val repository: FilmotecaRepository) : ViewModel() {

    private val _state = MutableStateFlow<CatalogUiState>(CatalogUiState.Loading)
    val state: StateFlow<CatalogUiState> = _state.asStateFlow()

    private var searchJob: Job? = null
    private var lastKey: String? = null

    /** Load a fixed catalogue (e.g. all movies/series). Skips redundant reloads. */
    fun loadByType(type: String?) {
        val key = "type:$type"
        if (key == lastKey && _state.value is CatalogUiState.Ready) return
        lastKey = key
        run(query = null, type = type)
    }

    /** Debounced free-text search across the catalogue. */
    fun search(query: String) {
        lastKey = "search:$query"
        searchJob?.cancel()
        if (query.isBlank()) {
            run(query = null, type = null)
            return
        }
        searchJob = viewModelScope.launch {
            delay(350)
            run(query = query, type = null)
        }
    }

    private fun run(query: String?, type: String?) {
        _state.value = CatalogUiState.Loading
        viewModelScope.launch {
            try {
                _state.value = CatalogUiState.Ready(repository.catalog(query = query, type = type))
            } catch (e: Exception) {
                _state.value = CatalogUiState.Error(e.message ?: "Nu am putut încărca catalogul.")
            }
        }
    }
}
