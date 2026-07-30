package id.sppg.mobile.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import id.sppg.mobile.core.notification.FirebaseTokenRegistrar
import id.sppg.mobile.data.NotificationRepository
import id.sppg.mobile.data.remote.MobileNotificationItem
import id.sppg.mobile.data.remote.MobileTaskItem
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class NotificationUiState(
    val isLoading: Boolean = false,
    val isRegistering: Boolean = false,
    val tasks: List<MobileTaskItem> = emptyList(),
    val notifications: List<MobileNotificationItem> = emptyList(),
    val unreadCount: Int = 0,
    val errorMessage: String? = null,
    val firebaseNotice: String? = null,
)

class NotificationViewModel(
    private val repository: NotificationRepository,
    private val registrar: FirebaseTokenRegistrar,
) : ViewModel() {
    private val _uiState = MutableStateFlow(NotificationUiState())
    val uiState: StateFlow<NotificationUiState> = _uiState.asStateFlow()

    fun registerDevice() {
        if (_uiState.value.isRegistering) return
        viewModelScope.launch {
            _uiState.update { it.copy(isRegistering = true, firebaseNotice = null) }
            registrar.registerCurrentToken()
                .onFailure { error ->
                    _uiState.update {
                        it.copy(firebaseNotice = error.message ?: "Firebase belum dapat diaktifkan.")
                    }
                }
            _uiState.update { it.copy(isRegistering = false) }
        }
    }

    fun load(force: Boolean = false) {
        if (_uiState.value.isLoading && !force) return
        viewModelScope.launch {
            _uiState.update { it.copy(isLoading = true, errorMessage = null) }
            val taskResult = repository.tasks()
            val notificationResult = repository.notifications()
            taskResult.onSuccess { (tasks, unread) ->
                _uiState.update { it.copy(tasks = tasks, unreadCount = unread) }
            }.onFailure { error ->
                _uiState.update { it.copy(errorMessage = error.message ?: "Tugas belum dapat dimuat.") }
            }
            notificationResult.onSuccess { notifications ->
                _uiState.update {
                    it.copy(
                        notifications = notifications,
                        unreadCount = notifications.count { item -> item.readAt == null },
                    )
                }
            }
            _uiState.update { it.copy(isLoading = false) }
        }
    }

    fun markRead(notification: MobileNotificationItem, onNavigate: (String?) -> Unit) {
        viewModelScope.launch {
            if (notification.readAt == null) {
                repository.markRead(notification.id)
            }
            load(force = true)
            onNavigate(notification.screen)
        }
    }

    fun markAllRead() {
        viewModelScope.launch {
            repository.markAllRead()
            load(force = true)
        }
    }


    fun unregisterDevice(onComplete: () -> Unit) {
        viewModelScope.launch {
            registrar.unregisterCurrentDevice()
            _uiState.value = NotificationUiState()
            onComplete()
        }
    }

    fun clearFeedback() {
        _uiState.update { it.copy(errorMessage = null, firebaseNotice = null) }
    }

    fun resetSession() {
        _uiState.value = NotificationUiState()
    }

    companion object {
        fun factory(
            repository: NotificationRepository,
            registrar: FirebaseTokenRegistrar,
        ): ViewModelProvider.Factory = object : ViewModelProvider.Factory {
            @Suppress("UNCHECKED_CAST")
            override fun <T : ViewModel> create(modelClass: Class<T>): T =
                NotificationViewModel(repository, registrar) as T
        }
    }
}
