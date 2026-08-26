package id.sppg.mobile.ui

import android.app.DatePickerDialog
import android.app.TimePickerDialog
import android.content.Context
import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.automirrored.outlined.Assignment
import androidx.compose.material.icons.outlined.CalendarMonth
import androidx.compose.material.icons.outlined.Add
import androidx.compose.material.icons.outlined.Person
import androidx.compose.material.icons.outlined.Refresh
import androidx.compose.material.icons.outlined.PhotoCamera
import androidx.compose.material.icons.outlined.PictureAsPdf
import androidx.compose.material.icons.outlined.Share
import androidx.compose.material.icons.outlined.Edit
import androidx.compose.material.icons.outlined.Delete
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuAnchorType
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.ui.unit.dp
import androidx.core.content.FileProvider
import id.sppg.mobile.data.remote.OperationalField
import id.sppg.mobile.data.remote.OperationalAction
import id.sppg.mobile.data.remote.OperationalRelationAction
import id.sppg.mobile.data.remote.OperationalRecord
import id.sppg.mobile.data.remote.OperationalSection
import id.sppg.mobile.data.remote.OperationalSectionItem
import com.google.gson.Gson
import java.io.File
import java.time.LocalDate
import java.time.LocalDateTime
import java.time.LocalTime
import java.time.format.DateTimeFormatter
import java.util.Locale

