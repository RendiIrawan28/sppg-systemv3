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
import androidx.compose.material.icons.outlined.Refresh
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
) {
    LaunchedEffect(Unit) { onLoad() }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Notifikasi & Tugas", fontWeight = FontWeight.SemiBold) },
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
                colors = sppgTopAppBarColors(),
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
