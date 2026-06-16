package md.film.tv.ui.pairing

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import md.film.tv.data.FilmotecaRepository
import md.film.tv.data.PollResult

sealed interface PairingUiState {
    data object Loading : PairingUiState
    data class AwaitingApproval(
        val userCode: String,
        val verificationUri: String,
        val verificationUriComplete: String,
    ) : PairingUiState
    data object Authorized : PairingUiState
    data class Failed(val message: String) : PairingUiState
}

class PairingViewModel(private val repository: FilmotecaRepository) : ViewModel() {

    private val _state = MutableStateFlow<PairingUiState>(PairingUiState.Loading)
    val state: StateFlow<PairingUiState> = _state.asStateFlow()

    init {
        start()
    }

    fun start() {
        _state.value = PairingUiState.Loading
        viewModelScope.launch {
            try {
                val code = repository.requestDeviceCode(deviceName = "Android TV")
                _state.value = PairingUiState.AwaitingApproval(
                    userCode = code.userCode,
                    verificationUri = code.verificationUri,
                    verificationUriComplete = code.verificationUriComplete,
                )
                poll(code.deviceCode, code.interval)
            } catch (e: Exception) {
                _state.value = PairingUiState.Failed(
                    e.message ?: "Nu am putut obține un cod. Verifică conexiunea.",
                )
            }
        }
    }

    private suspend fun poll(deviceCode: String, interval: Int) {
        var delaySeconds = interval.coerceAtLeast(2)
        while (true) {
            delay(delaySeconds * 1000L)
            when (val result = repository.pollDeviceToken(deviceCode)) {
                is PollResult.Authorized -> {
                    repository.persistSession(result.token, result.userName)
                    _state.value = PairingUiState.Authorized
                    return
                }
                PollResult.Pending -> Unit
                PollResult.SlowDown -> delaySeconds += 2
                PollResult.Expired -> {
                    _state.value = PairingUiState.Failed("Codul a expirat. Generează unul nou.")
                    return
                }
                PollResult.Denied -> {
                    _state.value = PairingUiState.Failed("Cererea a fost respinsă.")
                    return
                }
                is PollResult.Error -> {
                    _state.value = PairingUiState.Failed(result.message)
                    return
                }
            }
        }
    }
}
