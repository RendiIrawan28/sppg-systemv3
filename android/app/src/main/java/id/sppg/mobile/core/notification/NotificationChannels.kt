package id.sppg.mobile.core.notification

import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import android.os.Build

object NotificationChannels {
    const val TASKS = "sppg_tasks"
    const val REPORT_REMINDERS = "sppg_report_reminders"
    const val REVISIONS = "sppg_revisions"
    const val APPROVALS = "sppg_approvals"

    fun create(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return

        val manager = context.getSystemService(NotificationManager::class.java)
        manager.createNotificationChannels(
            listOf(
                NotificationChannel(
                    TASKS,
                    "Tugas operasional",
                    NotificationManager.IMPORTANCE_DEFAULT,
                ).apply {
                    description = "Pemberitahuan pekerjaan operasional yang perlu ditangani."
                },
                NotificationChannel(
                    REPORT_REMINDERS,
                    "Pengingat laporan",
                    NotificationManager.IMPORTANCE_HIGH,
                ).apply {
                    description = "Pengingat laporan berkala dan laporan yang mendekati batas waktu."
                },
                NotificationChannel(
                    REVISIONS,
                    "Permintaan revisi",
                    NotificationManager.IMPORTANCE_HIGH,
                ).apply {
                    description = "Dokumen atau laporan yang dikembalikan untuk diperbaiki."
                },
                NotificationChannel(
                    APPROVALS,
                    "Persetujuan",
                    NotificationManager.IMPORTANCE_DEFAULT,
                ).apply {
                    description = "Status pengajuan dan persetujuan dokumen SPPG."
                },
            ),
        )
    }
}