private val activeAcrossDatesModules = setOf(
    "persiapan",
    "pengolahan",
    "pemorsian",
    "distribusi",
    "pencucian",
    "kebersihan",
)

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun OperationalRecordListScreen(
    state: OperationalUiState,
    module: String,
    moduleLabel: String,
    onBack: () -> Unit,
    onLoad: (String) -> Unit,
    onRefresh: () -> Unit,
    onFilterChange: (String?, String?) -> Unit,
    onLoadMore: () -> Unit,
    onRecordClick: (Long) -> Unit,
    onCreate: () -> Unit,
) {
    LaunchedEffect(module) { onLoad(module) }
    val context = LocalContext.current
    var showHistory by remember(module) { mutableStateOf(false) }
    var historyDate by remember(module) { mutableStateOf(LocalDate.now()) }
    val displayedRecords = state.records.filter { it.isHistory == showHistory }
    val selectedDateLabel = operationalDateDisplay(
        (state.dateFilter ?: LocalDate.now().format(apiDateFormatter)),
        "date",
    )

    fun selectHistoryDate() {
        val currentDate = historyDate
        DatePickerDialog(
            context,
            { _, year, month, day ->
                historyDate = LocalDate.of(year, month + 1, day)
                onFilterChange(null, historyDate.format(apiDateFormatter))
            },
            currentDate.year,
            currentDate.monthValue - 1,
            currentDate.dayOfMonth,
        ).show()
    }

    Scaffold(
        containerColor = MaterialTheme.colorScheme.background,
        topBar = {
            TopAppBar(
                title = {
                    Text(moduleLabel, fontWeight = FontWeight.Bold)
                },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Outlined.ArrowBack, contentDescription = "Kembali")
                    }
                },
                actions = {
                    if (!showHistory && state.modules.firstOrNull { it.slug == module }?.canCreate == true) {
                        if (module == "pengolahan" || module == "pemorsian") {
                            TextButton(onClick = onCreate) {
                                Text(if (module == "pengolahan") "Mulai produksi" else "Mulai Pemorsian")
                            }
                        } else {
                            IconButton(onClick = onCreate) {
                                Icon(Icons.Outlined.Add, contentDescription = "Tambah data")
                            }
                        }
                    }
                    IconButton(onClick = onRefresh, enabled = !state.isLoading) {
                        Icon(Icons.Outlined.Refresh, contentDescription = "Muat ulang")
                    }
                },
                colors = sppgTopAppBarColors(),
            )
        },
    ) { innerPadding ->
        when {
            state.isLoading && state.records.isEmpty() -> OperationalLoading(innerPadding)
            state.errorMessage != null && state.records.isEmpty() -> OperationalError(
                message = state.errorMessage,
                padding = innerPadding,
                onRetry = onRefresh,
            )
            else -> LazyColumn(
                modifier = Modifier.fillMaxSize(),
                contentPadding = PaddingValues(
                    start = 20.dp,
                    top = innerPadding.calculateTopPadding() + 12.dp,
                    end = 20.dp,
                    bottom = 32.dp,
                ),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                item {
                    OperationalModuleHeader(
                        module = module,
                        label = if (showHistory) "Riwayat · $selectedDateLabel" else moduleLabel,
                        count = displayedRecords.size,
                    )
                }
                item {
                    WorkHistoryTabs(showHistory) { history ->
                        showHistory = history
                        onFilterChange(null, if (module in activeAcrossDatesModules && !history) null else {
                            (if (history) historyDate else LocalDate.now()).format(apiDateFormatter)
                        })
                    }
                }
                if (showHistory) {
                    item { HistoryDateSelector(selectedDateLabel, ::selectHistoryDate) }
                }
                if (!showHistory && module == "distribusi") {
                    item {
                        Card(
                            colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer),
                            shape = RoundedCornerShape(18.dp),
                        ) {
                            Text(
                                "Pilih rute, lengkapi kendaraan, lalu ikuti perjalanan sampai kembali ke SPPG.",
                                modifier = Modifier.padding(16.dp),
                                color = MaterialTheme.colorScheme.onPrimaryContainer,
                            )
                        }
                    }
                }
                if (displayedRecords.isEmpty()) {
                    item {
                        if (showHistory) HistoryEmptyState()
                        else OperationalEmptyCard("Belum ada pekerjaan untuk hari ini")
                    }
                } else {
                    item { OperationalListSectionTitle(if (showHistory) "PEKERJAAN SELESAI" else "PERLU DIKERJAKAN") }
                    items(displayedRecords, key = { it.id }) { record ->
                        OperationalRecordCard(
                            module = module,
                            record = record,
                            onClick = { onRecordClick(record.id) },
                        )
                    }
                }
                if (state.currentPage < state.lastPage) {
                    item(key = "load-more-$module-${state.currentPage}") {
                        OutlinedButton(
                            onClick = onLoadMore,
                            enabled = !state.isLoadingMore,
                            modifier = Modifier.fillMaxWidth().height(50.dp),
                            shape = RoundedCornerShape(16.dp),
                        ) {
                            if (state.isLoadingMore) {
                                CircularProgressIndicator(
                                    modifier = Modifier.size(20.dp),
                                    strokeWidth = 2.dp,
                                )
                                Spacer(Modifier.width(8.dp))
                            }
                            Text("Muat data berikutnya")
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun OperationalEmptyCard(message: String) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(18.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
    ) {
        Text(
            message,
            modifier = Modifier.padding(22.dp),
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

@Composable
private fun OperationalListSectionTitle(label: String) {
    Text(
        label,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
        style = MaterialTheme.typography.labelMedium,
        fontWeight = FontWeight.Bold,
    )
}

@Composable
private fun OperationalRecordCard(module: String, record: OperationalRecord, onClick: () -> Unit) {
    Card(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onClick),
        shape = RoundedCornerShape(22.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
    ) {
        Column(modifier = Modifier.padding(18.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(14.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                ModuleIcon(module, Modifier.size(42.dp))
                Column(modifier = Modifier.weight(1f)) {
                    if (!isTechnicalRecordNumber(record.number)) {
                        Text(
                            record.number,
                            color = MaterialTheme.colorScheme.primary,
                            style = MaterialTheme.typography.labelLarge,
                            fontWeight = FontWeight.Bold,
                        )
                        Spacer(Modifier.height(3.dp))
                    }
                    Text(record.title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                }
            }
            Spacer(Modifier.height(12.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                SppgStatusPill(record.stateLabel ?: record.statusLabel ?: "-")
                if (!record.statusLabel.isNullOrBlank() && record.statusLabel != record.stateLabel) {
                    SppgStatusPill(record.statusLabel)
                }
            }
            if (!record.subtitle.isNullOrBlank()) {
                Spacer(Modifier.height(5.dp))
                Text(
                    record.subtitle,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    style = MaterialTheme.typography.bodyMedium,
                )
            }
            if (!record.date.isNullOrBlank()) {
                Spacer(Modifier.height(10.dp))
                OperationalInfoLine(Icons.Outlined.CalendarMonth, formatOperationalDate(record.date))
            }
            if (!record.assignee.isNullOrBlank()) {
                Spacer(Modifier.height(6.dp))
                OperationalInfoLine(Icons.Outlined.Person, record.assignee)
            }
            if (record.metrics.isNotEmpty()) {
                Spacer(Modifier.height(14.dp))
                Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    record.metrics.take(2).forEach { metric ->
                        Column(
                            modifier = Modifier
                                .weight(1f)
                                .background(MaterialTheme.colorScheme.surfaceVariant, RoundedCornerShape(12.dp))
                                .padding(10.dp),
                        ) {
                            Text(metric.value, fontWeight = FontWeight.Bold)
                            Text(
                                metric.label,
                                style = MaterialTheme.typography.labelMedium,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun OperationalModuleHeader(module: String, label: String, count: Int) {
    val visual = moduleVisual(module)
    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(18.dp),
        colors = CardDefaults.cardColors(containerColor = visual.container),
    ) {
        Row(
            modifier = Modifier.padding(16.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            ModuleIcon(module, Modifier.size(44.dp))
            Spacer(Modifier.width(13.dp))
            Column(modifier = Modifier.weight(1f)) {
                Text(
                    label,
                    color = visual.color,
                    style = MaterialTheme.typography.titleLarge,
                    fontWeight = FontWeight.SemiBold,
                )
                Spacer(Modifier.height(2.dp))
                Text(
                    "$count pekerjaan ditampilkan",
                    color = visual.color.copy(alpha = 0.78f),
                    style = MaterialTheme.typography.bodyMedium,
                )
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun OperationalRecordDetailScreen(
    state: OperationalUiState,
    module: String,
    moduleLabel: String,
    recordId: Long,
    onBack: () -> Unit,
    onLoad: (String, Long) -> Unit,
    onEdit: () -> Unit,
    onDelete: () -> Unit,
    watermarkProfile: PhotoWatermarkProfile,
    onAction: (String, String?, Map<String, String?>, Map<String, String>) -> Unit,
    onOpenDocument: (String?) -> Unit,
    onShareDocument: (String?) -> Unit,
    onRelationCreate: (OperationalSection) -> Unit,
    onRelationEdit: (OperationalSection, OperationalSectionItem) -> Unit,
    onRelationDelete: (OperationalSection, OperationalSectionItem) -> Unit,
    onRelationAction: (
        OperationalSection,
        OperationalSectionItem,
        String,
        String?,
        Map<String, String?>,
        Map<String, String>,
    ) -> Unit,
) {
    LaunchedEffect(module, recordId) { onLoad(module, recordId) }
    var confirmDelete by remember { mutableStateOf(false) }
    var selectedAction by remember { mutableStateOf<OperationalAction?>(null) }
    var actionNotes by remember { mutableStateOf("") }
    var actionValues by remember { mutableStateOf<Map<String, String?>>(emptyMap()) }
    var actionFiles by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var selectedRelationAction by remember {
        mutableStateOf<Triple<OperationalSection, OperationalSectionItem, OperationalRelationAction>?>(null)
    }
    var relationActionNotes by remember { mutableStateOf("") }
    var relationActionValues by remember { mutableStateOf<Map<String, String?>>(emptyMap()) }
    var relationActionFiles by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var relationToDelete by remember {
        mutableStateOf<Pair<OperationalSection, OperationalSectionItem>?>(null)
    }

    if (confirmDelete) {
        AlertDialog(
            onDismissRequest = { confirmDelete = false },
            title = { Text("Hapus data ini?") },
            text = { Text("Data yang sudah dihapus tidak dapat dikembalikan.") },
            confirmButton = {
                TextButton(onClick = {
                    confirmDelete = false
                    onDelete()
                }) { Text("Hapus", color = MaterialTheme.colorScheme.error) }
            },
            dismissButton = {
                TextButton(onClick = { confirmDelete = false }) { Text("Batal") }
            },
        )
    }
    selectedAction?.let { action ->
        val requiredReady = action.fields.orEmpty().all { field ->
            if (!field.required) true
            else if (field.type == "file") actionFiles[field.key].isNullOrBlank().not()
            else actionValues[field.key].isNullOrBlank().not()
        }
        AlertDialog(
            onDismissRequest = {
                selectedAction = null
                actionValues = emptyMap()
                actionFiles = emptyMap()
            },
            title = { Text(action.label) },
            text = {
                LazyColumn(
                    modifier = Modifier.heightIn(max = 520.dp),
                    verticalArrangement = Arrangement.spacedBy(12.dp),
                ) {
                    item { Text("Pastikan data pekerjaan sudah benar sebelum melanjutkan.") }
                    items(action.fields.orEmpty(), key = { "action-field-${it.key}" }) { field ->
                        OperationalFormInput(
                            field = field,
                            value = actionValues[field.key],
                            onValueChange = { value -> actionValues = actionValues + (field.key to value) },
                            hasSelectedFile = actionFiles.containsKey(field.key),
                            watermarkProfile = watermarkProfile,
                            onSelectFile = { data -> actionFiles = actionFiles + (field.key to data) },
                        )
                    }
                    if (action.notesRequired || action.fields.orEmpty().none { it.key == "notes" }) {
                        item {
                            OutlinedTextField(
                                value = actionNotes,
                                onValueChange = { actionNotes = it },
                                modifier = Modifier.fillMaxWidth(),
                                label = { Text(if (action.notesRequired) "Alasan *" else "Catatan (opsional)") },
                                minLines = 3,
                            )
                        }
                    }
                }
            },
            confirmButton = {
                Button(
                    enabled = !state.isSaving
                        && requiredReady
                        && (!action.notesRequired || actionNotes.isNotBlank()),
                    onClick = {
                        onAction(
                            action.key,
                            actionNotes.trim().ifBlank { null },
                            actionValues,
                            actionFiles,
                        )
                        selectedAction = null
                        actionNotes = ""
                        actionValues = emptyMap()
                        actionFiles = emptyMap()
                    },
                ) { Text("Ya, lanjutkan") }
            },
            dismissButton = {
                TextButton(onClick = {
                    selectedAction = null
                    actionValues = emptyMap()
                    actionFiles = emptyMap()
                }) { Text("Batal") }
            },
        )
    }
    selectedRelationAction?.let { (section, item, action) ->
        val requiredReady = action.fields.orEmpty().all { field ->
            if (!field.required) true
            else if (field.type == "file") {
                relationActionFiles[field.key].isNullOrBlank().not() || !field.value.isNullOrBlank()
            } else relationActionValues[field.key].isNullOrBlank().not()
        }
        AlertDialog(
            onDismissRequest = { selectedRelationAction = null },
            title = { Text(action.label) },
            text = {
                LazyColumn(
                    modifier = Modifier.heightIn(max = 560.dp),
                    verticalArrangement = Arrangement.spacedBy(12.dp),
                ) {
                    item {
                        Text(
                            item.title,
                            fontWeight = FontWeight.Bold,
                            color = MaterialTheme.colorScheme.primary,
                        )
                    }
                    items(action.fields.orEmpty(), key = { "relation-action-${it.key}" }) { field ->
                        OperationalFormInput(
                            field = field,
                            value = relationActionValues[field.key],
                            onValueChange = { value ->
                                relationActionValues = relationActionValues + (field.key to value)
                            },
                            hasSelectedFile = relationActionFiles.containsKey(field.key),
                            watermarkProfile = watermarkProfile,
                            onSelectFile = { data ->
                                relationActionFiles = relationActionFiles + (field.key to data)
                            },
                        )
                    }
                    if (action.notesRequired) {
                        item {
                            OutlinedTextField(
                                value = relationActionNotes,
                                onValueChange = { relationActionNotes = it },
                                modifier = Modifier.fillMaxWidth(),
                                label = { Text("Catatan *") },
                                minLines = 3,
                            )
                        }
                    }
                }
            },
            confirmButton = {
                Button(
                    enabled = !state.isSaving && requiredReady
                        && (!action.notesRequired || relationActionNotes.isNotBlank()),
                    onClick = {
                        onRelationAction(
                            section,
                            item,
                            action.key,
                            relationActionNotes.trim().ifBlank { null },
                            relationActionValues,
                            relationActionFiles,
                        )
                        selectedRelationAction = null
                    },
                ) { Text("Simpan") }
            },
            dismissButton = {
                TextButton(onClick = { selectedRelationAction = null }) { Text("Batal") }
            },
        )
    }
    relationToDelete?.let { (section, item) ->
        AlertDialog(
            onDismissRequest = { relationToDelete = null },
            title = { Text("Hapus rincian ini?") },
            text = { Text(item.title) },
            confirmButton = {
                TextButton(onClick = {
                    onRelationDelete(section, item)
                    relationToDelete = null
                }) { Text("Hapus", color = MaterialTheme.colorScheme.error) }
            },
            dismissButton = {
                TextButton(onClick = { relationToDelete = null }) { Text("Batal") }
            },
        )
    }

    Scaffold(
        containerColor = MaterialTheme.colorScheme.background,
        topBar = {
            TopAppBar(
                title = { Text("Rincian $moduleLabel", fontWeight = FontWeight.Bold) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Outlined.ArrowBack, contentDescription = "Kembali")
                    }
                },
                colors = sppgTopAppBarColors(),
            )
        },
    ) { innerPadding ->
        when {
            state.isLoading -> OperationalLoading(innerPadding)
            state.errorMessage != null && state.selectedRecord == null -> OperationalError(
                message = state.errorMessage,
                padding = innerPadding,
                onRetry = { onLoad(module, recordId) },
            )
            state.selectedRecord != null -> OperationalDetailContent(
                record = state.selectedRecord,
                module = module,
                padding = innerPadding,
                onEdit = onEdit,
                onDelete = { confirmDelete = true },
                isSaving = state.isSaving,
                errorMessage = state.errorMessage,
                successMessage = state.successMessage,
                onAction = {
                    selectedAction = it
                    actionNotes = ""
                    actionValues = it.fields.orEmpty().associate { field -> field.key to field.value }
                    actionFiles = emptyMap()
                },
                onOpenDocument = onOpenDocument,
                onShareDocument = onShareDocument,
                onRelationCreate = onRelationCreate,
                onRelationEdit = onRelationEdit,
                onRelationDelete = { section, item -> relationToDelete = section to item },
                onRelationAction = { section, item, action ->
                    if (action.fields.isNullOrEmpty() && !action.notesRequired) {
                        onRelationAction(section, item, action.key, null, emptyMap(), emptyMap())
                    } else {
                        selectedRelationAction = Triple(section, item, action)
                        relationActionNotes = ""
                        relationActionValues = action.fields.orEmpty().associate { field ->
                            field.key to field.value
                        }
                        relationActionFiles = emptyMap()
                    }
                },
            )
        }
    }
}

@Composable
private fun DistributionProgressCard(record: OperationalRecord) {
    val steps = listOf(
        "Pilih rute",
        "Rute dipilih",
        "Memuat",
        "Dalam perjalanan",
        "Semua tujuan selesai",
        "Kembali ke SPPG",
    )
    val currentIndex = when (record.state) {
        "planned" -> 0
        "assigned" -> 1
        "loaded" -> 2
        "departed" -> 3
        "destinations_completed" -> 4
        "returned" -> 5
        else -> 0
    }
    Card(
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surfaceVariant),
        shape = RoundedCornerShape(20.dp),
    ) {
        Column(
            modifier = Modifier.fillMaxWidth().padding(18.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Text("PROGRES PERJALANAN", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.Bold)
            steps.forEachIndexed { index, label ->
                val completed = index < currentIndex || record.state == "returned"
                val current = index == currentIndex && record.state != "returned"
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        when {
                            completed -> "✓"
                            current -> "●"
                            else -> "○"
                        },
                        color = if (completed || current) MaterialTheme.colorScheme.primary
                        else MaterialTheme.colorScheme.onSurfaceVariant,
                        fontWeight = FontWeight.Bold,
                    )
                    Spacer(Modifier.width(10.dp))
                    Text(
                        label,
                        fontWeight = if (current) FontWeight.Bold else FontWeight.Normal,
                        color = if (current) MaterialTheme.colorScheme.onSurface
                        else MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
        }
    }
}

private fun distributionActionHint(action: String): String = when (action) {
    "claim" -> "Isi kendaraan dan nama kernet untuk mengambil rute ini. Nama driver mengikuti akun yang sedang digunakan."
    "load" -> "Pastikan seluruh porsi pada rute sudah masuk kendaraan sebelum mulai memuat."
    "depart" -> "Catat waktu dan suhu makanan saat kendaraan berangkat."
    "finish" -> "Gunakan setelah seluruh tujuan selesai dan kendaraan benar-benar tiba kembali di SPPG."
    "submit" -> "Seluruh rute sudah kembali. Ajukan laporan distribusi untuk diperiksa."
    else -> "Periksa data rute sebelum melanjutkan tahap berikutnya."
}

@Composable
private fun WashingProgressCard(record: OperationalRecord) {
    val steps = listOf("Terima ompreng", "Catat sisa makanan", "Proses pencucian", "Siap digunakan")
    val currentIndex = when (record.state) {
        "planned" -> 0
        "received" -> 1
        "washing", "completed" -> 2
        "ready" -> 3
        else -> 0
    }
    Card(
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surfaceVariant),
        shape = RoundedCornerShape(20.dp),
    ) {
        Column(
            modifier = Modifier.fillMaxWidth().padding(18.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Text("PROGRES PENCUCIAN", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.Bold)
            steps.forEachIndexed { index, label ->
                val completed = index < currentIndex || record.state == "ready"
                val current = index == currentIndex && record.state != "ready"
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        when {
                            completed -> "✓"
                            current -> "●"
                            else -> "○"
                        },
                        color = if (completed || current) MaterialTheme.colorScheme.primary
                        else MaterialTheme.colorScheme.onSurfaceVariant,
                        fontWeight = FontWeight.Bold,
                    )
                    Spacer(Modifier.width(10.dp))
                    Text(
                        label,
                        fontWeight = if (current) FontWeight.Bold else FontWeight.Normal,
                        color = if (current) MaterialTheme.colorScheme.onSurface
                        else MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
        }
    }
}

@Composable
private fun WashingNextStepCard(
    actions: List<OperationalAction>,
    isSaving: Boolean,
    onAction: (OperationalAction) -> Unit,
) {
    Card(
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer),
        shape = RoundedCornerShape(20.dp),
    ) {
        Column(
            modifier = Modifier.fillMaxWidth().padding(18.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Text("LANGKAH BERIKUTNYA", fontWeight = FontWeight.Bold)
            Text(
                washingActionHint(actions.first().key),
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            actions.forEach { action ->
                Button(
                    onClick = { onAction(action) },
                    enabled = !isSaving,
                    modifier = Modifier.fillMaxWidth().height(54.dp),
                    shape = RoundedCornerShape(16.dp),
                ) { Text(action.label, fontWeight = FontWeight.Bold) }
            }
        }
    }
}

private fun washingActionHint(action: String): String = when (action) {
    "receive" -> "Hitung ompreng yang benar-benar diterima. Catatan wajib jika jumlah atau kondisi berbeda."
    "waste_none", "waste_present" -> "Pilih kondisi sisa makanan pada ompreng sebelum pencucian dimulai."
    "waste" -> "Tambahkan rincian limbah dan fotonya, lalu lengkapi identitas kedua pihak untuk berita acara."
    "start" -> "Pencatatan sisa makanan sudah selesai. Mulai proses untuk membuka checklist dan dokumentasi hasil."
    "complete" -> "Pastikan seluruh checklist wajib selesai dan minimal satu foto hasil sudah tersedia."
    "submit" -> "Seluruh sesi Pencucian tanggal ini sudah siap untuk diajukan."
    else -> "Periksa seluruh data sebelum melanjutkan tahap Pencucian."
}

@Composable
private fun OperationalDetailContent(
    record: OperationalRecord,
    module: String,
    padding: PaddingValues,
    onEdit: () -> Unit,
    onDelete: () -> Unit,
    isSaving: Boolean,
    errorMessage: String?,
    successMessage: String?,
    onAction: (OperationalAction) -> Unit,
    onOpenDocument: (String?) -> Unit,
    onShareDocument: (String?) -> Unit,
    onRelationCreate: (OperationalSection) -> Unit,
    onRelationEdit: (OperationalSection, OperationalSectionItem) -> Unit,
    onRelationDelete: (OperationalSection, OperationalSectionItem) -> Unit,
    onRelationAction: (OperationalSection, OperationalSectionItem, OperationalRelationAction) -> Unit,
) {
    val capabilities = record.capabilities
    val preparationReturnSection = record.sections.orEmpty()
        .firstOrNull { module == "persiapan" && it.key == "returns" }
    val receiptActions = if (module == "gudang") {
        capabilities?.actions.orEmpty().filter { it.key == "receive" }
    } else emptyList()
    val leftoverActions = capabilities?.actions.orEmpty().filter {
        it.key == "set_leftover_none" || it.key == "set_leftover_present"
    }
    val remainingActions = capabilities?.actions.orEmpty().filterNot {
        it.key == "receive" || it.key == "set_leftover_none" || it.key == "set_leftover_present"
    }
    val washingEarlyActions = capabilities?.actions.orEmpty().filter {
        it.key in setOf("receive", "waste_none", "waste_present", "start")
    }
    val washingFinalActions = capabilities?.actions.orEmpty().filterNot {
        it.key in setOf("receive", "waste_none", "waste_present", "start")
    }
    LazyColumn(
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(
            start = 20.dp,
            top = padding.calculateTopPadding() + 12.dp,
            end = 20.dp,
            bottom = 32.dp,
        ),
        verticalArrangement = Arrangement.spacedBy(14.dp),
    ) {
        item {
            Card(
                colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primary),
                shape = RoundedCornerShape(24.dp),
            ) {
                Column(modifier = Modifier.padding(20.dp)) {
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        if (!isTechnicalRecordNumber(record.number)) {
                            Text(
                                record.number,
                                modifier = Modifier.weight(1f),
                                fontWeight = FontWeight.Bold,
                                color = Color.White,
                            )
                        } else {
                            Spacer(Modifier.weight(1f))
                        }
                        SppgStatusPill(record.stateLabel ?: record.statusLabel ?: "-")
                    }
                    Spacer(Modifier.height(14.dp))
                    Text(
                        record.title,
                        style = MaterialTheme.typography.headlineSmall,
                        fontWeight = FontWeight.Bold,
                        color = Color.White,
                    )
                    if (!record.date.isNullOrBlank()) {
                        Spacer(Modifier.height(8.dp))
                        Text(formatOperationalDate(record.date), color = Color.White.copy(alpha = 0.78f))
                    }
                }
            }
        }
        if (module == "distribusi") {
            item { DistributionProgressCard(record) }
        }
        if (module == "pencucian") {
            item { WashingProgressCard(record) }
        }
        if (!record.fields.isNullOrEmpty()) {
            if (module == "pemorsian") {
                item { PortioningProgressCard(record.fields) }
            }
            item {
                OperationalFieldCard(
                    if (module == "distribusi") "Informasi rute" else "Informasi pekerjaan",
                    record.fields,
                )
            }
        }
        if (module == "pemorsian" && leftoverActions.isNotEmpty()) {
            item {
                PortioningLeftoverChoiceCard(
                    fields = record.fields.orEmpty(),
                    actions = leftoverActions,
                    isSaving = isSaving,
                    onAction = onAction,
                )
            }
        }
        if (!successMessage.isNullOrBlank()) {
            item {
                Card(colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer)) {
                    Text(successMessage, modifier = Modifier.padding(16.dp), fontWeight = FontWeight.SemiBold)
                }
            }
        }
        if (!errorMessage.isNullOrBlank()) {
            item {
                Card(colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.errorContainer)) {
                    Text(
                        errorMessage,
                        modifier = Modifier.padding(16.dp),
                        color = MaterialTheme.colorScheme.onErrorContainer,
                    )
                }
            }
        }
        if (preparationReturnSection?.canCreate == true) {
            item {
                PreparationReturnCallout(
                    returnCount = preparationReturnSection.items.size,
                    onCreate = { onRelationCreate(preparationReturnSection) },
                )
            }
        }
        if (receiptActions.isNotEmpty()) {
            item {
                Card(
                    colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer),
                    shape = RoundedCornerShape(20.dp),
                ) {
                    Column(
                        modifier = Modifier.fillMaxWidth().padding(18.dp),
                        verticalArrangement = Arrangement.spacedBy(10.dp),
                    ) {
                        Text("SEMUA BARANG SUDAH DIPERIKSA?", fontWeight = FontWeight.Bold)
                        Text(
                            "Pastikan jumlah, hasil QC, dan foto dokumentasi sudah benar sebelum stok dimasukkan.",
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                        receiptActions.forEach { action ->
                            Button(
                                onClick = { onAction(action) },
                                enabled = !isSaving,
                                modifier = Modifier.fillMaxWidth().height(54.dp),
                                shape = RoundedCornerShape(16.dp),
                            ) { Text(action.label, fontWeight = FontWeight.Bold) }
                        }
                    }
                }
            }
        }
        if (module == "distribusi" && remainingActions.isNotEmpty()) {
            item {
                Card(
                    colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer),
                    shape = RoundedCornerShape(20.dp),
                ) {
                    Column(
                        modifier = Modifier.fillMaxWidth().padding(18.dp),
                        verticalArrangement = Arrangement.spacedBy(10.dp),
                    ) {
                        Text("LANGKAH BERIKUTNYA", fontWeight = FontWeight.Bold)
                        Text(
                            distributionActionHint(remainingActions.first().key),
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                        remainingActions.forEach { action ->
                            Button(
                                onClick = { onAction(action) },
                                enabled = !isSaving,
                                modifier = Modifier.fillMaxWidth().height(54.dp),
                                shape = RoundedCornerShape(16.dp),
                            ) { Text(action.label, fontWeight = FontWeight.Bold) }
                        }
                    }
                }
            }
        }
        if (module == "pencucian" && washingEarlyActions.isNotEmpty()) {
            item {
                WashingNextStepCard(
                    actions = washingEarlyActions,
                    isSaving = isSaving,
                    onAction = onAction,
                )
            }
        }
        val sections = record.sections.orEmpty()
        if (sections.isEmpty()) {
            item {
                Text(
                    "Belum ada rincian tambahan pada pekerjaan ini.",
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        } else {
            items(sections, key = { it.key }) { section ->
                OperationalSectionCard(
                    section = section,
                    onCreate = { onRelationCreate(section) },
                    onEdit = { onRelationEdit(section, it) },
                    onDelete = { onRelationDelete(section, it) },
                    onAction = { item, action -> onRelationAction(section, item, action) },
                )
            }
        }
        if (module == "pencucian" && washingFinalActions.isNotEmpty()) {
            item {
                WashingNextStepCard(
                    actions = washingFinalActions,
                    isSaving = isSaving,
                    onAction = onAction,
                )
            }
        }
        if (remainingActions.isNotEmpty() && module != "distribusi" && module != "pencucian") {
            item {
                Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text("LANGKAH BERIKUTNYA", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.Bold)
                    remainingActions.forEach { action ->
                        Button(
                            onClick = { onAction(action) },
                            enabled = !isSaving,
                            modifier = Modifier.fillMaxWidth().height(54.dp),
                            shape = RoundedCornerShape(16.dp),
                        ) { Text(action.label, fontWeight = FontWeight.Bold) }
                    }
                }
            }
        }
        if (capabilities?.canViewDocument == true) {
            item {
                Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text("EKSPOR LAPORAN", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.Bold)
                    if (module == "pengolahan") {
                        Text("Monitoring Produksi Harian", style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.Bold)
                    }
                    Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        OutlinedButton(
                            onClick = { onOpenDocument(null) }, enabled = !isSaving,
                            modifier = Modifier.weight(1f).height(52.dp), shape = RoundedCornerShape(16.dp),
                        ) {
                            Icon(Icons.Outlined.PictureAsPdf, contentDescription = null)
                            Spacer(Modifier.width(6.dp))
                            Text("Buka PDF", fontWeight = FontWeight.Bold)
                        }
                        Button(
                            onClick = { onShareDocument(null) }, enabled = !isSaving,
                            modifier = Modifier.weight(1f).height(52.dp), shape = RoundedCornerShape(16.dp),
                        ) {
                            Icon(Icons.Outlined.Share, contentDescription = null)
                            Spacer(Modifier.width(6.dp))
                            Text("Bagikan", fontWeight = FontWeight.Bold)
                        }
                    }
                    if (module == "pengolahan") {
                        Text("Pemantauan Suhu Pengolahan & Penyajian Harian", style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.Bold)
                        Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                            OutlinedButton(
                                onClick = { onOpenDocument("temperature") }, enabled = !isSaving,
                                modifier = Modifier.weight(1f).height(52.dp), shape = RoundedCornerShape(16.dp),
                            ) {
                                Icon(Icons.Outlined.PictureAsPdf, contentDescription = null)
                                Spacer(Modifier.width(6.dp))
                                Text("Buka PDF", fontWeight = FontWeight.Bold)
                            }
                            Button(
                                onClick = { onShareDocument("temperature") }, enabled = !isSaving,
                                modifier = Modifier.weight(1f).height(52.dp), shape = RoundedCornerShape(16.dp),
                            ) {
                                Icon(Icons.Outlined.Share, contentDescription = null)
                                Spacer(Modifier.width(6.dp))
                                Text("Bagikan", fontWeight = FontWeight.Bold)
                            }
                        }
                    }
                }
            }
        }
        if (capabilities?.canUpdate == true || capabilities?.canDelete == true) {
            item {
                Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    if (capabilities.canUpdate) {
                        Button(
                            onClick = onEdit,
                            modifier = Modifier.fillMaxWidth().height(52.dp),
                            shape = RoundedCornerShape(16.dp),
                        ) { Text("Ubah data", fontWeight = FontWeight.Bold) }
                    }
                    if (capabilities.canDelete) {
                        OutlinedButton(
                            onClick = onDelete,
                            modifier = Modifier.fillMaxWidth().height(52.dp),
                            shape = RoundedCornerShape(16.dp),
                        ) {
                            Text("Hapus data", color = MaterialTheme.colorScheme.error)
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun PreparationReturnCallout(returnCount: Int, onCreate: () -> Unit) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(20.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.tertiaryContainer),
    ) {
        Column(
            modifier = Modifier.padding(18.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Text("ADA BAHAN YANG TIDAK DIGUNAKAN?", fontWeight = FontWeight.Bold)
            Text(
                "Catat retur sebelum menyelesaikan Persiapan. Gudang akan memeriksa jumlah fisik sebelum stok dikembalikan.",
                color = MaterialTheme.colorScheme.onTertiaryContainer,
                style = MaterialTheme.typography.bodyMedium,
            )
            if (returnCount > 0) {
                Text(
                    "$returnCount retur sudah dicatat pada pekerjaan ini.",
                    color = MaterialTheme.colorScheme.onTertiaryContainer,
                    style = MaterialTheme.typography.labelMedium,
                )
            }
            Button(
                onClick = onCreate,
                modifier = Modifier.fillMaxWidth().height(50.dp),
                shape = RoundedCornerShape(15.dp),
            ) {
                Icon(Icons.Outlined.Add, contentDescription = null)
                Spacer(Modifier.width(7.dp))
                Text("Catat Retur Bahan", fontWeight = FontWeight.Bold)
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun OperationalRecordEditScreen(
    state: OperationalUiState,
    moduleLabel: String,
    isCreate: Boolean,
    createActionLabel: String? = null,
    watermarkProfile: PhotoWatermarkProfile,
    onBack: () -> Unit,
    onPrepare: () -> Unit,
    onValueChange: (String, String?) -> Unit,
    onFileSelected: (String, String) -> Unit,
    fileValues: Map<String, String>,
    onSave: () -> Unit,
) {
    LaunchedEffect(isCreate, state.selectedRecord?.id) { onPrepare() }
    val fields = state.activeFormFields
    val isManualReceipt = isCreate && fields.any { it.type == "manual_receipt_rows" }

    Scaffold(
        containerColor = MaterialTheme.colorScheme.background,
        topBar = {
            TopAppBar(
                title = {
                    Text(
                        if (isCreate && createActionLabel != null) createActionLabel
                        else if (isCreate) "Tambah $moduleLabel"
                        else "Ubah $moduleLabel",
                        fontWeight = FontWeight.Bold,
                    )
                },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Outlined.ArrowBack, contentDescription = "Kembali")
                    }
                },
                colors = sppgTopAppBarColors(),
            )
        },
    ) { padding ->
        if (fields.isEmpty()) {
            OperationalLoading(padding)
            return@Scaffold
        }

        LazyColumn(
            modifier = Modifier.fillMaxSize(),
            contentPadding = PaddingValues(
                start = 20.dp,
                top = padding.calculateTopPadding() + 12.dp,
                end = 20.dp,
                bottom = 32.dp,
            ),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            item {
                Card(
                    shape = RoundedCornerShape(20.dp),
                    colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer),
                ) {
                    Column(Modifier.padding(18.dp)) {
                        Text(
                            if (isManualReceipt) "Buat penerimaan manual"
                            else if (isCreate && createActionLabel == "Mulai Pemorsian") "Pilih rencana distribusi aktif"
                            else if (isCreate && createActionLabel != null) "Pilih rencana produksi aktif"
                            else "Isi hanya data yang perlu diubah",
                            fontWeight = FontWeight.Bold,
                        )
                        Spacer(Modifier.height(5.dp))
                        Text(
                            if (isManualReceipt) {
                                "Gunakan untuk kiriman supplier yang tidak berasal dari dokumen pengadaan."
                            } else if (isCreate && createActionLabel != null) {
                                if (createActionLabel == "Mulai Pemorsian") {
                                    "Pemorsian langsung dimulai setelah rencana dipilih. Barang dapat diambil sesudahnya."
                                } else {
                                    "Produksi langsung dimulai setelah rencana dipilih. Bahan dapat diambil sesudahnya."
                                }
                            } else {
                                "Kolom status dikunci agar alur kerja tetap aman."
                            },
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }
            }
            items(fields.filter { it.editable }, key = { it.key }) { field ->
                OperationalFormInput(
                    field = field,
                    value = state.editValues[field.key],
                    onValueChange = { onValueChange(field.key, it) },
                    hasSelectedFile = fileValues.containsKey(field.key),
                    watermarkProfile = watermarkProfile,
                    onSelectFile = { onFileSelected(field.key, it) },
                )
            }
            if (state.errorMessage != null) {
                item {
                    Text(
                        state.errorMessage,
                        color = MaterialTheme.colorScheme.error,
                        style = MaterialTheme.typography.bodyMedium,
                    )
                }
            }
            item {
                Button(
                    onClick = onSave,
                    enabled = !state.isSaving,
                    modifier = Modifier.fillMaxWidth().height(54.dp),
                    shape = RoundedCornerShape(16.dp),
                ) {
                    if (state.isSaving) {
                        CircularProgressIndicator(
                            modifier = Modifier.size(22.dp),
                            strokeWidth = 2.dp,
                            color = MaterialTheme.colorScheme.onPrimary,
                        )
                    } else {
                        Text(
                            if (isManualReceipt) "Buat draft penerimaan"
                            else if (isCreate && createActionLabel != null) createActionLabel
                            else if (isCreate) "Simpan data baru"
                            else "Simpan perubahan",
                            fontWeight = FontWeight.Bold,
                        )
                    }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun OperationalFormInput(
    field: id.sppg.mobile.data.remote.OperationalFormField,
    value: String?,
    onValueChange: (String?) -> Unit,
    hasSelectedFile: Boolean,
    watermarkProfile: PhotoWatermarkProfile,
    onSelectFile: (String) -> Unit,
) {
    val label = field.label + if (field.required) " *" else ""
    val context = LocalContext.current
    var photoError by remember(field.key) { mutableStateOf<String?>(null) }
    val photoLauncher = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri ->
        if (uri != null) {
            runCatching { watermarkedPhotoDataUri(context, uri, watermarkProfile).second }
                .onSuccess {
                    photoError = null
                    onSelectFile(it)
                }
                .onFailure { error ->
                    photoError = error.message
                        ?: "Foto tidak dapat dibaca. Coba simpan foto ke perangkat lalu pilih kembali."
                }
        }
    }
    var pendingCameraTarget by remember(field.key) { mutableStateOf<OperationalCameraCaptureTarget?>(null) }
    val cameraLauncher = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { success ->
        val target = pendingCameraTarget
        pendingCameraTarget = null
        if (success && target != null) {
            runCatching {
                require(target.file.exists() && target.file.length() > 0L) {
                    "Kamera tidak menghasilkan file foto."
                }
                watermarkedPhotoDataUri(target.file, watermarkProfile).second
            }.onSuccess {
                photoError = null
                onSelectFile(it)
            }.onFailure { error ->
                photoError = error.message ?: "Foto dari kamera tidak dapat diproses."
            }
            target.file.delete()
        } else {
            target?.file?.delete()
        }
    }

    when {
        field.type == "manual_receipt_rows" -> ManualReceiptRowsInput(
            field = field,
            value = value,
            onValueChange = onValueChange,
        )
        field.type == "opening_stock_rows" -> OpeningStockRowsInput(
            field = field,
            value = value,
            onValueChange = onValueChange,
        )
        field.type == "file" -> Card(
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(16.dp),
            colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        ) {
            Column(Modifier.padding(16.dp)) {
                Text(label, fontWeight = FontWeight.SemiBold)
                Spacer(Modifier.height(6.dp))
                Text(
                    when {
                        hasSelectedFile -> "Foto baru sudah dipilih."
                        !field.value.isNullOrBlank() -> "Foto tersimpan tersedia."
                        else -> "Belum ada foto."
                    },
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                Spacer(Modifier.height(10.dp))
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    Button(
                        onClick = {
                            photoError = null
                            runCatching { createOperationalCameraCaptureTarget(context) }
                                .onSuccess { target ->
                                    pendingCameraTarget?.file?.delete()
                                    pendingCameraTarget = target
                                    cameraLauncher.launch(target.uri)
                                }
                                .onFailure { error ->
                                    photoError = error.message ?: "Kamera tidak dapat dibuka."
                                }
                        },
                        modifier = Modifier.weight(1f),
                    ) {
                        Icon(Icons.Outlined.PhotoCamera, contentDescription = null)
                        Spacer(Modifier.width(6.dp))
                        Text("Kamera")
                    }
                    OutlinedButton(
                        onClick = {
                            photoError = null
                            photoLauncher.launch(
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
                        Text("Galeri")
                    }
                }
                photoError?.let {
                    Spacer(Modifier.height(8.dp))
                    Text(
                        it,
                        color = MaterialTheme.colorScheme.error,
                        style = MaterialTheme.typography.bodySmall,
                    )
                }
            }
        }
        field.type == "boolean" -> Card(
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(16.dp),
            colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        ) {
            Row(
                modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 12.dp),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.SpaceBetween,
            ) {
                Column(modifier = Modifier.weight(1f)) {
                    Text(label, fontWeight = FontWeight.SemiBold)
                    Text(
                        if (value == "1") "Ya" else "Tidak",
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
                Switch(
                    checked = value == "1",
                    onCheckedChange = { onValueChange(if (it) "1" else "0") },
                )
            }
        }
        field.type == "date" || field.type == "datetime" -> OperationalDatePickerInput(
            field = field,
            value = value,
            onValueChange = onValueChange,
        )
        field.type == "select" && !field.options.isNullOrEmpty()
            && (field.key == "inventory_lot_id" || field.options.size > 20) ->
            OpeningStockSearchableDropdown(
                label = label,
                value = value,
                options = field.options,
                onValueChange = onValueChange,
            )
        field.type == "select" && !field.options.isNullOrEmpty() -> {
            var expanded by remember(field.key) { mutableStateOf(false) }
            ExposedDropdownMenuBox(
                expanded = expanded,
                onExpandedChange = { expanded = !expanded },
            ) {
                OutlinedTextField(
                    value = field.options[value] ?: "",
                    onValueChange = {},
                    readOnly = true,
                    modifier = Modifier
                        .fillMaxWidth()
                        .menuAnchor(ExposedDropdownMenuAnchorType.PrimaryNotEditable),
                    label = { Text(label) },
                    placeholder = { Text("Ketuk untuk memilih") },
                    trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded) },
                    shape = RoundedCornerShape(16.dp),
                )
                ExposedDropdownMenu(
                    expanded = expanded,
                    onDismissRequest = { expanded = false },
                ) {
                    if (!field.required) {
                        DropdownMenuItem(
                            text = { Text("Tidak dipilih") },
                            onClick = {
                                onValueChange(null)
                                expanded = false
                            },
                        )
                    }
                    field.options.forEach { (optionValue, optionLabel) ->
                        DropdownMenuItem(
                            text = { Text(optionLabel) },
                            onClick = {
                                onValueChange(optionValue)
                                expanded = false
                            },
                        )
                    }
                }
            }
        }
        field.type == "select" -> OutlinedTextField(
            value = "",
            onValueChange = {},
            readOnly = true,
            enabled = false,
            modifier = Modifier.fillMaxWidth(),
            label = { Text(label) },
            placeholder = { Text("Belum ada pekerjaan aktif") },
            shape = RoundedCornerShape(16.dp),
        )
        else -> OutlinedTextField(
            value = value.orEmpty(),
            onValueChange = onValueChange,
            modifier = Modifier.fillMaxWidth(),
            label = { Text(label) },
            supportingText = {
                Text(
                    when (field.type) {
                        "number" -> "Masukkan angka tanpa pemisah ribuan."
                        "date" -> "Contoh: 2026-07-29"
                        "datetime" -> "Contoh: 2026-07-29 08:00"
                        else -> "Isi dengan informasi yang mudah dipahami."
                    },
                )
            },
            keyboardOptions = KeyboardOptions(
                keyboardType = if (field.type == "number") KeyboardType.Decimal else KeyboardType.Text,
            ),
            minLines = if (field.type == "textarea") 3 else 1,
            shape = RoundedCornerShape(16.dp),
        )
    }
}

private data class ManualReceiptMobileRow(
    val ingredient_id: String? = null,
    val non_food_item_id: String? = null,
    val received_quantity: String? = null,
    val accepted_quantity: String? = null,
    val rejected_quantity: String = "0",
    val supplier_batch_number: String? = null,
    val expired_date: String? = null,
    val received_temperature_celsius: String? = null,
    val quality_notes: String? = null,
)

@Composable
private fun ManualReceiptRowsInput(
    field: id.sppg.mobile.data.remote.OperationalFormField,
    value: String?,
    onValueChange: (String?) -> Unit,
) {
    val gson = remember { Gson() }
    var rows by remember(field.key) { mutableStateOf(listOf(ManualReceiptMobileRow())) }
    val catalog = field.options.orEmpty()
    val isNonFood = catalog.keys.any { it.startsWith("non_food_item:") }
    val items = if (isNonFood) {
        catalog.filterKeys { it.startsWith("non_food_item:") }
            .mapKeys { it.key.removePrefix("non_food_item:") }
    } else {
        catalog.filterKeys { it.startsWith("ingredient:") }
            .mapKeys { it.key.removePrefix("ingredient:") }
    }

    fun publish(next: List<ManualReceiptMobileRow>) {
        rows = next
        onValueChange(gson.toJson(next))
    }

    LaunchedEffect(field.key, value) {
        if (value.isNullOrBlank()) onValueChange(gson.toJson(rows))
    }

    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Card(
            shape = RoundedCornerShape(18.dp),
            colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer),
        ) {
            Column(Modifier.padding(16.dp)) {
                Text("Penerimaan manual", fontWeight = FontWeight.Bold)
                Spacer(Modifier.height(5.dp))
                Text(
                    "Isi pemeriksaan setiap barang. Setelah draft dibuat, buka bagian Dokumentasi per Barang untuk mengunggah satu atau beberapa foto.",
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
        rows.forEachIndexed { index, row ->
            Card(
                modifier = Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(18.dp),
                colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
            ) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.SpaceBetween,
                    ) {
                        Text("Barang ${index + 1}", fontWeight = FontWeight.Bold)
                        if (rows.size > 1) {
                            TextButton(onClick = { publish(rows.filterIndexed { rowIndex, _ -> rowIndex != index }) }) {
                                Text("Hapus", color = MaterialTheme.colorScheme.error)
                            }
                        }
                    }
                    OpeningStockSearchableDropdown(
                        label = "Cari barang *",
                        value = if (isNonFood) row.non_food_item_id else row.ingredient_id,
                        options = items,
                    ) { selected ->
                        publish(rows.toMutableList().also {
                            it[index] = if (isNonFood) row.copy(non_food_item_id = selected)
                            else row.copy(ingredient_id = selected)
                        })
                    }
                    OutlinedTextField(
                        value = row.received_quantity.orEmpty(),
                        onValueChange = { text -> publish(rows.toMutableList().also { it[index] = row.copy(received_quantity = text) }) },
                        modifier = Modifier.fillMaxWidth(),
                        label = { Text("Jumlah diterima *") },
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                        shape = RoundedCornerShape(16.dp),
                    )
                    OutlinedTextField(
                        value = row.accepted_quantity.orEmpty(),
                        onValueChange = { text -> publish(rows.toMutableList().also { it[index] = row.copy(accepted_quantity = text) }) },
                        modifier = Modifier.fillMaxWidth(),
                        label = { Text("Jumlah baik *") },
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                        shape = RoundedCornerShape(16.dp),
                    )
                    OutlinedTextField(
                        value = row.rejected_quantity,
                        onValueChange = { text -> publish(rows.toMutableList().also { it[index] = row.copy(rejected_quantity = text) }) },
                        modifier = Modifier.fillMaxWidth(),
                        label = { Text("Jumlah ditolak *") },
                        supportingText = { Text("Jumlah baik + ditolak harus sama dengan jumlah diterima.") },
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                        shape = RoundedCornerShape(16.dp),
                    )
                    OutlinedTextField(
                        value = row.supplier_batch_number.orEmpty(),
                        onValueChange = { text -> publish(rows.toMutableList().also { it[index] = row.copy(supplier_batch_number = text) }) },
                        modifier = Modifier.fillMaxWidth(),
                        label = { Text("Batch supplier") },
                        shape = RoundedCornerShape(16.dp),
                    )
                    OperationalDatePickerInput(
                        field = id.sppg.mobile.data.remote.OperationalFormField(
                            key = "manual-receipt-expiry-$index", label = "Kedaluwarsa", type = "date",
                            value = row.expired_date, required = false, editable = true, options = null,
                        ),
                        value = row.expired_date,
                        onValueChange = { date -> publish(rows.toMutableList().also { it[index] = row.copy(expired_date = date) }) },
                    )
                    if (!isNonFood) {
                        OutlinedTextField(
                            value = row.received_temperature_celsius.orEmpty(),
                            onValueChange = { text -> publish(rows.toMutableList().also { it[index] = row.copy(received_temperature_celsius = text) }) },
                            modifier = Modifier.fillMaxWidth(),
                            label = { Text("Suhu diterima (°C)") },
                            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                            shape = RoundedCornerShape(16.dp),
                        )
                    }
                    OutlinedTextField(
                        value = row.quality_notes.orEmpty(),
                        onValueChange = { text -> publish(rows.toMutableList().also { it[index] = row.copy(quality_notes = text) }) },
                        modifier = Modifier.fillMaxWidth(),
                        label = { Text("Catatan mutu") },
                        minLines = 2,
                        shape = RoundedCornerShape(16.dp),
                    )
                }
            }
        }
        OutlinedButton(
            onClick = { publish(rows + ManualReceiptMobileRow()) },
            modifier = Modifier.fillMaxWidth().height(50.dp),
            shape = RoundedCornerShape(16.dp),
        ) {
            Icon(Icons.Outlined.Add, contentDescription = null)
            Spacer(Modifier.width(6.dp))
            Text("Tambah barang")
        }
    }
}

private data class OpeningStockMobileRow(
    val mode: String = "existing",
    val ingredient_id: String? = null,
    val non_food_item_id: String? = null,
    val new_name: String? = null,
    val new_category: String = "other",
    val measurement_unit_id: String? = null,
    val quantity: String? = null,
    val lot_number: String? = null,
    val expired_date: String? = null,
    val storage_type: String = "dry",
    val location_name: String = "Gudang Utama",
    val condition_notes: String? = null,
)

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun OpeningStockRowsInput(
    field: id.sppg.mobile.data.remote.OperationalFormField,
    value: String?,
    onValueChange: (String?) -> Unit,
) {
    val gson = remember { Gson() }
    var rows by remember(field.key) { mutableStateOf(listOf(OpeningStockMobileRow())) }
    val catalog = field.options.orEmpty()
    val isNonFood = catalog.keys.any { it.startsWith("non_food_item:") }
    val ingredients = if (isNonFood) {
        catalog.filterKeys { it.startsWith("non_food_item:") }
            .mapKeys { it.key.removePrefix("non_food_item:") }
    } else {
        catalog.filterKeys { it.startsWith("ingredient:") }
            .mapKeys { it.key.removePrefix("ingredient:") }
    }
    val units = catalog.filterKeys { it.startsWith("unit:") }
        .mapKeys { it.key.removePrefix("unit:") }
    val categories = catalog.filterKeys { it.startsWith("category:") }
        .mapKeys { it.key.removePrefix("category:") }
    val storages = catalog.filterKeys { it.startsWith("storage:") }
        .mapKeys { it.key.removePrefix("storage:") }

    fun publish(next: List<OpeningStockMobileRow>) {
        rows = next
        onValueChange(gson.toJson(next))
    }

    LaunchedEffect(field.key, value) {
        if (value.isNullOrBlank()) onValueChange(gson.toJson(rows))
    }

    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text("Daftar barang *", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
        Text(
            "Tambahkan seluruh stok yang sudah ada di gudang. Semua barang memakai satu foto dokumentasi.",
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        rows.forEachIndexed { index, row ->
            Card(
                modifier = Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(18.dp),
                colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
            ) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.SpaceBetween,
                    ) {
                        Text("Barang ${index + 1}", fontWeight = FontWeight.Bold)
                        if (rows.size > 1) {
                            TextButton(onClick = { publish(rows.filterIndexed { rowIndex, _ -> rowIndex != index }) }) {
                                Text("Hapus", color = MaterialTheme.colorScheme.error)
                            }
                        }
                    }
                    if (!isNonFood) Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        if (row.mode == "existing") {
                            Button(onClick = {}, modifier = Modifier.weight(1f)) { Text("Barang tersedia") }
                        } else {
                            OutlinedButton(
                                onClick = { publish(rows.toMutableList().also { it[index] = row.copy(mode = "existing") }) },
                                modifier = Modifier.weight(1f),
                            ) { Text("Barang tersedia") }
                        }
                        if (row.mode == "new") {
                            Button(onClick = {}, modifier = Modifier.weight(1f)) { Text("Barang baru") }
                        } else {
                            OutlinedButton(
                                onClick = { publish(rows.toMutableList().also { it[index] = row.copy(mode = "new") }) },
                                modifier = Modifier.weight(1f),
                            ) { Text("Barang baru") }
                        }
                    }
                    if (row.mode == "existing") {
                        val selectedItem = if (isNonFood) row.non_food_item_id else row.ingredient_id
                        OpeningStockSearchableDropdown("Cari barang *", selectedItem, ingredients) { selected ->
                            publish(rows.toMutableList().also {
                                it[index] = if (isNonFood) row.copy(non_food_item_id = selected)
                                else row.copy(ingredient_id = selected)
                            })
                        }
                    } else {
                        OutlinedTextField(
                            value = row.new_name.orEmpty(),
                            onValueChange = { text -> publish(rows.toMutableList().also { it[index] = row.copy(new_name = text) }) },
                            modifier = Modifier.fillMaxWidth(),
                            label = { Text("Nama barang baru *") },
                            shape = RoundedCornerShape(16.dp),
                        )
                        OpeningStockDropdown("Kategori *", row.new_category, categories) { selected ->
                            publish(rows.toMutableList().also { it[index] = row.copy(new_category = selected ?: "other") })
                        }
                        OpeningStockDropdown("Satuan *", row.measurement_unit_id, units) { selected ->
                            publish(rows.toMutableList().also { it[index] = row.copy(measurement_unit_id = selected) })
                        }
                    }
                    OutlinedTextField(
                        value = row.quantity.orEmpty(),
                        onValueChange = { text -> publish(rows.toMutableList().also { it[index] = row.copy(quantity = text) }) },
                        modifier = Modifier.fillMaxWidth(),
                        label = { Text("Jumlah *") },
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                        shape = RoundedCornerShape(16.dp),
                    )
                    OpeningStockDropdown("Penyimpanan *", row.storage_type, storages) { selected ->
                        publish(rows.toMutableList().also { it[index] = row.copy(storage_type = selected ?: "dry") })
                    }
                    OutlinedTextField(
                        value = row.location_name,
                        onValueChange = { text -> publish(rows.toMutableList().also { it[index] = row.copy(location_name = text) }) },
                        modifier = Modifier.fillMaxWidth(),
                        label = { Text("Lokasi") },
                        shape = RoundedCornerShape(16.dp),
                    )
                    OutlinedTextField(
                        value = row.lot_number.orEmpty(),
                        onValueChange = { text -> publish(rows.toMutableList().also { it[index] = row.copy(lot_number = text) }) },
                        modifier = Modifier.fillMaxWidth(),
                        label = { Text("Nomor lot (opsional)") },
                        shape = RoundedCornerShape(16.dp),
                    )
                    OperationalDatePickerInput(
                        field = id.sppg.mobile.data.remote.OperationalFormField(
                            key = "opening-expiry-$index", label = "Kedaluwarsa (opsional)", type = "date",
                            value = row.expired_date, required = false, editable = true, options = null,
                        ),
                        value = row.expired_date,
                        onValueChange = { date -> publish(rows.toMutableList().also { it[index] = row.copy(expired_date = date) }) },
                    )
                    OutlinedTextField(
                        value = row.condition_notes.orEmpty(),
                        onValueChange = { text -> publish(rows.toMutableList().also { it[index] = row.copy(condition_notes = text) }) },
                        modifier = Modifier.fillMaxWidth(),
                        label = { Text("Catatan kondisi") },
                        minLines = 2,
                        shape = RoundedCornerShape(16.dp),
                    )
                }
            }
        }
        OutlinedButton(
            onClick = { publish(rows + OpeningStockMobileRow()) },
            modifier = Modifier.fillMaxWidth().height(50.dp),
            shape = RoundedCornerShape(16.dp),
        ) {
            Icon(Icons.Outlined.Add, contentDescription = null)
            Spacer(Modifier.width(6.dp))
            Text("Tambah barang")
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun OpeningStockDropdown(
    label: String,
    value: String?,
    options: Map<String, String>,
    onValueChange: (String?) -> Unit,
) {
    var expanded by remember(label) { mutableStateOf(false) }
    ExposedDropdownMenuBox(expanded = expanded, onExpandedChange = { expanded = !expanded }) {
        OutlinedTextField(
            value = options[value].orEmpty(),
            onValueChange = {},
            readOnly = true,
            modifier = Modifier.fillMaxWidth().menuAnchor(ExposedDropdownMenuAnchorType.PrimaryNotEditable),
            label = { Text(label) },
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded) },
            shape = RoundedCornerShape(16.dp),
        )
        ExposedDropdownMenu(expanded = expanded, onDismissRequest = { expanded = false }) {
            options.forEach { (key, optionLabel) ->
                DropdownMenuItem(
                    text = { Text(optionLabel) },
                    onClick = { onValueChange(key); expanded = false },
                )
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun OpeningStockSearchableDropdown(
    label: String,
    value: String?,
    options: Map<String, String>,
    onValueChange: (String?) -> Unit,
) {
    var expanded by remember(label) { mutableStateOf(false) }
    var query by remember(label) { mutableStateOf(options[value].orEmpty()) }
    LaunchedEffect(value) {
        if (value != null && query != options[value]) query = options[value].orEmpty()
    }
    val filtered = remember(query, options) {
        val keyword = query.trim()
        options.entries
            .asSequence()
            .filter { keyword.isBlank() || it.value.contains(keyword, ignoreCase = true) }
            .take(50)
            .toList()
    }

    ExposedDropdownMenuBox(
        expanded = expanded,
        onExpandedChange = { expanded = true },
    ) {
        OutlinedTextField(
            value = query,
            onValueChange = { text ->
                query = text
                if (value != null && text != options[value]) onValueChange(null)
                expanded = true
            },
            modifier = Modifier.fillMaxWidth().menuAnchor(ExposedDropdownMenuAnchorType.PrimaryEditable),
            label = { Text(label) },
            placeholder = { Text("Ketik nama barang atau satuan") },
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded) },
            singleLine = true,
            shape = RoundedCornerShape(16.dp),
        )
        ExposedDropdownMenu(expanded = expanded, onDismissRequest = { expanded = false }) {
            if (filtered.isEmpty()) {
                DropdownMenuItem(
                    text = {
                        Text(
                            "Barang tidak ditemukan",
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    },
                    onClick = { expanded = false },
                )
            } else {
                filtered.forEach { (key, optionLabel) ->
                    DropdownMenuItem(
                        text = { Text(optionLabel) },
                        onClick = {
                            query = optionLabel
                            onValueChange(key)
                            expanded = false
                        },
                    )
                }
                if (options.size > filtered.size) {
                    DropdownMenuItem(
                        text = {
                            Text(
                                "Ketik lebih spesifik untuk mempersempit hasil",
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                                style = MaterialTheme.typography.bodySmall,
                            )
                        },
                        onClick = {},
                    )
                }
            }
        }
    }
}

private val apiDateFormatter: DateTimeFormatter = DateTimeFormatter.ISO_LOCAL_DATE
private val displayDateFormatter: DateTimeFormatter = DateTimeFormatter.ofPattern("dd-MM-yyyy")
private val apiDateTimeFormatter: DateTimeFormatter = DateTimeFormatter.ofPattern("yyyy-MM-dd'T'HH:mm")
private val displayDateTimeFormatter: DateTimeFormatter = DateTimeFormatter.ofPattern("dd-MM-yyyy HH:mm")
private val legacyDateTimeFormatter: DateTimeFormatter = DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm")

private fun parseOperationalDate(value: String?): LocalDate? = value
    ?.takeIf { it.isNotBlank() }
    ?.let { raw ->
        runCatching { LocalDate.parse(raw.take(10), apiDateFormatter) }.getOrNull()
    }

private fun parseOperationalDateTime(value: String?): LocalDateTime? = value
    ?.takeIf { it.isNotBlank() }
    ?.let { raw ->
        runCatching { LocalDateTime.parse(raw) }.getOrNull()
            ?: runCatching { LocalDateTime.parse(raw.take(16), apiDateTimeFormatter) }.getOrNull()
            ?: runCatching { LocalDateTime.parse(raw.take(16), legacyDateTimeFormatter) }.getOrNull()
    }

private fun operationalDateDisplay(value: String?, type: String): String = when (type) {
    "datetime" -> parseOperationalDateTime(value)?.format(displayDateTimeFormatter)
    else -> parseOperationalDate(value)?.format(displayDateFormatter)
}.orEmpty()

@Composable
private fun OperationalDatePickerInput(
    field: id.sppg.mobile.data.remote.OperationalFormField,
    value: String?,
    onValueChange: (String?) -> Unit,
) {
    val context = LocalContext.current
    val isDateTime = field.type == "datetime"
    val label = field.label + if (field.required) " *" else ""

    fun openPicker() {
        val currentDateTime = parseOperationalDateTime(value)
        val currentDate = currentDateTime?.toLocalDate()
            ?: parseOperationalDate(value)
            ?: LocalDate.now()
        DatePickerDialog(
            context,
            { _, year, month, day ->
                val selectedDate = LocalDate.of(year, month + 1, day)
                if (!isDateTime) {
                    onValueChange(selectedDate.format(apiDateFormatter))
                } else {
                    val currentTime = currentDateTime?.toLocalTime() ?: LocalTime.now()
                    TimePickerDialog(
                        context,
                        { _, hour, minute ->
                            onValueChange(
                                selectedDate.atTime(hour, minute).format(apiDateTimeFormatter),
                            )
                        },
                        currentTime.hour,
                        currentTime.minute,
                        true,
                    ).show()
                }
            },
            currentDate.year,
            currentDate.monthValue - 1,
            currentDate.dayOfMonth,
        ).show()
    }

    Column(modifier = Modifier.fillMaxWidth()) {
        Card(
            onClick = ::openPicker,
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(16.dp),
            colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        ) {
            Row(
                modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 14.dp),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.SpaceBetween,
            ) {
                Column(modifier = Modifier.weight(1f)) {
                    Text(label, style = MaterialTheme.typography.labelMedium)
                    Spacer(Modifier.height(4.dp))
                    Text(
                        operationalDateDisplay(value, field.type).ifBlank {
                            if (isDateTime) "Pilih tanggal dan waktu" else "Pilih tanggal"
                        },
                        color = if (value.isNullOrBlank()) {
                            MaterialTheme.colorScheme.onSurfaceVariant
                        } else {
                            MaterialTheme.colorScheme.onSurface
                        },
                        fontWeight = if (value.isNullOrBlank()) FontWeight.Normal else FontWeight.SemiBold,
                    )
                }
                Icon(Icons.Outlined.CalendarMonth, contentDescription = "Pilih tanggal")
            }
        }
        if (!field.required && !value.isNullOrBlank()) {
            TextButton(onClick = { onValueChange(null) }) {
                Text("Hapus tanggal")
            }
        }
    }
}

@Composable
private fun PortioningLeftoverChoiceCard(
    fields: List<OperationalField>,
    actions: List<OperationalAction>,
    isSaving: Boolean,
    onAction: (OperationalAction) -> Unit,
) {
    val current = fields.firstOrNull { it.key == "leftover_mode" }?.value.orEmpty()
    val noneSelected = current.contains("Tidak ada", ignoreCase = true)
    val presentSelected = current.contains("Ada sisa", ignoreCase = true)
    val noneAction = actions.firstOrNull { it.key == "set_leftover_none" }
    val presentAction = actions.firstOrNull { it.key == "set_leftover_present" }

    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(22.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
    ) {
        Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text("SISA MAKANAN SETELAH PEMORSIAN", fontWeight = FontWeight.ExtraBold)
            Text(
                "Pilih kondisi sisa makanan sebelum menyelesaikan Pemorsian.",
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                OutlinedButton(
                    onClick = { noneAction?.let(onAction) },
                    enabled = !isSaving && noneAction != null,
                    modifier = Modifier.weight(1f),
                ) {
                    Text(if (noneSelected) "✓ Tidak ada sisa" else "Tidak ada sisa")
                }
                Button(
                    onClick = { presentAction?.let(onAction) },
                    enabled = !isSaving && presentAction != null,
                    modifier = Modifier.weight(1f),
                ) {
                    Text(if (presentSelected) "✓ Ada sisa" else "Ada sisa makanan")
                }
            }
            if (presentSelected) {
                Text(
                    "Tambahkan nama makanan, jumlah, satuan, dan foto pada bagian Sisa makanan di bawah.",
                    color = MaterialTheme.colorScheme.primary,
                    fontWeight = FontWeight.SemiBold,
                )
            } else if (noneSelected) {
                Text("Tercatat tidak ada sisa makanan.", color = MaterialTheme.colorScheme.primary)
            }
        }
    }
}

@Composable
private fun PortioningProgressCard(fields: List<OperationalField>) {
    fun value(key: String): Int = fields.firstOrNull { it.key == key }
        ?.value
        ?.replace(".", "")
        ?.replace(",", ".")
        ?.toDoubleOrNull()
        ?.toInt()
        ?: 0

    val targetSmall = value("target_small_portions")
    val targetLarge = value("target_large_portions")
    val actualSmall = value("actual_small_portions")
    val actualLarge = value("actual_large_portions")
    val targetMet = actualSmall >= targetSmall && actualLarge >= targetLarge

    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(22.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer),
    ) {
        Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text("OMPRENG YANG SUDAH DIPORSIKAN", fontWeight = FontWeight.ExtraBold)
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                PortioningProgressItem("Kecil", actualSmall, targetSmall, Modifier.weight(1f))
                PortioningProgressItem("Besar", actualLarge, targetLarge, Modifier.weight(1f))
            }
            Text(
                if (targetMet) "Target Pemorsian sudah terpenuhi."
                else "Target belum terpenuhi. Lanjutkan pencatatan ompreng per rute.",
                color = if (targetMet) MaterialTheme.colorScheme.primary
                else MaterialTheme.colorScheme.onSurfaceVariant,
                fontWeight = FontWeight.SemiBold,
            )
        }
    }
}

@Composable
private fun PortioningProgressItem(
    label: String,
    actual: Int,
    target: Int,
    modifier: Modifier = Modifier,
) {
    Card(
        modifier = modifier,
        shape = RoundedCornerShape(16.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
    ) {
        Column(Modifier.padding(14.dp)) {
            Text("Ompreng $label", color = MaterialTheme.colorScheme.onSurfaceVariant)
            Spacer(Modifier.height(5.dp))
            Text("$actual / $target", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
            Text(
                if (actual >= target) "Terpenuhi" else "Kurang ${(target - actual).coerceAtLeast(0)}",
                color = if (actual >= target) MaterialTheme.colorScheme.primary
                else MaterialTheme.colorScheme.onSurfaceVariant,
                style = MaterialTheme.typography.bodySmall,
            )
        }
    }
}

@Composable
private fun OperationalFieldCard(title: String, fields: List<OperationalField>) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(22.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
    ) {
        Column(modifier = Modifier.padding(18.dp)) {
            Text(title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            Spacer(Modifier.height(10.dp))
            fields.forEachIndexed { index, field ->
                OperationalFieldRow(field)
                if (index < fields.lastIndex) {
                    Spacer(Modifier.height(9.dp))
                    HorizontalDivider(color = MaterialTheme.colorScheme.surfaceVariant)
                    Spacer(Modifier.height(9.dp))
                }
            }
        }
    }
}

@Composable
private fun OperationalSectionCard(
    section: OperationalSection,
    onCreate: () -> Unit,
    onEdit: (OperationalSectionItem) -> Unit,
    onDelete: (OperationalSectionItem) -> Unit,
    onAction: (OperationalSectionItem, OperationalRelationAction) -> Unit,
) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(22.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
    ) {
        Column(modifier = Modifier.padding(18.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.SpaceBetween,
            ) {
                Text(
                    section.title,
                    modifier = Modifier.weight(1f),
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.Bold,
                )
                if (section.canCreate) {
                    TextButton(onClick = onCreate) {
                        Icon(Icons.Outlined.Add, contentDescription = null)
                        Spacer(Modifier.width(4.dp))
                        Text("Tambah")
                    }
                }
            }
            Spacer(Modifier.height(10.dp))
            if (section.items.isEmpty()) {
                Text("Belum ada data.", color = MaterialTheme.colorScheme.onSurfaceVariant)
            } else {
                section.items.forEachIndexed { itemIndex, item ->
                    Text(item.title, fontWeight = FontWeight.SemiBold)
                    Spacer(Modifier.height(8.dp))
                    item.fields.forEach { field ->
                        OperationalFieldRow(field)
                        Spacer(Modifier.height(6.dp))
                    }
                    if (item.canUpdate || item.canDelete) {
                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.End,
                        ) {
                            if (item.canUpdate) {
                                TextButton(onClick = { onEdit(item) }) {
                                    Icon(Icons.Outlined.Edit, contentDescription = null)
                                    Spacer(Modifier.width(4.dp))
                                    Text("Ubah")
                                }
                            }
                            if (item.canDelete) {
                                TextButton(onClick = { onDelete(item) }) {
                                    Icon(
                                        Icons.Outlined.Delete,
                                        contentDescription = null,
                                        tint = MaterialTheme.colorScheme.error,
                                    )
                                    Spacer(Modifier.width(4.dp))
                                    Text("Hapus", color = MaterialTheme.colorScheme.error)
                                }
                            }
                        }
                    }
                    if (!item.actions.isNullOrEmpty()) {
                        Column(
                            modifier = Modifier.fillMaxWidth(),
                            verticalArrangement = Arrangement.spacedBy(8.dp),
                        ) {
                            item.actions.orEmpty().forEach { action ->
                                OutlinedButton(
                                    onClick = { onAction(item, action) },
                                    modifier = Modifier.fillMaxWidth(),
                                ) { Text(action.label, fontWeight = FontWeight.SemiBold) }
                            }
                        }
                    }
                    if (itemIndex < section.items.lastIndex) {
                        Spacer(Modifier.height(6.dp))
                        HorizontalDivider()
                        Spacer(Modifier.height(12.dp))
                    }
                }
            }
        }
    }
}

@Composable
private fun OperationalFieldRow(field: OperationalField) {
    Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
        Text(
            field.label,
            modifier = Modifier.weight(1f),
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            style = MaterialTheme.typography.bodyMedium,
        )
        Spacer(Modifier.width(16.dp))
        if (!field.fileUrl.isNullOrBlank()) {
            InAppImageButton(
                url = field.fileUrl,
                title = field.label,
                label = "Lihat foto",
                modifier = Modifier.weight(1f),
            )
        } else {
            Text(
                field.value,
                modifier = Modifier.weight(1f),
                fontWeight = FontWeight.Medium,
                style = MaterialTheme.typography.bodyMedium,
            )
        }
    }
}

@Composable
private fun OperationalInfoLine(icon: androidx.compose.ui.graphics.vector.ImageVector, text: String) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Icon(
            icon,
            contentDescription = null,
            modifier = Modifier.size(18.dp),
            tint = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Spacer(Modifier.width(8.dp))
        Text(text, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

internal fun isTechnicalRecordNumber(value: String): Boolean =
    value.matches(Regex("^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$"))

@Composable
private fun OperationalLoading(padding: PaddingValues) {
    Box(
        modifier = Modifier
            .fillMaxSize()
            .padding(padding),
        contentAlignment = Alignment.Center,
    ) { CircularProgressIndicator() }
}

@Composable
private fun OperationalError(message: String, padding: PaddingValues, onRetry: () -> Unit) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(padding)
            .padding(24.dp),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text("Data belum dapat dimuat", fontWeight = FontWeight.Bold)
        Spacer(Modifier.height(8.dp))
        Text(message, color = MaterialTheme.colorScheme.onSurfaceVariant)
        Spacer(Modifier.height(12.dp))
        TextButton(onClick = onRetry) { Text("Coba lagi") }
    }
}

@Composable
private fun OperationalEmpty(label: String, padding: PaddingValues) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(padding)
            .padding(24.dp),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Icon(
            Icons.AutoMirrored.Outlined.Assignment,
            contentDescription = null,
            modifier = Modifier.size(48.dp),
            tint = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Spacer(Modifier.height(12.dp))
        Text("Belum ada pekerjaan $label", fontWeight = FontWeight.Bold)
        Spacer(Modifier.height(6.dp))
        Text(
            "Pekerjaan yang dibuat pada sistem SPPG V3 akan tampil di sini.",
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

private fun formatOperationalDate(value: String): String = runCatching {
    LocalDate.parse(value).format(
        DateTimeFormatter.ofPattern("EEEE, d MMMM yyyy", Locale.forLanguageTag("id-ID")),
    )
}.getOrDefault(value)

private data class OperationalCameraCaptureTarget(
    val file: File,
    val uri: Uri,
)

private fun createOperationalCameraCaptureTarget(context: Context): OperationalCameraCaptureTarget {
    val directory = File(context.cacheDir, "images").apply {
        check(exists() || mkdirs()) { "Folder sementara kamera tidak dapat dibuat." }
    }
    val file = File.createTempFile("operational_", ".jpg", directory)
    val uri = FileProvider.getUriForFile(
        context,
        "${context.packageName}.fileprovider",
        file,
    )
    return OperationalCameraCaptureTarget(file = file, uri = uri)
}
