package id.sppg.mobile.core.notification

import android.Manifest
import android.app.PendingIntent
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import id.sppg.mobile.MainActivity
import id.sppg.mobile.R
import id.sppg.mobile.SppgApplication
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch

class SppgFirebaseMessagingService : FirebaseMessagingService() {
    private val serviceScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    override fun onNewToken(token: String) {
        super.onNewToken(token)
        val repository = (application as SppgApplication).container.notificationRepository
        serviceScope.launch {
            repository.registerDeviceToken(token)
            NotificationRefreshBus.publish()
        }
    }

    override fun onMessageReceived(message: RemoteMessage) {
        super.onMessageReceived(message)
        val data = message.data
        val title = message.notification?.title ?: data["title"] ?: "SPPG Nogotirto"
        val body = message.notification?.body ?: data["body"] ?: "Ada informasi baru untuk Anda."
        val channel = when (data["channel"]) {
            NotificationChannels.REPORT_REMINDERS -> NotificationChannels.REPORT_REMINDERS
            NotificationChannels.REVISIONS -> NotificationChannels.REVISIONS
            NotificationChannels.APPROVALS -> NotificationChannels.APPROVALS
            else -> NotificationChannels.TASKS
        }

        val intent = Intent(this, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP
            data.forEach { (key, value) -> putExtra(key, value) }
        }
        val requestCode = data["notification_id"]?.hashCode()
            ?: message.messageId?.hashCode()
            ?: System.currentTimeMillis().hashCode()
        val pendingIntent = PendingIntent.getActivity(
            this,
            requestCode,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )

        val notification = NotificationCompat.Builder(this, channel)
            .setSmallIcon(R.drawable.ic_notification_sppg)
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setCategory(NotificationCompat.CATEGORY_REMINDER)
            .setAutoCancel(true)
            .setContentIntent(pendingIntent)
            .build()

        val notificationAllowed = Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU ||
            ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) ==
            PackageManager.PERMISSION_GRANTED
        if (notificationAllowed) {
            NotificationManagerCompat.from(this).notify(requestCode, notification)
        }
        NotificationRefreshBus.publish()
    }

    override fun onDeletedMessages() {
        super.onDeletedMessages()
        NotificationRefreshBus.publish()
    }
}
