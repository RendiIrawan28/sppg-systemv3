package id.sppg.mobile.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.outlined.DoneAll
import androidx.compose.material.icons.outlined.NotificationsActive
import androidx.compose.material.icons.outlined.Refresh
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import id.sppg.mobile.data.remote.MobileNotificationItem
import id.sppg.mobile.data.remote.MobileTaskItem
import id.sppg.mobile.data.remote.PushNotificationStatus
import java.time.OffsetDateTime
import java.time.format.DateTimeFormatter

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun TaskListScreen(
    state: NotificationUiState,
    onBack: () -> Unit,
    onRefresh: () -> Unit,
    onLoad: () -> Unit,
    onTaskClick: (MobileTaskItem) -> Unit,
    onNotificationClick: (MobileNotificationItem) -> Unit,
    onMarkAllRead: () -> Unit,
    onSendTestNotification: () -> Unit,
) {
    LaunchedEffect(Unit) { onLoad() }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Tugas Saya", fontWeight = FontWeight.Bold) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Outlined.ArrowBack, contentDescription = "Kembali")
                    }
                },
                actions = {
                    if (state.unreadCount > 0) {
                        IconButton(onClick = onMarkAllRead) {
                            Icon(Icons.Outlined.DoneAll, contentDescription = "Tandai semua dibaca")
                        }
                    }
                    IconButton(onClick = onRefresh) {
                        Icon(Icons.Outlined.Refresh, contentDescription = "Muat ulang")
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = MaterialTheme.colorScheme.background,
                ),
            )
        },
    ) { innerPadding ->
        LazyColumn(
            modifier = Modifier.fillMaxSize(),
            contentPadding = PaddingValues(
                start = 20.dp,
                end = 20.dp,
                top = innerPadding.calculateTopPadding() + 12.dp,
                bottom = 32.dp,
            ),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            if (state.isLoading && state.tasks.isEmpty()) {
                item(key = "loading") {
                    Row(
                        modifier = Modifier.fillMaxWidth().padding(32.dp),
                        horizontalArrangement = Arrangement.Center,
                    ) { CircularProgressIndicator() }
                }
            }

            state.errorMessage?.let { message ->
                item(key = "error-message") {
                    FeedbackCard("Proses belum berhasil", message, isError = true)
                }
            }

            state.successMessage?.let { message ->
                item(key = "success-message") {
                    FeedbackCard("Berhasil", message, isError = false)
                }
            }

            state.firebaseNotice?.let { message ->
                item(key = "firebase-notice") {
                    FeedbackCard(
                        "Push notification belum aktif",
                        message,
                        isError = false,
                    )
                }
            }

            item(key = "push-status") {
                PushStatusCard(
                    status = state.pushStatus,
                    isRegistering = state.isRegistering,
                    isSendingTest = state.isSendingTest,
                    onSendTestNotification = onSendTestNotification,
                )
            }

            item(key = "active-title") {
                Text("Pekerjaan aktif", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                Spacer(Modifier.height(4.dp))
                Text(
                    "Tugas tetap tampil di sini meskipun notifikasi perangkat tidak aktif.",
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }

            if (!state.isLoading && state.tasks.isEmpty()) {
                item(key = "empty-tasks") {
                    FeedbackCard("Tidak ada tugas tertunda", "Seluruh pekerjaan yang tercatat sudah selesai.", false)
                }
            } else {
                items(state.tasks, key = { task -> "task-${task.id}" }) { task ->
                    TaskCard(task = task, onClick = { onTaskClick(task) })
                }
            }

            item(key = "notification-title") {
                Spacer(Modifier.height(12.dp))
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("Riwayat notifikasi", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                    if (state.unreadCount > 0) {
                        Spacer(Modifier.weight(1f))
                        SppgStatusPill("${state.unreadCount} belum dibaca")
                    }
                }
            }

            if (state.notifications.isEmpty()) {
                item(key = "empty-notifications") {
                    Text("Belum ada riwayat notifikasi.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            } else {
                items(state.notifications, key = { notification -> "notification-${notification.id}" }) { notification ->
                    NotificationCard(notification, onClick = { onNotificationClick(notification) })
                }
            }
        }
    }
}

@Composable
private fun PushStatusCard(
    status: PushNotificationStatus?,
    isRegistering: Boolean,
    isSendingTest: Boolean,
    onSendTestNotification: () -> Unit,
) {
    val ready = status?.firebaseConfigured == true &&
        status.deviceRegistered &&
        status.deviceActive

    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(20.dp),
        colors = CardDefaults.cardColors(
            containerColor = if (ready) {
                MaterialTheme.colorScheme.primaryContainer
            } else {
                MaterialTheme.colorScheme.secondaryContainer
            },
        ),
    ) {
        Column(Modifier.padding(18.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Outlined.NotificationsActive, contentDescription = null)
                Spacer(Modifier.width(10.dp))
                Text("Status push notification", fontWeight = FontWeight.Bold)
                Spacer(Modifier.weight(1f))
                SppgStatusPill(if (ready) "Siap" else "Perlu diperiksa")
            }
            Spacer(Modifier.height(10.dp))

            when {
                isRegistering -> Text("Mendaftarkan token perangkat ke server…")
                status == null -> Text("Status perangkat belum tersedia. Tekan muat ulang.")
                else -> {
                    StatusLine("Firebase server", if (status.firebaseConfigured) "Siap" else "Belum siap")
                    StatusLine("Perangkat terdaftar", if (status.deviceRegistered) "Ya" else "Belum")
                    StatusLine("Token perangkat aktif", if (status.deviceActive) "Ya" else "Tidak")
                    if (!status.deviceName.isNullOrBlank()) {
                        StatusLine("Perangkat", status.deviceName)
                    }
                    if (!status.lastSeenAt.isNullOrBlank()) {
                        StatusLine("Terakhir terhubung", formatMobileDate(status.lastSeenAt))
                    }
                    if (!status.firebaseConfigured) {
                        Spacer(Modifier.height(8.dp))
                        Text(
                            status.firebaseMessage,
                            color = MaterialTheme.colorScheme.onSecondaryContainer,
                        )
                    }
                }
            }

            Spacer(Modifier.height(14.dp))
            Button(
                onClick = onSendTestNotification,
                enabled = ready && !isSendingTest,
                modifier = Modifier.fillMaxWidth(),
            ) {
                if (isSendingTest) {
                    CircularProgressIndicator(
                        modifier = Modifier.size(20.dp),
                        strokeWidth = 2.dp,
                    )
                    Spacer(Modifier.width(12.dp))
                }
                Text(if (isSendingTest) "Mengirim…" else "Kirim notifikasi uji")
            }
        }
    }
}

@Composable
private fun StatusLine(label: String, value: String) {
    Row(modifier = Modifier.fillMaxWidth().padding(vertical = 2.dp)) {
        Text(label, modifier = Modifier.weight(1f), color = MaterialTheme.colorScheme.onSurfaceVariant)
        Text(value, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
private fun TaskCard(task: MobileTaskItem, onClick: () -> Unit) {
    Card(
        modifier = Modifier.fillMaxWidth().clickable(onClick = onClick),
        shape = RoundedCornerShape(20.dp),
        colors = CardDefaults.cardColors(
            containerColor = if (task.isOverdue) {
                MaterialTheme.colorScheme.errorContainer
            } else {
                MaterialTheme.colorScheme.surface
            },
        ),
    ) {
        Column(Modifier.padding(18.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(task.title, modifier = Modifier.weight(1f), fontWeight = FontWeight.Bold)
                SppgStatusPill(if (task.isOverdue) "Terlambat" else "Menunggu")
            }
            if (!task.description.isNullOrBlank()) {
                Spacer(Modifier.height(8.dp))
                Text(task.description, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            if (!task.dueAt.isNullOrBlank()) {
                Spacer(Modifier.height(10.dp))
                Text("Batas: ${formatMobileDate(task.dueAt)}", style = MaterialTheme.typography.labelLarge)
            }
        }
    }
}

@Composable
private fun NotificationCard(notification: MobileNotificationItem, onClick: () -> Unit) {
    Card(
        modifier = Modifier.fillMaxWidth().clickable(onClick = onClick),
        shape = RoundedCornerShape(18.dp),
        colors = CardDefaults.cardColors(
            containerColor = if (notification.readAt == null) {
                MaterialTheme.colorScheme.primaryContainer
            } else {
                MaterialTheme.colorScheme.surface
            },
        ),
    ) {
        Column(Modifier.padding(16.dp)) {
            Text(notification.title, fontWeight = FontWeight.Bold)
            Spacer(Modifier.height(5.dp))
            Text(notification.body, color = MaterialTheme.colorScheme.onSurfaceVariant)
            if (notification.deliveryStatus != "sent" && !notification.errorMessage.isNullOrBlank()) {
                Spacer(Modifier.height(7.dp))
                Text(
                    notification.errorMessage,
                    color = MaterialTheme.colorScheme.error,
                    style = MaterialTheme.typography.labelMedium,
                )
            }
            if (!notification.createdAt.isNullOrBlank()) {
                Spacer(Modifier.height(8.dp))
                Text(formatMobileDate(notification.createdAt), style = MaterialTheme.typography.labelMedium)
            }
        }
    }
}

@Composable
private fun FeedbackCard(title: String, message: String, isError: Boolean) {
    Card(
        colors = CardDefaults.cardColors(
            containerColor = if (isError) MaterialTheme.colorScheme.errorContainer
            else MaterialTheme.colorScheme.secondaryContainer,
        ),
        shape = RoundedCornerShape(18.dp),
    ) {
        Column(Modifier.padding(16.dp)) {
            Text(title, fontWeight = FontWeight.Bold)
            Spacer(Modifier.height(5.dp))
            Text(message)
        }
    }
}

internal fun formatMobileDate(value: String?): String {
    if (value.isNullOrBlank()) return "-"
    return runCatching {
        OffsetDateTime.parse(value).format(DateTimeFormatter.ofPattern("dd-MM-yyyy HH:mm"))
    }.getOrDefault(value)
}
