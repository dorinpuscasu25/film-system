package md.film.tv.ui.profile

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import md.film.tv.data.FilmotecaRepository
import md.film.tv.data.remote.dto.ProfileDto

sealed interface ProfileUiState {
    data object Loading : ProfileUiState
    data class Ready(val profiles: List<ProfileDto>) : ProfileUiState
    data class Error(val message: String) : ProfileUiState
}

class ProfileViewModel(private val repository: FilmotecaRepository) : ViewModel() {

    private val _state = MutableStateFlow<ProfileUiState>(ProfileUiState.Loading)
    val state: StateFlow<ProfileUiState> = _state.asStateFlow()

    private val _selected = MutableStateFlow(false)
    val selected: StateFlow<Boolean> = _selected.asStateFlow()

    private val _pinError = MutableStateFlow<String?>(null)
    val pinError: StateFlow<String?> = _pinError.asStateFlow()

    fun load() {
        _state.value = ProfileUiState.Loading
        viewModelScope.launch {
            val account = repository.account()
            val profiles = account?.user?.profiles.orEmpty()
            _state.value = if (account == null) {
                ProfileUiState.Error("Nu am putut încărca profilele.")
            } else {
                ProfileUiState.Ready(profiles)
            }
        }
    }

    /** Choose a profile without a PIN, persisting it as active. */
    fun choose(profile: ProfileDto) {
        viewModelScope.launch {
            repository.setActiveProfile(profile.id, profile.name)
            _selected.value = true
        }
    }

    /** Choose a PIN-protected profile; verifies the PIN before persisting. */
    fun chooseWithPin(profile: ProfileDto, pin: String) {
        _pinError.value = null
        viewModelScope.launch {
            if (repository.unlockParental(profile.id, pin)) {
                repository.setActiveProfile(profile.id, profile.name)
                _selected.value = true
            } else {
                _pinError.value = "PIN incorect."
            }
        }
    }

    fun clearPinError() {
        _pinError.value = null
    }
}
