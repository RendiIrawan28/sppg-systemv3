package id.sppg.mobile.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import id.sppg.mobile.data.FieldPlanRepository
import id.sppg.mobile.data.remote.FieldPlan
import id.sppg.mobile.data.remote.ReadinessResponse
import id.sppg.mobile.data.remote.UpdateFieldPlanRequest
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class FieldPlanUiState(
    val isLoading: Boolean = false,
    val isSubmitting: Boolean = false,
    val plans: List<FieldPlan> = emptyList(),
    val selectedPlan: FieldPlan? = null,
    val readiness: ReadinessResponse? = null,
    val successMessage: String? = null,
    val errorMessage: String? = null,
)

class FieldPlanViewModel(private val repository: FieldPlanRepository) : ViewModel() {
    private val _uiState = MutableStateFlow(FieldPlanUiState())
    val uiState: StateFlow<FieldPlanUiState> = _uiState.asStateFlow()

    fun resetSession() {
        _uiState.value = FieldPlanUiState()
    }

    fun loadPlans(force: Boolean = false) {
        if (!force && (_uiState.value.isLoading || _uiState.value.plans.isNotEmpty())) return
        viewModelScope.launch {
            _uiState.update { it.copy(isLoading = true, errorMessage = null, successMessage = null, selectedPlan = null) }
            repository.getPlans()
                .onSuccess { plans -> _uiState.update { it.copy(plans = plans) } }
                .onFailure { error -> _uiState.update { it.copy(errorMessage = error.message) } }
            _uiState.update { it.copy(isLoading = false) }
        }
    }

    fun loadPlan(id: Long) {
        if (_uiState.value.isLoading || _uiState.value.selectedPlan?.id == id) return
        viewModelScope.launch {
            _uiState.update { it.copy(isLoading = true, errorMessage = null, successMessage = null, readiness = null, selectedPlan = null) }
            repository.getPlan(id)
                .onSuccess { plan -> _uiState.update { it.copy(selectedPlan = plan) } }
                .onFailure { error -> _uiState.update { it.copy(errorMessage = error.message) } }
            _uiState.update { it.copy(isLoading = false) }
        }
    }

    fun clearDetail() {
        _uiState.update { it.copy(selectedPlan = null, readiness = null, successMessage = null, errorMessage = null) }
    }

    fun updatePlan(request: UpdateFieldPlanRequest) {
        val id = _uiState.value.selectedPlan?.id ?: return
        viewModelScope.launch {
            _uiState.update { it.copy(isSubmitting = true, errorMessage = null, successMessage = null, readiness = null) }
            repository.updatePlan(id, request)
                .onSuccess { plan ->
                    _uiState.update {
                        it.copy(
                            selectedPlan = plan,
                            plans = replacePlan(it.plans, plan),
                            successMessage = "Konfirmasi rencana berhasil disimpan.",
                        )
                    }
                }
                .onFailure { error -> _uiState.update { it.copy(errorMessage = error.message) } }
            _uiState.update { it.copy(isSubmitting = false) }
        }
    }

    fun checkReadiness() {
        val id = _uiState.value.selectedPlan?.id ?: return
        viewModelScope.launch {
            _uiState.update { it.copy(isSubmitting = true, errorMessage = null, successMessage = null, readiness = null) }
            repository.checkReadiness(id)
                .onSuccess { readiness -> _uiState.update { it.copy(readiness = readiness) } }
                .onFailure { error -> _uiState.update { it.copy(errorMessage = error.message) } }
            _uiState.update { it.copy(isSubmitting = false) }
        }
    }

    fun activatePlan(notes: String?) {
        val id = _uiState.value.selectedPlan?.id ?: return
        viewModelScope.launch {
            _uiState.update { it.copy(isSubmitting = true, errorMessage = null, successMessage = null) }
            repository.activatePlan(id, notes)
                .onSuccess { plan ->
                    _uiState.update {
                        it.copy(
                            selectedPlan = plan,
                            plans = replacePlan(it.plans, plan),
                            readiness = null,
                            successMessage = "Rencana berhasil diaktifkan.",
                        )
                    }
                }
                .onFailure { error -> _uiState.update { it.copy(errorMessage = error.message) } }
            _uiState.update { it.copy(isSubmitting = false) }
        }
    }

    fun clearFeedback() {
        _uiState.update { it.copy(errorMessage = null, successMessage = null) }
    }

    private fun replacePlan(plans: List<FieldPlan>, updated: FieldPlan): List<FieldPlan> {
        return plans.map { if (it.id == updated.id) updated else it }
    }

    companion object {
        fun factory(repository: FieldPlanRepository): ViewModelProvider.Factory =
            object : ViewModelProvider.Factory {
                @Suppress("UNCHECKED_CAST")
                override fun <T : ViewModel> create(modelClass: Class<T>): T {
                    return FieldPlanViewModel(repository) as T
                }
            }
    }
}
