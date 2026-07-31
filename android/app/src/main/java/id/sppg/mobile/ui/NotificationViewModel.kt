package id.sppg.mobile.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import id.sppg.mobile.core.notification.FirebaseTokenRegistrar
import id.sppg.mobile.data.NotificationRepository
import id.sppg.mobile.data.remote.MobileNotificationItem
import id.sppg.mobile.data.remote.MobileTaskItem
import id.sppg.mobile.data.remote.PushNotificationStatus
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class NotificationUiState(
    val isLoading: Boolean = false,
    val isRegistering: Boolean = false,
    val isSendingTest: Boolean = false,
    val tasks: List<MobileTaskItem> = emptyList(),
    val notifications: List<MobileNotificationItem> = emptyList(),
    val unreadCount: Int = 0,
    val pushStatus: PushNotificationStatus? = null,
    val errorMessage: String? = null,
    val successMessage: String? = null,
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
            _uiState.update {
                it.copy(
                    isRegistering = true,
                    firebaseNotice = null,
                    successMessage = null,
                )
            }
            registrar.registerCurrentToken()
                .onSuccess {
                    loadPushStatus()
                }
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
            val statusResult = repository.pushStatus()

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
            }.onFailure { error ->
                if (_uiState.value.errorMessage == null) {
                    _uiState.update {
                        it.copy(errorMessage = error.message ?: "Riwayat notifikasi belum dapat dimuat.")
                    }
                }
            }
            statusResult.onSuccess { status ->
                _uiState.update { it.copy(pushStatus = status) }
            }
            _uiState.update { it.copy(isLoading = false) }
        }
    }

    fun sendTestNotification() {
        if (_uiState.value.isSendingTest) return
        viewModelScope.launch {
            _uiState.update {
                it.copy(
                    isSendingTest = true,
                    errorMessage = null,
                    successMessage = null,
                )
            }
            repository.sendTestNotification()
                .onSuccess { result ->
                    if (result.deliveryStatus == "sent") {
                        _uiState.update {
                            it.copy(successMessage = "Notifikasi uji diterima Firebase. Tunggu beberapa detik pada perangkat ini.")
                        }
                    } else {
                        _uiState.update {
                            it.copy(
                                errorMessage = result.errorMessage
                                    ?: "Notifikasi uji berstatus ${result.deliveryStatus}.",
                            )
                        }
                    }
                }
                .onFailure { error ->
                    _uiState.update {
                        it.copy(errorMessage = error.message ?: "Notifikasi uji belum dapat dikirim.")
                    }
                }
            _uiState.update { it.copy(isSendingTest = false) }
            load(force = true)
        }
    }

    private suspend fun loadPushStatus() {
        repository.pushStatus()
            .onSuccess { status ->
                _uiState.update { it.copy(pushStatus = status, firebaseNotice = null) }
            }
            .onFailure { error ->
                _uiState.update {
                    it.copy(firebaseNotice = error.message ?: "Status Firebase belum dapat diperiksa.")
                }
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
        _uiState.update {
            it.copy(
                errorMessage = null,
                successMessage = null,
                firebaseNotice = null,
            )
        }
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
