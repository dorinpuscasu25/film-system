package md.film.tv.ui.home

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import md.film.tv.data.FilmotecaRepository
import md.film.tv.data.remote.dto.ContentCardDto
import md.film.tv.data.remote.dto.ContinueWatchingItemDto

data class HomeRow(val title: String, val items: List<ContentCardDto>)

sealed interface HomeUiState {
    data object Loading : HomeUiState
    data class Ready(
        val heroes: List<ContentCardDto>,
        val continueWatching: List<ContinueWatchingItemDto>,
        val rows: List<HomeRow>,
    ) : HomeUiState
    data class Error(val message: String) : HomeUiState
}

class HomeViewModel(private val repository: FilmotecaRepository) : ViewModel() {

    private val _state = MutableStateFlow<HomeUiState>(HomeUiState.Loading)
    val state: StateFlow<HomeUiState> = _state.asStateFlow()

    init {
        load()
    }

    fun load() {
        _state.value = HomeUiState.Loading
        viewModelScope.launch {
            try {
                val home = repository.home()
                val continueWatching = repository.continueWatching()
                val rows = buildList {
                    // Admin-curated carousels first, then sensible fallbacks.
                    home.sections.forEach { section ->
                        if (section.items.isNotEmpty()) {
                            add(HomeRow(section.title ?: "Recomandate", section.items))
                        }
                    }
                    if (home.freeToWatch.isNotEmpty()) add(HomeRow("Gratis de vizionat", home.freeToWatch))
                    if (home.latest.isNotEmpty()) add(HomeRow("Adăugate recent", home.latest))
                    if (home.movies.isNotEmpty()) add(HomeRow("Filme", home.movies))
                    if (home.series.isNotEmpty()) add(HomeRow("Seriale", home.series))
                    if (home.featured.isNotEmpty()) add(HomeRow("Promovate", home.featured))
                }.distinctBy { it.title }

                val heroes = buildList {
                    home.hero?.let { add(it) }
                    addAll(home.featured)
                }.distinctBy { it.slug }.take(6)

                _state.value = HomeUiState.Ready(
                    heroes = heroes,
                    continueWatching = continueWatching,
                    rows = rows,
                )
            } catch (e: Exception) {
                _state.value = HomeUiState.Error(e.message ?: "Nu am putut încărca catalogul.")
            }
        }
    }
}
