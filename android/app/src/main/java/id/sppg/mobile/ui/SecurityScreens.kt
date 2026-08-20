package id.sppg.mobile.ui

import android.app.DatePickerDialog
import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.net.Uri
import android.provider.MediaStore
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.Image
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
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.outlined.CameraAlt
import androidx.compose.material.icons.outlined.PhotoLibrary
import androidx.compose.material.icons.outlined.Refresh
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.core.content.FileProvider
import id.sppg.mobile.data.remote.SecurityReportItem
import id.sppg.mobile.data.remote.SecurityShiftSummary
import java.io.File
import java.time.LocalDate

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SecurityScreen(
    state: SecurityUiState,
    watermarkProfile: PhotoWatermarkProfile,
    onBack: () -> Unit,
    onLoad: () -> Unit,
    onRefresh: () -> Unit,
    onDateChange: (String) -> Unit,
    onStartShift: () -> Unit,
    onSubmitReport: (
        situation: String,
        gateSecure: Boolean,
        perimeterSecure: Boolean,
        accessActivity: String,
        visitorActivity: String,
        notes: String,
        photo: String,
        onSuccess: () -> Unit,
    ) -> Unit,
    onClearFeedback: () -> Unit,
) {
    LaunchedEffect(Unit) { onLoad() }
    val overview = state.overview
    var situation by remember { mutableStateOf("safe") }
    var gateSecure by remember { mutableStateOf(true) }
    var perimeterSecure by remember { mutableStateOf(true) }
    var accessActivity by remember { mutableStateOf("") }
    var visitorActivity by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    var photo by remember { mutableStateOf("") }
    var preview by remember { mutableStateOf<Bitmap?>(null) }
    var pendingCameraTarget by remember { mutableStateOf<CameraCaptureTarget?>(null) }
    var photoError by remember { mutableStateOf<String?>(null) }
    val context = LocalContext.current
    var showHistory by remember { mutableStateOf(false) }
    var historyDate by remember { mutableStateOf(LocalDate.now()) }

    fun chooseHistoryDate() {
        DatePickerDialog(
            context,
            { _, year, month, day ->
                historyDate = LocalDate.of(year, month + 1, day)
                onDateChange(historyDate.toString())
            },
            historyDate.year,
            historyDate.monthValue - 1,
            historyDate.dayOfMonth,
        ).show()
    }

    fun applyProcessedPhoto(bitmap: Bitmap, dataUri: String) {
        preview = bitmap
        photo = dataUri
        photoError = null
    }

    fun useGalleryPhoto(uri: Uri) {
        photoError = null
        onClearFeedback()

        runCatching {
            watermarkedPhotoDataUri(context, uri, watermarkProfile)
        }.onSuccess { (bitmap, dataUri) ->
            applyProcessedPhoto(bitmap, dataUri)
        }.onFailure { error ->
            // Foto lama tetap dipertahankan agar kegagalan memilih foto baru
            // tidak menghapus laporan yang sudah siap dikirim.
            photoError = error.message
                ?: "Foto tidak dapat dibaca. Coba simpan foto ke perangkat lalu pilih kembali."
        }
    }

    fun useCameraPhoto(target: CameraCaptureTarget) {
        photoError = null
        onClearFeedback()

        runCatching {
            require(target.file.exists() && target.file.length() > 0L) {
                "Kamera tidak menghasilkan file foto."
            }
            watermarkedPhotoDataUri(target.file, watermarkProfile)
        }.onSuccess { (bitmap, dataUri) ->
            applyProcessedPhoto(bitmap, dataUri)
        }.onFailure { error ->
            photoError = error.message ?: "Foto dari kamera tidak dapat diproses."
        }

        target.file.delete()
    }

    val galleryLauncher = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri ->
        if (uri != null) {
            useGalleryPhoto(uri)
        } else {
            photoError = "Tidak ada foto yang dipilih."
        }
    }
    val cameraLauncher = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { success ->
        val target = pendingCameraTarget
        pendingCameraTarget = null

        if (success && target != null) {
            useCameraPhoto(target)
        } else {
            target?.file?.delete()
            photoError = "Pengambilan foto dibatalkan atau kamera tidak menghasilkan foto."
        }
    }

    fun resetForm() {
        situation = "safe"
        gateSecure = true
        perimeterSecure = true
        accessActivity = ""
        visitorActivity = ""
        notes = ""
        photo = ""
        preview = null
        photoError = null
        pendingCameraTarget?.file?.delete()
        pendingCameraTarget = null
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Keamanan", fontWeight = FontWeight.Bold) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Outlined.ArrowBack, contentDescription = "Kembali")
                    }
                },
                actions = {
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
                bottom = 36.dp,
            ),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            if (state.isLoading && overview == null) {
                item {
                    Row(Modifier.fillMaxWidth().padding(30.dp), horizontalArrangement = Arrangement.Center) {
                        CircularProgressIndicator()
                    }
                }
            }

            state.errorMessage?.let { message ->
                item { SecurityFeedback(message, true) }
            }
            state.successMessage?.let { message ->
                item { SecurityFeedback(message, false) }
            }

            if (overview != null) {
                item {
                    WorkHistoryTabs(showHistory) { history ->
                        showHistory = history
                        onDateChange((if (history) historyDate else LocalDate.now()).toString())
                    }
                }
                if (showHistory) {
                    item { HistoryDateSelector(formatMobileDate(state.dateFilter), ::chooseHistoryDate) }
                    val completedShifts = overview.recentShifts.filter { it.status != "active" }
                    if (completedShifts.isEmpty()) {
                        item { HistoryEmptyState() }
                    } else {
                        items(completedShifts, key = { "security-history-${it.id}" }) { shift ->
                            SecurityShiftHistoryCard(shift)
                        }
                    }
                } else {
                val shift = overview.activeShift
                if (shift == null) {
                    item {
                        Card(shape = RoundedCornerShape(22.dp)) {
                            Column(Modifier.padding(20.dp)) {
                                Text("Belum ada shift aktif", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                                Spacer(Modifier.height(8.dp))
                                Text(
                                    "Mulai shift saat bertugas. Sistem membuat empat tugas laporan dengan interval tiga jam.",
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                                Spacer(Modifier.height(18.dp))
                                Button(
                                    onClick = onStartShift,
                                    enabled = overview.canStartShift && !state.isSubmitting,
                                    modifier = Modifier.fillMaxWidth(),
                                ) {
                                    if (state.isSubmitting) CircularProgressIndicator(Modifier.size(20.dp))
                                    else Text("Mulai Shift 12 Jam")
                                }
                            }
                        }
                    }
                } else {
                    item {
                        Card(
                            shape = RoundedCornerShape(22.dp),
                            colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer),
                        ) {
                            Column(Modifier.padding(20.dp)) {
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    Text("Shift aktif", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                                    SppgStatusPill("${shift.reportsCount}/${shift.reportsExpected} laporan")
                                }
                                Spacer(Modifier.height(12.dp))
                                Text("Mulai: ${formatMobileDate(shift.startedAt)}")
                                Text("Selesai terjadwal: ${formatMobileDate(shift.scheduledEndAt)}")
                                Text("Laporan berikutnya: ${formatMobileDate(shift.nextReportDueAt)}")
                            }
                        }
                    }

                    if (shift.reportDue) {
                        item {
                            Text("Buat laporan situasi", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                        }
                        item {
                            Card(shape = RoundedCornerShape(22.dp)) {
                                Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
                                    Text("Situasi", fontWeight = FontWeight.Bold)
                                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                        listOf("safe" to "Aman", "attention" to "Perhatian", "emergency" to "Darurat").forEach { option ->
                                            FilterChip(
                                                selected = situation == option.first,
                                                onClick = { situation = option.first },
                                                label = { Text(option.second) },
                                            )
                                        }
                                    }
                                    SecuritySwitch("Gerbang aman", gateSecure) { gateSecure = it }
                                    SecuritySwitch("Perimeter aman", perimeterSecure) { perimeterSecure = it }
                                    OutlinedTextField(
                                        value = accessActivity,
                                        onValueChange = { accessActivity = it },
                                        label = { Text("Aktivitas akses") },
                                        modifier = Modifier.fillMaxWidth(),
                                        minLines = 2,
                                    )
                                    OutlinedTextField(
                                        value = visitorActivity,
                                        onValueChange = { visitorActivity = it },
                                        label = { Text("Aktivitas tamu") },
                                        modifier = Modifier.fillMaxWidth(),
                                        minLines = 2,
                                    )
                                    OutlinedTextField(
                                        value = notes,
                                        onValueChange = { notes = it },
                                        label = { Text("Catatan") },
                                        modifier = Modifier.fillMaxWidth(),
                                        minLines = 2,
                                    )
                                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                                        OutlinedButton(
                                            onClick = {
                                                photoError = null
                                                if (!hasCameraApplication(context)) {
                                                    photoError = "Aplikasi kamera tidak ditemukan pada perangkat ini."
                                                } else {
                                                    runCatching { createCameraCaptureTarget(context) }
                                                        .onSuccess { target ->
                                                            pendingCameraTarget?.file?.delete()
                                                            pendingCameraTarget = target
                                                            cameraLauncher.launch(target.uri)
                                                        }
                                                        .onFailure { error ->
                                                            pendingCameraTarget = null
                                                            photoError = error.message
                                                                ?: "Penyimpanan sementara untuk kamera tidak dapat dibuat."
                                                        }
                                                }
                                            },
                                            modifier = Modifier.weight(1f),
                                        ) {
                                            Icon(Icons.Outlined.CameraAlt, contentDescription = null)
                                            Spacer(Modifier.size(6.dp))
                                            Text("Kamera")
                                        }
                                        OutlinedButton(
                                            onClick = {
                                                photoError = null
                                                galleryLauncher.launch(
                                                    arrayOf(
                                                        "image/jpeg",
                                                        "image/png",
                                                        "image/webp",
                                                        "image/heic",
                                                        "image/heif",
                                                    ),
                                                )
                                            },
                                            modifier = Modifier.weight(1f),
                                        ) {
                                            Icon(Icons.Outlined.PhotoLibrary, contentDescription = null)
                                            Spacer(Modifier.size(6.dp))
                                            Text("Galeri")
                                        }
                                    }
                                    photoError?.let { message ->
                                        Text(
                                            text = message,
                                            color = MaterialTheme.colorScheme.error,
                                            style = MaterialTheme.typography.bodySmall,
                                        )
                                    }
                                    preview?.let { bitmap ->
                                        Image(
                                            bitmap = bitmap.asImageBitmap(),
                                            contentDescription = "Foto pemeriksaan",
                                            modifier = Modifier.fillMaxWidth().height(180.dp),
                                        )
                                        Text(
                                            text = "Foto berhasil dipilih.",
                                            color = MaterialTheme.colorScheme.primary,
                                            style = MaterialTheme.typography.bodySmall,
                                        )
                                    }
                                    if (photo.isBlank() && photoError == null) {
                                        Text(
                                            text = "Foto pemeriksaan wajib diambil sebelum laporan disimpan.",
                                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                                            style = MaterialTheme.typography.bodySmall,
                                        )
                                    }
                                    Button(
                                        onClick = {
                                            onSubmitReport(
                                                situation,
                                                gateSecure,
                                                perimeterSecure,
                                                accessActivity,
                                                visitorActivity,
                                                notes,
                                                photo,
                                                ::resetForm,
                                            )
                                        },
                                        enabled = !state.isSubmitting && photo.isNotBlank(),
                                        modifier = Modifier.fillMaxWidth(),
                                    ) {
                                        if (state.isSubmitting) CircularProgressIndicator(Modifier.size(20.dp))
                                        else Text("Simpan Laporan")
                                    }
                                }
                            }
                        }
                    } else if (shift.nextReportSequence != null) {
                        item {
                            SecurityFeedback(
                                "Laporan berikutnya baru dapat dibuat pada ${formatMobileDate(shift.nextReportDueAt)}.",
                                false,
                            )
                        }
                    }

                    if (shift.reports.isNotEmpty()) {
                        item { Text("Laporan shift ini", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold) }
                        items(shift.reports, key = { report -> "security-report-${report.id}" }) { report -> SecurityReportCard(report) }
                    }
                }

                if (overview.pendingTasks.isNotEmpty()) {
                    item { Text("Jadwal laporan", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold) }
                    items(overview.pendingTasks, key = { task -> "security-task-${task.id}" }) { task ->
                        Card(shape = RoundedCornerShape(18.dp)) {
                            Row(Modifier.fillMaxWidth().padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
                                Column(Modifier.weight(1f)) {
                                    Text(task.title, fontWeight = FontWeight.Bold)
                                    Text(formatMobileDate(task.dueAt), color = MaterialTheme.colorScheme.onSurfaceVariant)
                                }
                                SppgStatusPill(if (task.isOverdue) "Terlambat" else "Terjadwal")
                            }
                        }
                    }
                }
                }
            }
        }
    }
}

