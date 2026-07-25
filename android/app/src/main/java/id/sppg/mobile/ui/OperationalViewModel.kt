package id.sppg.mobile.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import id.sppg.mobile.data.OperationalRepository
import id.sppg.mobile.data.remote.OperationalModule
import id.sppg.mobile.data.remote.OperationalRecord
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class OperationalUiState(
    val isLoading: Boolean = false,
    val activeModule: String? = null,
    val modules: List<OperationalModule> = emptyList(),
    val records: List<OperationalRecord> = emptyList(),
    val selectedRecord: OperationalRecord? = null,
    val errorMessage: String? = null,
)

class OperationalViewModel(private val repository: OperationalRepository) : ViewModel() {
    private val _uiState = MutableStateFlow(OperationalUiState())
    val uiState: StateFlow<OperationalUiState> = _uiState.asStateFlow()

    fun resetSession() {
        _uiState.value = OperationalUiState()
    }

    fun loadModules(force: Boolean = false) {
        if (!force && (_uiState.value.isLoading || _uiState.value.modules.isNotEmpty())) return
        viewModelScope.launch {
            _uiState.update { it.copy(isLoading = true, errorMessage = null) }
            repository.getModules()
                .onSuccess { modules -> _uiState.update { it.copy(modules = modules) } }
                .onFailure { error -> _uiState.update { it.copy(errorMessage = error.message) } }
            _uiState.update { it.copy(isLoading = false) }
        }
    }

    fun loadRecords(module: String, force: Boolean = false) {
        val current = _uiState.value
        if (!force && current.activeModule == module && current.records.isNotEmpty()) return
        viewModelScope.launch {
            _uiState.update {
                it.copy(
                    isLoading = true,
                    activeModule = module,
                    records = if (it.activeModule == module) it.records else emptyList(),
                    selectedRecord = null,
                    errorMessage = null,
                )
            }
            repository.getRecords(module)
                .onSuccess { records -> _uiState.update { it.copy(records = records) } }
                .onFailure { error -> _uiState.update { it.copy(errorMessage = error.message) } }
            _uiState.update { it.copy(isLoading = false) }
        }
    }

    fun loadRecord(module: String, id: Long) {
        if (_uiState.value.isLoading || _uiState.value.selectedRecord?.id == id) return
        viewModelScope.launch {
            _uiState.update { it.copy(isLoading = true, selectedRecord = null, errorMessage = null) }
            repository.getRecord(module, id)
                .onSuccess { record -> _uiState.update { it.copy(selectedRecord = record) } }
                .onFailure { error -> _uiState.update { it.copy(errorMessage = error.message) } }
            _uiState.update { it.copy(isLoading = false) }
        }
    }

    fun clearDetail() {
        _uiState.update { it.copy(selectedRecord = null, errorMessage = null) }
    }

    companion object {
        fun factory(repository: OperationalRepository): ViewModelProvider.Factory =
            object : ViewModelProvider.Factory {
                @Suppress("UNCHECKED_CAST")
                override fun <T : ViewModel> create(modelClass: Class<T>): T {
                    return OperationalViewModel(repository) as T
                }
            }
    }
}
