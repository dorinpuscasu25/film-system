package md.film.tv.ui.library

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import md.film.tv.data.FilmotecaRepository
import md.film.tv.data.remote.dto.ContentCardDto

sealed interface LibraryUiState {
    data object Loading : LibraryUiState
    data class Ready(
        val items: List<ContentCardDto>,
        val userName: String?,
        val walletBalance: Double?,
        val walletCurrency: String?,
    ) : LibraryUiState
    data class Error(val message: String) : LibraryUiState
}

class LibraryViewModel(private val repository: FilmotecaRepository) : ViewModel() {

    private val _state = MutableStateFlow<LibraryUiState>(LibraryUiState.Loading)
    val state: StateFlow<LibraryUiState> = _state.asStateFlow()

    fun load() {
        _state.value = LibraryUiState.Loading
        viewModelScope.launch {
            val account = repository.account()
            if (account == null) {
                _state.value = LibraryUiState.Error("Nu am putut încărca contul.")
                return@launch
            }
            val cards = account.library
                .filter { it.isActive }
                .map { item ->
                    ContentCardDto(
                        id = item.contentId ?: item.contentSlug,
                        slug = item.contentSlug,
                        type = item.contentType,
                        title = item.contentTitle,
                        posterUrl = item.posterUrl,
                    )
                }
            _state.value = LibraryUiState.Ready(
                items = cards,
                userName = account.user?.name,
                walletBalance = account.wallet?.balanceAmount,
                walletCurrency = account.wallet?.currency,
            )
        }
    }
}
