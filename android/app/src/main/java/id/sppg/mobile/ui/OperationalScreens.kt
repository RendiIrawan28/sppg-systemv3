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
import androidx.compose.material.icons.automirrored.outlined.OpenInNew
import androidx.compose.material.icons.outlined.CalendarMonth
import androidx.compose.material.icons.outlined.Add
import androidx.compose.material.icons.outlined.Person
import androidx.compose.material.icons.outlined.Refresh
import androidx.compose.material.icons.outlined.PhotoCamera
import androidx.compose.material.icons.outlined.PictureAsPdf
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
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.ui.unit.dp
import androidx.core.content.FileProvider
import id.sppg.mobile.data.remote.OperationalField
import id.sppg.mobile.data.remote.OperationalAction
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

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun OperationalRecordListScreen(
    state: OperationalUiState,
    module: String,
    moduleLabel: String,
    onBack: () -> Unit,
    onLoad: (String) -> Unit,
    onRefresh: () -> Unit,
    onLoadMore: () -> Unit,
    onRecordClick: (Long) -> Unit,
    onCreate: () -> Unit,
) {
    LaunchedEffect(module) { onLoad(module) }

    Scaffold(
        containerColor = MaterialTheme.colorScheme.background,
        topBar = {
            TopAppBar(
                title = { Text(moduleLabel, fontWeight = FontWeight.Bold) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Outlined.ArrowBack, contentDescription = "Kembali")
                    }
                },
                actions = {
                    if (state.modules.firstOrNull { it.slug == module }?.canCreate == true) {
                        IconButton(onClick = onCreate) {
                            Icon(Icons.Outlined.Add, contentDescription = "Tambah data")
                        }
                    }
                    IconButton(onClick = onRefresh, enabled = !state.isLoading) {
                        Icon(Icons.Outlined.Refresh, contentDescription = "Muat ulang")
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = MaterialTheme.colorScheme.background,
                ),
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
            state.records.isEmpty() -> OperationalEmpty(moduleLabel, innerPadding)
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
                        label = moduleLabel,
                        count = state.records.size,
                    )
                }
                item {
                    Text(
                        "PEKERJAAN TERBARU",
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        style = MaterialTheme.typography.labelMedium,
                        fontWeight = FontWeight.Bold,
                    )
                }
                items(state.records, key = { it.id }) { record ->
                    OperationalRecordCard(
                        module = module,
                        record = record,
                        onClick = { onRecordClick(record.id) },
                    )
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
            SppgStatusPill(record.stateLabel ?: record.statusLabel ?: "-")
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
        shape = RoundedCornerShape(24.dp),
        colors = CardDefaults.cardColors(containerColor = visual.container),
    ) {
        Row(
            modifier = Modifier.padding(20.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            ModuleIcon(module, Modifier.size(56.dp))
            Spacer(Modifier.width(16.dp))
            Column(modifier = Modifier.weight(1f)) {
                Text(
                    label,
                    color = visual.color,
                    style = MaterialTheme.typography.headlineSmall,
                    fontWeight = FontWeight.ExtraBold,
                )
                Spacer(Modifier.height(4.dp))
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
    onOpenDocument: () -> Unit,
    onRelationCreate: (OperationalSection) -> Unit,
    onRelationEdit: (OperationalSection, OperationalSectionItem) -> Unit,
    onRelationDelete: (OperationalSection, OperationalSectionItem) -> Unit,
    onRelationAction: (OperationalSection, OperationalSectionItem, String) -> Unit,
) {
    LaunchedEffect(module, recordId) { onLoad(module, recordId) }
    var confirmDelete by remember { mutableStateOf(false) }
    var selectedAction by remember { mutableStateOf<OperationalAction?>(null) }
    var actionNotes by remember { mutableStateOf("") }
    var actionValues by remember { mutableStateOf<Map<String, String?>>(emptyMap()) }
    var actionFiles by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
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
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = MaterialTheme.colorScheme.background,
                ),
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
                onRelationCreate = onRelationCreate,
                onRelationEdit = onRelationEdit,
                onRelationDelete = { section, item -> relationToDelete = section to item },
                onRelationAction = onRelationAction,
            )
        }
    }
}

