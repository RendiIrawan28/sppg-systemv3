package id.sppg.mobile.core.notification

import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.asSharedFlow

/**
 * Sinyal ringan agar daftar tugas/notifikasi dimuat ulang saat FCM diterima.
 * Tidak menyimpan payload dan tidak memaksa navigasi ketika aplikasi aktif.
 */
object NotificationRefreshBus {
    private val _events = MutableSharedFlow<Unit>(extraBufferCapacity = 1)
    val events = _events.asSharedFlow()

    fun publish() {
        _events.tryEmit(Unit)
    }
}
