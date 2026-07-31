package id.sppg.mobile.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import id.sppg.mobile.data.OperationalRepository
import id.sppg.mobile.data.remote.OperationalModule
import id.sppg.mobile.data.remote.OperationalFormField
import id.sppg.mobile.data.remote.OperationalRecord
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import java.time.LocalDate
import java.time.LocalDateTime
import java.time.format.DateTimeFormatter

data class OperationalUiState(
    val isLoading: Boolean = false,
    val activeModule: String? = null,
    val modules: List<OperationalModule> = emptyList(),
    val records: List<OperationalRecord> = emptyList(),
    val currentPage: Int = 1,
    val lastPage: Int = 1,
    val isLoadingMore: Boolean = false,
    val selectedRecord: OperationalRecord? = null,
    val editValues: Map<String, String?> = emptyMap(),
    val editFiles: Map<String, String> = emptyMap(),
    val activeFormFields: List<OperationalFormField> = emptyList(),
    val isSaving: Boolean = false,
    val successMessage: String? = null,
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
                    records = if (it.activeModule == module && !force) it.records else emptyList(),
                    currentPage = 1,
                    lastPage = 1,
                    isLoadingMore = false,
                    selectedRecord = null,
                    errorMessage = null,
                )
            }
            repository.getRecords(module, page = 1)
                .onSuccess { page ->
                    _uiState.update {
                        it.copy(
                            records = page.records,
                            currentPage = page.currentPage,
                            lastPage = page.lastPage,
                        )
                    }
                }
                .onFailure { error -> _uiState.update { it.copy(errorMessage = error.message) } }
            _uiState.update { it.copy(isLoading = false) }
        }
    }

    fun loadMoreRecords() {
        val current = _uiState.value
        val module = current.activeModule ?: return
        if (current.isLoading || current.isLoadingMore || current.currentPage >= current.lastPage) return

        viewModelScope.launch {
            val nextPage = _uiState.value.currentPage + 1
            _uiState.update { it.copy(isLoadingMore = true, errorMessage = null) }
            repository.getRecords(module, page = nextPage)
                .onSuccess { page ->
                    _uiState.update { state ->
                        state.copy(
                            records = (state.records + page.records).distinctBy { it.id },
                            currentPage = page.currentPage,
                            lastPage = page.lastPage,
                        )
                    }
                }
                .onFailure { error -> _uiState.update { it.copy(errorMessage = error.message) } }
            _uiState.update { it.copy(isLoadingMore = false) }
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
        _uiState.update {
            it.copy(
                selectedRecord = null,
                editValues = emptyMap(),
                editFiles = emptyMap(),
                activeFormFields = emptyList(),
                errorMessage = null,
                successMessage = null,
            )
        }
    }

    fun prepareEdit() {
        _uiState.update { state ->
            state.copy(
                editValues = state.selectedRecord?.formFields.orEmpty()
                    .filter { it.editable }
                    .associate { it.key to it.value },
                activeFormFields = state.selectedRecord?.formFields.orEmpty(),
                editFiles = emptyMap(),
                errorMessage = null,
                successMessage = null,
            )
        }
    }

    fun prepareCreate(module: String) {
        _uiState.update { state ->
            val fields = state.modules.firstOrNull { it.slug == module }?.formFields.orEmpty()
            state.copy(
                activeFormFields = fields,
                editValues = fields.filter { it.editable }.associate { it.key to defaultFormValue(it) },
                editFiles = emptyMap(),
                errorMessage = null,
                successMessage = null,
            )
        }
    }

    fun updateEditValue(key: String, value: String?) {
        _uiState.update { it.copy(editValues = it.editValues + (key to value), errorMessage = null) }
    }

    fun updateEditFile(key: String, dataUri: String) {
        _uiState.update { it.copy(editFiles = it.editFiles + (key to dataUri), errorMessage = null) }
    }

    fun prepareRelationCreate(sectionKey: String) {
        _uiState.update { state ->
            val fields = state.selectedRecord?.sections.orEmpty()
                .firstOrNull { it.key == sectionKey }?.emptyFormFields.orEmpty()
            state.copy(
                activeFormFields = fields,
                editValues = fields.filter { it.editable && it.type != "file" }
                    .associate { it.key to defaultFormValue(it) },
                editFiles = emptyMap(),
                errorMessage = null,
                successMessage = null,
            )
        }
    }

    fun prepareRelationEdit(sectionKey: String, itemId: Long) {
        _uiState.update { state ->
            val fields = state.selectedRecord?.sections.orEmpty()
                .firstOrNull { it.key == sectionKey }?.items
                ?.firstOrNull { it.id == itemId }?.formFields.orEmpty()
            state.copy(
                activeFormFields = fields,
                editValues = fields.filter { it.editable && it.type != "file" }.associate { it.key to it.value },
                editFiles = emptyMap(),
                errorMessage = null,
                successMessage = null,
            )
        }
    }

    fun saveRecord(module: String, id: Long, onSaved: () -> Unit) {
        if (_uiState.value.isSaving) return
        viewModelScope.launch {
            _uiState.update { it.copy(isSaving = true, errorMessage = null, successMessage = null) }
            repository.updateRecord(module, id, _uiState.value.editValues, _uiState.value.editFiles)
                .onSuccess {
                    val refreshed = repository.getRecord(module, id).getOrNull() ?: it
                    _uiState.update { state ->
                        state.copy(selectedRecord = refreshed, successMessage = "Perubahan berhasil disimpan.")
                    }
                    onSaved()
                }
                .onFailure { error -> _uiState.update { it.copy(errorMessage = error.message) } }
            _uiState.update { it.copy(isSaving = false) }
        }
    }

    fun createRecord(module: String, onCreated: (Long) -> Unit) {
        if (_uiState.value.isSaving) return
        viewModelScope.launch {
            _uiState.update { it.copy(isSaving = true, errorMessage = null, successMessage = null) }
            repository.createRecord(module, _uiState.value.editValues, _uiState.value.editFiles)
                .onSuccess { record ->
                    val refreshed = repository.getRecord(module, record.id).getOrNull() ?: record
                    _uiState.update { state ->
                        state.copy(
                            selectedRecord = refreshed,
                            records = listOf(refreshed) + state.records.filterNot { it.id == refreshed.id },
                            successMessage = "Data baru berhasil dibuat.",
                        )
                    }
                    onCreated(record.id)
                }
                .onFailure { error -> _uiState.update { it.copy(errorMessage = error.message) } }
            _uiState.update { it.copy(isSaving = false) }
        }
    }

    fun deleteRecord(module: String, id: Long, onDeleted: () -> Unit) {
        if (_uiState.value.isSaving) return
        viewModelScope.launch {
            _uiState.update { it.copy(isSaving = true, errorMessage = null) }
            repository.deleteRecord(module, id)
                .onSuccess {
                    _uiState.update { state ->
                        state.copy(
                            records = state.records.filterNot { record -> record.id == id },
                            selectedRecord = null,
                            successMessage = it,
                        )
                    }
                    onDeleted()
                }
                .onFailure { error -> _uiState.update { it.copy(errorMessage = error.message) } }
            _uiState.update { it.copy(isSaving = false) }
        }
    }

    fun runAction(
        module: String,
        id: Long,
        action: String,
        notes: String?,
        fields: Map<String, String?> = emptyMap(),
        files: Map<String, String> = emptyMap(),
    ) {
        if (_uiState.value.isSaving) return
        viewModelScope.launch {
            _uiState.update { it.copy(isSaving = true, errorMessage = null, successMessage = null) }
            repository.runAction(module, id, action, notes, fields, files)
                .onSuccess { record ->
                    _uiState.update { state ->
                        state.copy(
                            selectedRecord = record,
                            records = state.records.map { if (it.id == record.id) record else it },
                            successMessage = "Tahap pekerjaan berhasil diperbarui.",
                        )
                    }
                }
                .onFailure { error -> _uiState.update { it.copy(errorMessage = error.message) } }
            _uiState.update { it.copy(isSaving = false) }
        }
    }

    fun saveRelation(
        module: String,
        recordId: Long,
        sectionKey: String,
        itemId: Long?,
        onSaved: () -> Unit,
    ) {
        if (_uiState.value.isSaving) return
        viewModelScope.launch {
            _uiState.update { it.copy(isSaving = true, errorMessage = null, successMessage = null) }
            val result = if (itemId == null) {
                repository.createRelation(module, recordId, sectionKey, _uiState.value.editValues, _uiState.value.editFiles)
            } else {
                repository.updateRelation(module, recordId, sectionKey, itemId, _uiState.value.editValues, _uiState.value.editFiles)
            }
            result.onSuccess {
                repository.getRecord(module, recordId)
                    .onSuccess { record -> _uiState.update { state -> state.copy(selectedRecord = record) } }
                _uiState.update { it.copy(successMessage = "Rincian berhasil disimpan.") }
                onSaved()
            }.onFailure { error -> _uiState.update { it.copy(errorMessage = error.message) } }
            _uiState.update { it.copy(isSaving = false) }
        }
    }

    fun deleteRelation(
        module: String,
        recordId: Long,
        sectionKey: String,
        itemId: Long,
    ) {
        if (_uiState.value.isSaving) return
        viewModelScope.launch {
            _uiState.update { it.copy(isSaving = true, errorMessage = null) }
            repository.deleteRelation(module, recordId, sectionKey, itemId)
                .onSuccess { message ->
                    repository.getRecord(module, recordId)
                        .onSuccess { record -> _uiState.update { state -> state.copy(selectedRecord = record) } }
                    _uiState.update { it.copy(successMessage = message) }
                }
                .onFailure { error -> _uiState.update { it.copy(errorMessage = error.message) } }
            _uiState.update { it.copy(isSaving = false) }
        }
    }

    fun runRelationAction(
        module: String,
        recordId: Long,
        sectionKey: String,
        itemId: Long,
        action: String,
    ) {
        if (_uiState.value.isSaving) return
        viewModelScope.launch {
            _uiState.update { it.copy(isSaving = true, errorMessage = null) }
            repository.runRelationAction(module, recordId, sectionKey, itemId, action)
                .onSuccess { message ->
                    repository.getRecord(module, recordId)
                        .onSuccess { record -> _uiState.update { state -> state.copy(selectedRecord = record) } }
                    _uiState.update { it.copy(successMessage = message) }
                }
                .onFailure { error -> _uiState.update { it.copy(errorMessage = error.message) } }
            _uiState.update { it.copy(isSaving = false) }
        }
    }

    fun downloadDocument(module: String, id: Long, onReady: (java.io.File) -> Unit) {
        if (_uiState.value.isSaving) return
        viewModelScope.launch {
            _uiState.update { it.copy(isSaving = true, errorMessage = null, successMessage = null) }
            repository.downloadDocument(module, id)
                .onSuccess { file -> onReady(file) }
                .onFailure { error -> _uiState.update { it.copy(errorMessage = error.message) } }
            _uiState.update { it.copy(isSaving = false) }
        }
    }

    fun clearFeedback() {
        _uiState.update { it.copy(errorMessage = null, successMessage = null) }
    }

    companion object {
        private fun defaultFormValue(field: OperationalFormField): String? {
            if (!field.value.isNullOrBlank()) return field.value
            if (!field.required) return field.value
            return when (field.type) {
                "date" -> LocalDate.now().toString()
                "datetime" -> LocalDateTime.now().format(DateTimeFormatter.ofPattern("yyyy-MM-dd'T'HH:mm"))
                "boolean" -> "0"
                else -> field.value
            }
        }

        fun factory(repository: OperationalRepository): ViewModelProvider.Factory =
            object : ViewModelProvider.Factory {
                @Suppress("UNCHECKED_CAST")
                override fun <T : ViewModel> create(modelClass: Class<T>): T {
                    return OperationalViewModel(repository) as T
                }
            }
    }
}
