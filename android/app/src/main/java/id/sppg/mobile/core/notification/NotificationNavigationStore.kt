package id.sppg.mobile.core.notification

import android.content.Intent
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow

data class NotificationNavigation(
    val screen: String,
    val taskId: Long? = null,
    val shiftId: Long? = null,
)

/**
 * Menyimpan satu tujuan navigasi dari notifikasi sampai UI yang sudah login
 * benar-benar mengonsumsinya. State dikosongkan setelah dipakai agar rotasi
 * layar tidak membuka halaman yang sama berulang kali.
 */
object NotificationNavigationStore {
    private val _event = MutableStateFlow<NotificationNavigation?>(null)
    val event = _event.asStateFlow()

    fun publish(intent: Intent?) {
        val screen = intent?.getStringExtra("screen")?.takeIf { it.isNotBlank() } ?: return
        _event.value = NotificationNavigation(
            screen = screen,
            taskId = intent.getStringExtra("task_id")?.toLongOrNull(),
            shiftId = intent.getStringExtra("shift_id")?.toLongOrNull(),
        )
    }

    fun publish(data: Map<String, String>) {
        val screen = data["screen"]?.takeIf { it.isNotBlank() } ?: return
        _event.value = NotificationNavigation(
            screen = screen,
            taskId = data["task_id"]?.toLongOrNull(),
            shiftId = data["shift_id"]?.toLongOrNull(),
        )
    }

    fun consume() {
        _event.value = null
    }
}
