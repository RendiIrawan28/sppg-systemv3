package id.sppg.mobile.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import id.sppg.mobile.data.FieldPlanRepository
import id.sppg.mobile.data.remote.FieldPlan
import id.sppg.mobile.data.remote.FieldPlanOption
import id.sppg.mobile.data.remote.ReadinessResponse
import id.sppg.mobile.data.remote.UpdateFieldPlanRequest
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import java.time.LocalDate

data class FieldPlanUiState(
    val isLoading: Boolean = false,
    val isSubmitting: Boolean = false,
    val plans: List<FieldPlan> = emptyList(),
    val currentPage: Int = 1,
    val lastPage: Int = 1,
    val dateFilter: String = LocalDate.now().toString(),
    val isLoadingMore: Boolean = false,
    val selectedPlan: FieldPlan? = null,
    val options: List<FieldPlanOption> = emptyList(),
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

    fun loadPlans(force: Boolean = false, date: String = _uiState.value.dateFilter) {
        if (!force && (_uiState.value.isLoading || _uiState.value.plans.isNotEmpty())) return
        viewModelScope.launch {
            _uiState.update {
                it.copy(
                    isLoading = true,
                    plans = if (force) emptyList() else it.plans,
                    currentPage = 1,
                    lastPage = 1,
                    isLoadingMore = false,
                    dateFilter = date,
                    errorMessage = null,
                    successMessage = null,
                    selectedPlan = null,
                )
            }
            repository.getPlans(page = 1, date = date)
                .onSuccess { page ->
                    _uiState.update {
                        it.copy(
                            plans = page.plans,
                            currentPage = page.currentPage,
                            lastPage = page.lastPage,
                        )
                    }
                }
                .onFailure { error -> _uiState.update { it.copy(errorMessage = error.message) } }
            _uiState.update { it.copy(isLoading = false) }
        }
    }

    fun loadMorePlans() {
        val current = _uiState.value
        if (current.isLoading || current.isLoadingMore || current.currentPage >= current.lastPage) return
        viewModelScope.launch {
            val nextPage = _uiState.value.currentPage + 1
            _uiState.update { it.copy(isLoadingMore = true, errorMessage = null) }
            repository.getPlans(page = nextPage, date = current.dateFilter)
                .onSuccess { page ->
                    _uiState.update { state ->
                        state.copy(
                            plans = (state.plans + page.plans).distinctBy { it.id },
                            currentPage = page.currentPage,
                            lastPage = page.lastPage,
                        )
                    }
                }
                .onFailure { error -> _uiState.update { it.copy(errorMessage = error.message) } }
            _uiState.update { it.copy(isLoadingMore = false) }
        }
    }

    fun filterPlans(date: String) = loadPlans(force = true, date = date)

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

    fun loadOptions(force: Boolean = false) {
        if (!force && (_uiState.value.isLoading || _uiState.value.options.isNotEmpty())) return
        viewModelScope.launch {
            _uiState.update { it.copy(isLoading = true, errorMessage = null, successMessage = null) }
            repository.getOptions()
                .onSuccess { options -> _uiState.update { it.copy(options = options) } }
                .onFailure { error -> _uiState.update { it.copy(errorMessage = error.message) } }
            _uiState.update { it.copy(isLoading = false) }
        }
    }

    fun createPlan(distributionDate: String, notes: String?, onCreated: (Long) -> Unit) {
        viewModelScope.launch {
            _uiState.update { it.copy(isSubmitting = true, errorMessage = null, successMessage = null) }
            repository.createPlan(distributionDate, notes)
                .onSuccess { plan ->
                    _uiState.update {
                        it.copy(
                            selectedPlan = plan,
                            plans = listOf(plan) + it.plans.filterNot { item -> item.id == plan.id },
                            options = it.options.map { option -> if (option.distributionDate == distributionDate) option.copy(hasPlan = true) else option },
                            successMessage = "Rencana distribusi berhasil dibuat.",
                        )
                    }
                    onCreated(plan.id)
                }
                .onFailure { error -> _uiState.update { it.copy(errorMessage = error.message) } }
            _uiState.update { it.copy(isSubmitting = false) }
        }
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
        _uiState.update { it.copy(isSubmitting = true, errorMessage = null, successMessage = null, readiness = null) }
        viewModelScope.launch {
            repository.checkReadiness(id)
                .onSuccess { readiness -> _uiState.update { it.copy(readiness = readiness) } }
                .onFailure { error -> _uiState.update { it.copy(errorMessage = error.message) } }
            _uiState.update { it.copy(isSubmitting = false) }
        }
    }

    fun refreshBeneficiaries() {
        val id = _uiState.value.selectedPlan?.id ?: return
        viewModelScope.launch {
            _uiState.update { it.copy(isSubmitting = true, errorMessage = null, successMessage = null, readiness = null) }
            repository.refreshBeneficiaries(id)
                .onSuccess { plan -> _uiState.update { it.copy(selectedPlan = plan, plans = replacePlan(it.plans, plan), successMessage = "Data penerima berhasil diperbarui.") } }
                .onFailure { error -> _uiState.update { it.copy(errorMessage = error.message) } }
            _uiState.update { it.copy(isSubmitting = false) }
        }
    }

    fun deletePlan(onDeleted: () -> Unit) {
        val id = _uiState.value.selectedPlan?.id ?: return
        viewModelScope.launch {
            _uiState.update { it.copy(isSubmitting = true, errorMessage = null, successMessage = null) }
            repository.deletePlan(id)
                .onSuccess {
                    _uiState.update { it.copy(selectedPlan = null, plans = it.plans.filterNot { plan -> plan.id == id }, options = emptyList()) }
                    onDeleted()
                }
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

    fun downloadDocument(id: Long, format: String = "pdf", onReady: (java.io.File) -> Unit) {
        if (_uiState.value.isSubmitting) return
        viewModelScope.launch {
            _uiState.update { it.copy(isSubmitting = true, errorMessage = null, successMessage = null) }
            repository.downloadDocument(id, format)
                .onSuccess(onReady)
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
