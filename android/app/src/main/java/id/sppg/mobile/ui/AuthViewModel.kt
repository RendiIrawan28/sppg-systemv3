package id.sppg.mobile.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import id.sppg.mobile.data.AuthRepository
import id.sppg.mobile.data.session.UserSession
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class AuthUiState(
    val isLoading: Boolean = true,
    val isSubmitting: Boolean = false,
    val session: UserSession? = null,
    val errorMessage: String? = null,
    val noticeMessage: String? = null,
)

class AuthViewModel(private val repository: AuthRepository) : ViewModel() {
    private val _uiState = MutableStateFlow(AuthUiState())
    val uiState: StateFlow<AuthUiState> = _uiState.asStateFlow()

    init {
        viewModelScope.launch {
            repository.sessionEvents.collect { event ->
                if (!event.message.isNullOrBlank()) {
                    _uiState.update {
                        it.copy(errorMessage = event.message, noticeMessage = null)
                    }
                }
            }
        }

        viewModelScope.launch {
            val bootstrap = repository.bootstrap()
            _uiState.update {
                it.copy(
                    isLoading = false,
                    session = bootstrap.session,
                    noticeMessage = bootstrap.warningMessage,
                )
            }

            repository.session.collect { session ->
                _uiState.update { current ->
                    current.copy(
                        isLoading = false,
                        session = session,
                        isSubmitting = false,
                    )
                }
            }
        }
    }

    fun login(login: String, password: String) {
        if (login.isBlank() || password.isBlank()) {
            _uiState.update { it.copy(errorMessage = "Email/nomor pegawai dan kata sandi wajib diisi.") }
            return
        }

        viewModelScope.launch {
            _uiState.update {
                it.copy(isSubmitting = true, errorMessage = null, noticeMessage = null)
            }
            repository.login(login, password)
                .onFailure { error ->
                    _uiState.update {
                        it.copy(errorMessage = error.message ?: "Tidak dapat terhubung ke server.")
                    }
                }
            _uiState.update { it.copy(isSubmitting = false) }
        }
    }

    fun logout() {
        viewModelScope.launch {
            _uiState.update { it.copy(isSubmitting = true, noticeMessage = null) }
            repository.logout()
            _uiState.update {
                AuthUiState(
                    isLoading = false,
                    errorMessage = null,
                )
            }
        }
    }

    fun dismissError() {
        _uiState.update { it.copy(errorMessage = null) }
    }

    fun dismissNotice() {
        _uiState.update { it.copy(noticeMessage = null) }
    }

    companion object {
        fun factory(repository: AuthRepository): ViewModelProvider.Factory =
            object : ViewModelProvider.Factory {
                @Suppress("UNCHECKED_CAST")
                override fun <T : ViewModel> create(modelClass: Class<T>): T {
                    return AuthViewModel(repository) as T
                }
            }
    }
}