@Composable
private fun SecurityShiftHistoryCard(shift: SecurityShiftSummary) {
    Card(shape = RoundedCornerShape(18.dp), modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(18.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("Shift keamanan", fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                SppgStatusPill(shift.status.replaceFirstChar { it.uppercase() })
            }
            Spacer(Modifier.height(8.dp))
            Text("Mulai: ${formatMobileDate(shift.startedAt)}")
            Text("Selesai: ${formatMobileDate(shift.completedAt)}")
            Text(
                "${shift.reportsCount}/${shift.reportsExpected} laporan situasi",
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

@Composable
private fun SecuritySwitch(label: String, checked: Boolean, onCheckedChange: (Boolean) -> Unit) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Text(label, modifier = Modifier.weight(1f))
        Switch(checked = checked, onCheckedChange = onCheckedChange)
    }
}

@Composable
private fun SecurityFeedback(message: String, isError: Boolean) {
    Card(
        shape = RoundedCornerShape(18.dp),
        colors = CardDefaults.cardColors(
            containerColor = if (isError) MaterialTheme.colorScheme.errorContainer
            else MaterialTheme.colorScheme.secondaryContainer,
        ),
    ) {
        Text(message, modifier = Modifier.padding(16.dp))
    }
}

@Composable
private fun SecurityReportCard(report: SecurityReportItem) {
    Card(shape = RoundedCornerShape(18.dp)) {
        Column(Modifier.padding(16.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("Laporan ke-${report.sequenceNumber}", fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                SppgStatusPill(
                    when (report.situation) {
                        "emergency" -> "Darurat"
                        "attention" -> "Perhatian"
                        else -> "Aman"
                    },
                )
            }
            Spacer(Modifier.height(8.dp))
            Text("Dilaporkan: ${formatMobileDate(report.reportedAt)}")
            Text("Gerbang: ${if (report.gateSecure) "Aman" else "Tidak aman"}")
            Text("Perimeter: ${if (report.perimeterSecure) "Aman" else "Tidak aman"}")
            if (!report.notes.isNullOrBlank()) {
                Spacer(Modifier.height(6.dp))
                Text(report.notes, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            if (!report.photoUrl.isNullOrBlank()) {
                Spacer(Modifier.height(10.dp))
                InAppImageButton(
                    url = report.photoUrl,
                    title = "Foto laporan keamanan ke-${report.sequenceNumber}",
                    label = "Lihat foto laporan",
                    modifier = Modifier.fillMaxWidth(),
                )
            }
        }
    }
}

private fun hasCameraApplication(context: Context): Boolean =
    Intent(MediaStore.ACTION_IMAGE_CAPTURE).resolveActivity(context.packageManager) != null

private data class CameraCaptureTarget(
    val file: File,
    val uri: Uri,
)

private fun createCameraCaptureTarget(context: Context): CameraCaptureTarget {
    val directory = File(context.cacheDir, "images").apply {
        check(exists() || mkdirs()) { "Folder sementara kamera tidak dapat dibuat." }
    }
    val file = File.createTempFile("security_", ".jpg", directory)
    val uri = FileProvider.getUriForFile(
        context,
        "${context.packageName}.fileprovider",
        file,
    )
    return CameraCaptureTarget(file = file, uri = uri)
}
