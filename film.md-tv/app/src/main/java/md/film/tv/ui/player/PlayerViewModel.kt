package md.film.tv.ui.player

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import md.film.tv.data.FilmotecaRepository
import md.film.tv.data.PlaybackResult
import md.film.tv.data.remote.dto.PlaybackResponse
import md.film.tv.data.remote.dto.WatchProgressRequest

sealed interface PlayerUiState {
    data object Loading : PlayerUiState
    data class Ready(val data: PlaybackResponse) : PlayerUiState
    /** Logged in but no active entitlement — show the "buy on phone/PC" screen. */
    data object NoAccess : PlayerUiState
    data object NeedsReauth : PlayerUiState
    data class Error(val message: String) : PlayerUiState
}

class PlayerViewModel(private val repository: FilmotecaRepository) : ViewModel() {

    private val _state = MutableStateFlow<PlayerUiState>(PlayerUiState.Loading)
    val state: StateFlow<PlayerUiState> = _state.asStateFlow()

    private var slug: String? = null
    private var episodeId: String? = null

    fun load(slug: String, episodeId: String? = null) {
        this.slug = slug
        this.episodeId = episodeId
        _state.value = PlayerUiState.Loading
        viewModelScope.launch {
            _state.value = when (val result = repository.playback(slug, episodeId)) {
                is PlaybackResult.Ready -> PlayerUiState.Ready(result.data)
                PlaybackResult.NoAccess -> PlayerUiState.NoAccess
                PlaybackResult.Unauthorized -> PlayerUiState.NeedsReauth
                is PlaybackResult.Error -> PlayerUiState.Error(result.message)
            }
        }
    }

    /** Used by the "Verifică din nou" button after the user pays on another device. */
    fun refresh() {
        slug?.let { load(it, episodeId) }
    }

    /**
     * Report playback progress so the server's continue-watching rail and view
     * counts stay in sync with what's watched on the TV. Best-effort.
     */
    fun reportProgress(positionSeconds: Int, durationSeconds: Int, eventType: String) {
        val data = (state.value as? PlayerUiState.Ready)?.data ?: return
        val contentId = data.content?.id ?: return
        val sessionToken = data.playback.sessionToken ?: return

        viewModelScope.launch {
            repository.reportProgress(
                WatchProgressRequest(
                    sessionToken = sessionToken,
                    contentId = contentId,
                    contentFormatId = data.playback.contentFormatId,
                    episodeId = episodeId ?: data.episode?.id,
                    positionSeconds = positionSeconds,
                    durationSeconds = durationSeconds,
                    watchTimeSeconds = positionSeconds,
                    eventType = eventType,
                ),
            )
        }
    }
}
