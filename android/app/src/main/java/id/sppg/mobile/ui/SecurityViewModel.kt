package id.sppg.mobile.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import id.sppg.mobile.data.SecurityRepository
import id.sppg.mobile.data.remote.SecurityOverview
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import java.time.LocalDate

data class SecurityUiState(
    val isLoading: Boolean = false,
    val isSubmitting: Boolean = false,
    val overview: SecurityOverview? = null,
    val dateFilter: String = LocalDate.now().toString(),
    val errorMessage: String? = null,
    val successMessage: String? = null,
)

class SecurityViewModel(private val repository: SecurityRepository) : ViewModel() {
    private val _uiState = MutableStateFlow(SecurityUiState())
    val uiState: StateFlow<SecurityUiState> = _uiState.asStateFlow()

    fun load(force: Boolean = false, date: String = _uiState.value.dateFilter) {
        if (_uiState.value.isLoading && !force) return
        viewModelScope.launch {
            _uiState.update { it.copy(isLoading = true, dateFilter = date, errorMessage = null) }
            repository.overview(date)
                .onSuccess { overview -> _uiState.update { it.copy(overview = overview) } }
                .onFailure { error ->
                    _uiState.update { it.copy(errorMessage = error.message ?: "Data keamanan belum dapat dimuat.") }
                }
            _uiState.update { it.copy(isLoading = false) }
        }
    }

    fun filterHistory(date: String) = load(force = true, date = date)

    fun startShift() {
        if (_uiState.value.isSubmitting) return
        viewModelScope.launch {
            _uiState.update { it.copy(isSubmitting = true, errorMessage = null, successMessage = null) }
            repository.startShift()
                .onSuccess {
                    _uiState.update { state -> state.copy(successMessage = "Shift keamanan berhasil dimulai.") }
                    load(force = true)
                }
                .onFailure { error ->
                    _uiState.update { it.copy(errorMessage = error.message ?: "Shift belum dapat dimulai.") }
                }
            _uiState.update { it.copy(isSubmitting = false) }
        }
    }

    fun submitReport(
        situation: String,
        gateSecure: Boolean,
        perimeterSecure: Boolean,
        accessActivity: String,
        visitorActivity: String,
        notes: String,
        photo: String,
        onSuccess: () -> Unit,
    ) {
        val shift = _uiState.value.overview?.activeShift
        if (shift == null) {
            _uiState.update { it.copy(errorMessage = "Tidak ada shift keamanan aktif.") }
            return
        }
        if (photo.isBlank()) {
            _uiState.update { it.copy(errorMessage = "Foto pemeriksaan wajib diambil.") }
            return
        }
        if (_uiState.value.isSubmitting) return

        viewModelScope.launch {
            _uiState.update { it.copy(isSubmitting = true, errorMessage = null, successMessage = null) }
            repository.submitReport(
                shiftId = shift.id,
                situation = situation,
                gateSecure = gateSecure,
                perimeterSecure = perimeterSecure,
                accessActivity = accessActivity,
                visitorActivity = visitorActivity,
                notes = notes,
                photo = photo,
            ).onSuccess {
                _uiState.update { state -> state.copy(successMessage = "Laporan keamanan berhasil disimpan.") }
                onSuccess()
                load(force = true)
            }.onFailure { error ->
                _uiState.update { it.copy(errorMessage = error.message ?: "Laporan belum dapat disimpan.") }
            }
            _uiState.update { it.copy(isSubmitting = false) }
        }
    }

    fun clearFeedback() {
        _uiState.update { it.copy(errorMessage = null, successMessage = null) }
    }

    fun resetSession() {
        _uiState.value = SecurityUiState()
    }

    companion object {
        fun factory(repository: SecurityRepository): ViewModelProvider.Factory =
            object : ViewModelProvider.Factory {
                @Suppress("UNCHECKED_CAST")
                override fun <T : ViewModel> create(modelClass: Class<T>): T =
                    SecurityViewModel(repository) as T
            }
    }
}