@Composable
private fun OperationalDetailContent(
    record: OperationalRecord,
    padding: PaddingValues,
    onEdit: () -> Unit,
    onDelete: () -> Unit,
    isSaving: Boolean,
    errorMessage: String?,
    successMessage: String?,
    onAction: (OperationalAction) -> Unit,
    onOpenDocument: () -> Unit,
    onRelationCreate: (OperationalSection) -> Unit,
    onRelationEdit: (OperationalSection, OperationalSectionItem) -> Unit,
    onRelationDelete: (OperationalSection, OperationalSectionItem) -> Unit,
    onRelationAction: (OperationalSection, OperationalSectionItem, String) -> Unit,
) {
    val capabilities = record.capabilities
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
        if (!record.fields.isNullOrEmpty()) {
            item { OperationalFieldCard("Informasi pekerjaan", record.fields) }
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
        if (!capabilities?.actions.isNullOrEmpty()) {
            item {
                Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text("LANGKAH BERIKUTNYA", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.Bold)
                    capabilities.actions.orEmpty().forEach { action ->
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
                OutlinedButton(
                    onClick = onOpenDocument,
                    enabled = !isSaving,
                    modifier = Modifier.fillMaxWidth().height(52.dp),
                    shape = RoundedCornerShape(16.dp),
                ) {
                    Icon(Icons.Outlined.PictureAsPdf, contentDescription = null)
                    Spacer(Modifier.width(8.dp))
                    Text("Lihat dokumen PDF", fontWeight = FontWeight.Bold)
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

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun OperationalRecordEditScreen(
    state: OperationalUiState,
    moduleLabel: String,
    isCreate: Boolean,
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

    Scaffold(
        containerColor = MaterialTheme.colorScheme.background,
        topBar = {
            TopAppBar(
                title = { Text(if (isCreate) "Tambah $moduleLabel" else "Ubah $moduleLabel", fontWeight = FontWeight.Bold) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Outlined.ArrowBack, contentDescription = "Kembali")
                    }
                },
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
                        Text("Isi hanya data yang perlu diubah", fontWeight = FontWeight.Bold)
                        Spacer(Modifier.height(5.dp))
                        Text(
                            "Kolom status dikunci agar alur kerja tetap aman.",
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
                        Text(if (isCreate) "Simpan data baru" else "Simpan perubahan", fontWeight = FontWeight.Bold)
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

private data class OpeningStockMobileRow(
    val mode: String = "existing",
    val ingredient_id: String? = null,
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
    val ingredients = catalog.filterKeys { it.startsWith("ingredient:") }
        .mapKeys { it.key.removePrefix("ingredient:") }
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
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
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
                        OpeningStockSearchableDropdown("Cari barang *", row.ingredient_id, ingredients) { selected ->
                            publish(rows.toMutableList().also { it[index] = row.copy(ingredient_id = selected) })
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
    onAction: (OperationalSectionItem, String) -> Unit,
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
                                    onClick = { onAction(item, action.key) },
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
    val uriHandler = LocalUriHandler.current
    Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
        Text(
            field.label,
            modifier = Modifier.weight(1f),
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            style = MaterialTheme.typography.bodyMedium,
        )
        Spacer(Modifier.width(16.dp))
        if (!field.fileUrl.isNullOrBlank()) {
            if (isImageUrl(field.fileUrl)) {
                InAppImageButton(
                    url = field.fileUrl,
                    title = field.label,
                    label = "Lihat foto",
                    modifier = Modifier.weight(1f),
                )
            } else {
                TextButton(
                    onClick = { uriHandler.openUri(resolveAppMediaUrl(field.fileUrl)) },
                    modifier = Modifier.weight(1f),
                ) {
                    Icon(Icons.AutoMirrored.Outlined.OpenInNew, contentDescription = null, modifier = Modifier.size(18.dp))
                    Spacer(Modifier.width(6.dp))
                    Text("Buka dokumen", fontWeight = FontWeight.SemiBold)
                }
            }
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
