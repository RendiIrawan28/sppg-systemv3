package id.sppg.mobile.ui

import android.app.DatePickerDialog

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
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.outlined.CalendarMonth
import androidx.compose.material.icons.outlined.Add
import androidx.compose.material.icons.outlined.Delete
import androidx.compose.material.icons.outlined.Download
import androidx.compose.material.icons.outlined.LocationOn
import androidx.compose.material.icons.outlined.People
import androidx.compose.material.icons.outlined.PictureAsPdf
import androidx.compose.material.icons.outlined.Share
import androidx.compose.material.icons.outlined.Refresh
import androidx.compose.material.icons.outlined.Restaurant
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuAnchorType
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
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
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import id.sppg.mobile.data.remote.FieldPlan
import id.sppg.mobile.data.remote.FieldPlanOption
import id.sppg.mobile.data.remote.FieldPlanDestination
import id.sppg.mobile.data.remote.UpdateFieldPlanDestinationRequest
import id.sppg.mobile.data.remote.UpdateFieldPlanRequest
import id.sppg.mobile.data.remote.UpdateRecipientGroupRequest
import java.time.LocalDate
import java.time.format.DateTimeFormatter
import java.util.Locale

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun FieldPlanListScreen(
    state: FieldPlanUiState,
    onBack: () -> Unit,
    onRefresh: () -> Unit,
    onLoad: () -> Unit,
    onLoadMore: () -> Unit,
    onShowActive: () -> Unit,
    onHistoryDateChange: (String) -> Unit,
    onPlanClick: (Long) -> Unit,
    onCreate: () -> Unit,
) {
    LaunchedEffect(Unit) { onLoad() }
    val context = LocalContext.current
    val showHistory = state.showHistory
    var historyDate by remember(state.dateFilter) {
        mutableStateOf(runCatching { LocalDate.parse(state.dateFilter) }.getOrDefault(LocalDate.now()))
    }
    val visiblePlans = state.plans

    fun chooseHistoryDate() {
        DatePickerDialog(
            context,
            { _, year, month, day ->
                historyDate = LocalDate.of(year, month + 1, day)
                onHistoryDateChange(historyDate.toString())
            },
            historyDate.year,
            historyDate.monthValue - 1,
            historyDate.dayOfMonth,
        ).show()
    }

    Scaffold(
        containerColor = MaterialTheme.colorScheme.background,
        topBar = {
            TopAppBar(
                title = { Text("Rencana Distribusi", fontWeight = FontWeight.Bold) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Outlined.ArrowBack, contentDescription = "Kembali")
                    }
                },
                actions = {
                    if (!showHistory) {
                        IconButton(onClick = onCreate) {
                            Icon(Icons.Outlined.Add, contentDescription = "Tambah rencana")
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
            state.isLoading && state.plans.isEmpty() -> LoadingContent(innerPadding)
            state.errorMessage != null && state.plans.isEmpty() -> ErrorContent(
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
                    Card(
                        modifier = Modifier.fillMaxWidth(),
                        shape = RoundedCornerShape(24.dp),
                        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer),
                    ) {
                        Row(
                            modifier = Modifier.padding(20.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            ModuleIcon("field-plans", Modifier.size(56.dp))
                            Spacer(Modifier.width(16.dp))
                            Column {
                                Text(
                                    "Rencana Distribusi",
                                    style = MaterialTheme.typography.headlineSmall,
                                    color = MaterialTheme.colorScheme.primary,
                                    fontWeight = FontWeight.ExtraBold,
                                )
                                Text(
                                    "${state.plans.size} rencana siap dipantau",
                                    color = MaterialTheme.colorScheme.onPrimaryContainer,
                                )
                            }
                        }
                    }
                }
                item {
                    WorkHistoryTabs(showHistory, activeLabel = "Aktif & Mendatang") { history ->
                        if (history) onHistoryDateChange(historyDate.toString()) else onShowActive()
                    }
                }
                if (showHistory) {
                    item { HistoryDateSelector(formatDate(state.dateFilter), ::chooseHistoryDate) }
                }
                item {
                    Text(
                        if (showHistory) "RIWAYAT RENCANA" else "AKTIF & MENDATANG",
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        style = MaterialTheme.typography.labelMedium,
                        fontWeight = FontWeight.Bold,
                    )
                }
                if (visiblePlans.isEmpty()) {
                    item {
                        if (showHistory) HistoryEmptyState()
                        else Card(shape = RoundedCornerShape(18.dp)) {
                            Text(
                                "Belum ada rencana distribusi aktif atau mendatang",
                                modifier = Modifier.padding(22.dp),
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                    }
                } else {
                    items(visiblePlans, key = { it.id }) { plan ->
                        FieldPlanCard(plan = plan, onClick = { onPlanClick(plan.id) })
                    }
                }
                if (state.currentPage < state.lastPage) {
                    item(key = "field-plan-load-more-${state.currentPage}") {
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
                            Text("Muat rencana berikutnya")
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun FieldPlanCard(plan: FieldPlan, onClick: () -> Unit) {
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
                ModuleIcon("field-plans", Modifier.size(42.dp))
                Column(modifier = Modifier.weight(1f)) {
                    Text(
                        plan.planNumber,
                        style = MaterialTheme.typography.labelLarge,
                        color = MaterialTheme.colorScheme.primary,
                        fontWeight = FontWeight.Bold,
                    )
                    Spacer(Modifier.height(3.dp))
                    Text(
                        plan.menuName ?: "Menu belum ditentukan",
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.Bold,
                    )
                }
            }
            Spacer(Modifier.height(12.dp))
            StatusBadge(plan.status, plan.statusLabel)
            Spacer(Modifier.height(10.dp))
            InfoLine(Icons.Outlined.CalendarMonth, formatDate(plan.distributionDate))
            Spacer(Modifier.height(6.dp))
            InfoLine(
                Icons.Outlined.LocationOn,
                "${plan.destinationCount} tujuan • ${plan.totalPortions} porsi",
            )
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun FieldPlanDetailScreen(
    state: FieldPlanUiState,
    planId: Long,
    onBack: () -> Unit,
    onLoad: (Long) -> Unit,
    onEdit: () -> Unit,
    onCheckReadiness: () -> Unit,
    onActivate: (String?) -> Unit,
    onRefreshBeneficiaries: () -> Unit,
    onDelete: () -> Unit,
    onOpenDocument: (String) -> Unit,
    onShareDocument: (String) -> Unit,
    onClearFeedback: () -> Unit,
) {
    LaunchedEffect(planId) { onLoad(planId) }

    Scaffold(
        containerColor = MaterialTheme.colorScheme.background,
        topBar = {
            TopAppBar(
                title = { Text("Rincian Rencana", fontWeight = FontWeight.Bold) },
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
            state.isLoading -> LoadingContent(innerPadding)
            state.errorMessage != null && state.selectedPlan == null -> ErrorContent(
                message = state.errorMessage,
                padding = innerPadding,
                onRetry = { onLoad(planId) },
            )
            state.selectedPlan != null -> FieldPlanDetailContent(
                plan = state.selectedPlan,
                padding = innerPadding,
                state = state,
                onEdit = onEdit,
                onCheckReadiness = onCheckReadiness,
                onActivate = onActivate,
                onRefreshBeneficiaries = onRefreshBeneficiaries,
                onDelete = onDelete,
                onOpenDocument = onOpenDocument,
                onShareDocument = onShareDocument,
                onClearFeedback = onClearFeedback,
            )
        }
    }
}

@Composable
private fun FieldPlanDetailContent(
    plan: FieldPlan,
    padding: PaddingValues,
    state: FieldPlanUiState,
    onEdit: () -> Unit,
    onCheckReadiness: () -> Unit,
    onActivate: (String?) -> Unit,
    onRefreshBeneficiaries: () -> Unit,
    onDelete: () -> Unit,
    onOpenDocument: (String) -> Unit,
    onShareDocument: (String) -> Unit,
    onClearFeedback: () -> Unit,
) {
    var showActivationDialog by remember { mutableStateOf(false) }
    var showDeleteDialog by remember { mutableStateOf(false) }
    var activationRequested by remember { mutableStateOf(false) }
    var activationNotes by remember { mutableStateOf("") }

    LaunchedEffect(state.readiness, activationRequested) {
        if (activationRequested && state.readiness != null) {
            if (state.readiness.ready) {
                showActivationDialog = true
            }
            activationRequested = false
        }
    }

    if (showActivationDialog) {
        AlertDialog(
            onDismissRequest = { if (!state.isSubmitting) showActivationDialog = false },
            title = { Text("Aktifkan rencana?") },
            text = {
                Column {
                    Text("Aktivasi akan menyiapkan rute Distribusi. Pengolahan dan Pemorsian tetap dimulai manual oleh divisi masing-masing. Nama dan urutan rute masih dapat disesuaikan sebelum dipilih driver.")
                    Spacer(Modifier.height(14.dp))
                    OutlinedTextField(
                        value = activationNotes,
                        onValueChange = { activationNotes = it },
                        modifier = Modifier.fillMaxWidth(),
                        label = { Text("Catatan aktivasi (opsional)") },
                        minLines = 2,
                    )
                }
            },
            confirmButton = {
                Button(
                    onClick = {
                        onActivate(activationNotes)
                        showActivationDialog = false
                    },
                    enabled = !state.isSubmitting,
                ) { Text("Aktifkan") }
            },
            dismissButton = {
                TextButton(
                    onClick = { showActivationDialog = false },
                    enabled = !state.isSubmitting,
                ) { Text("Batal") }
            },
        )
    }
    if (showDeleteDialog) {
        AlertDialog(
            onDismissRequest = { if (!state.isSubmitting) showDeleteDialog = false },
            title = { Text("Hapus draft rencana?") },
            text = { Text("Draft dan seluruh rincian tujuan di dalamnya akan dihapus.") },
            confirmButton = {
                Button(onClick = { showDeleteDialog = false; onDelete() }, enabled = !state.isSubmitting) {
                    Text("Hapus")
                }
            },
            dismissButton = { TextButton(onClick = { showDeleteDialog = false }) { Text("Batal") } },
        )
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
        if (state.successMessage != null) {
            item {
                FeedbackCard(
                    message = state.successMessage,
                    isError = false,
                    onDismiss = onClearFeedback,
                )
            }
        }
        if (state.errorMessage != null) {
            item {
                FeedbackCard(
                    message = state.errorMessage,
                    isError = true,
                    onDismiss = onClearFeedback,
                )
            }
        }
        item {
            Card(
                colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primary),
                shape = RoundedCornerShape(24.dp),
            ) {
                Column(modifier = Modifier.padding(20.dp)) {
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.SpaceBetween,
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(plan.planNumber, fontWeight = FontWeight.Bold, color = Color.White)
                        StatusBadge(plan.status, plan.statusLabel)
                    }
                    Spacer(Modifier.height(14.dp))
                    Text(
                        plan.menuName ?: "Menu belum ditentukan",
                        style = MaterialTheme.typography.headlineSmall,
                        fontWeight = FontWeight.Bold,
                        color = Color.White,
                    )
                    Spacer(Modifier.height(8.dp))
                    Text(formatDate(plan.distributionDate), color = Color.White.copy(alpha = 0.8f))
                    if (plan.isRapel) {
                        Spacer(Modifier.height(6.dp))
                        Text("Distribusi rapel", fontWeight = FontWeight.SemiBold, color = Color.White)
                    }
                }
            }
        }
        if (plan.canUpdate || plan.canReviseRoutes || plan.canActivate) {
            item {
                SectionCard("Tindakan rencana") {
                    if (plan.canUpdate) {
                        OutlinedButton(
                            onClick = onEdit,
                            modifier = Modifier.fillMaxWidth(),
                            enabled = !state.isSubmitting,
                        ) { Text("Ubah konfirmasi tujuan") }
                        Spacer(Modifier.height(10.dp))
                    }
                    if (plan.canReviseRoutes && !plan.canUpdate) {
                        OutlinedButton(
                            onClick = onEdit,
                            modifier = Modifier.fillMaxWidth(),
                            enabled = !state.isSubmitting,
                        ) { Text("Sesuaikan rute aktif") }
                        Spacer(Modifier.height(10.dp))
                    }
                    if (plan.canRefresh) {
                        OutlinedButton(
                            onClick = onRefreshBeneficiaries,
                            modifier = Modifier.fillMaxWidth(),
                            enabled = !state.isSubmitting,
                        ) { Text("Ambil ulang jumlah penerima") }
                        Spacer(Modifier.height(10.dp))
                    }
                    if (plan.canActivate) {
                        Button(
                            onClick = {
                                activationRequested = true
                                onCheckReadiness()
                            },
                            modifier = Modifier.fillMaxWidth(),
                            enabled = !state.isSubmitting,
                        ) {
                            if (state.isSubmitting) {
                                CircularProgressIndicator(
                                    modifier = Modifier.size(20.dp),
                                    strokeWidth = 2.dp,
                                    color = MaterialTheme.colorScheme.onPrimary,
                                )
                            } else {
                                Text("Aktifkan rencana")
                            }
                        }
                    }
                    if (plan.canDelete) {
                        Spacer(Modifier.height(10.dp))
                        TextButton(onClick = { showDeleteDialog = true }, modifier = Modifier.fillMaxWidth()) {
                            Icon(Icons.Outlined.Delete, contentDescription = null)
                            Spacer(Modifier.width(8.dp))
                            Text("Hapus draft")
                        }
                    }
                }
            }
        }
        if (state.readiness != null) {
            item {
                ReadinessCard(
                    ready = state.readiness.ready,
                    message = state.readiness.message,
                    issues = state.readiness.issues,
                    onActivate = null,
                )
            }
        }
        item {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                SummaryCard("Tujuan", plan.destinationCount.toString(), Modifier.weight(1f))
                SummaryCard("Penerima", plan.confirmedBeneficiaries.toString(), Modifier.weight(1f))
                SummaryCard("Porsi", plan.totalPortions.toString(), Modifier.weight(1f))
            }
        }
        if (plan.canExport) {
            item {
                SectionCard("Ekspor dan bagikan") {
                    Text("PDF", fontWeight = FontWeight.Bold)
                    Spacer(Modifier.height(8.dp))
                    Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        OutlinedButton(onClick = { onOpenDocument("pdf") }, enabled = !state.isSubmitting, modifier = Modifier.weight(1f).height(48.dp)) {
                            Icon(Icons.Outlined.PictureAsPdf, contentDescription = null)
                            Spacer(Modifier.width(6.dp))
                            Text("Buka")
                        }
                        Button(onClick = { onShareDocument("pdf") }, enabled = !state.isSubmitting, modifier = Modifier.weight(1f).height(48.dp)) {
                            Icon(Icons.Outlined.Share, contentDescription = null)
                            Spacer(Modifier.width(6.dp))
                            Text("Bagikan")
                        }
                    }
                    Spacer(Modifier.height(14.dp))
                    Text("Excel", fontWeight = FontWeight.Bold)
                    Spacer(Modifier.height(8.dp))
                    Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        OutlinedButton(onClick = { onOpenDocument("xlsx") }, enabled = !state.isSubmitting, modifier = Modifier.weight(1f).height(48.dp)) {
                            Icon(Icons.Outlined.Download, contentDescription = null)
                            Spacer(Modifier.width(6.dp))
                            Text("Buka")
                        }
                        Button(onClick = { onShareDocument("xlsx") }, enabled = !state.isSubmitting, modifier = Modifier.weight(1f).height(48.dp)) {
                            Icon(Icons.Outlined.Share, contentDescription = null)
                            Spacer(Modifier.width(6.dp))
                            Text("Bagikan")
                        }
                    }
                }
            }
        }
        if (!plan.generalNotes.isNullOrBlank()) {
            item {
                SectionCard("Catatan umum") {
                    Text(plan.generalNotes, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            }
        }
        item {
            Text(
                "Daftar tujuan",
                style = MaterialTheme.typography.titleLarge,
                fontWeight = FontWeight.Bold,
            )
        }
        val destinations = plan.destinations.orEmpty()
        if (destinations.isEmpty()) {
            item {
                Text(
                    "Belum ada tujuan pada rencana ini.",
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        } else {
            items(destinations, key = { it.id }) { destination ->
                DestinationCard(destination)
            }
        }
    }
}

@Composable
private fun DestinationCard(destination: FieldPlanDestination) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(22.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
    ) {
        Column(modifier = Modifier.padding(18.dp)) {
            Text(
                "${destination.sequenceOrder}. ${destination.name}",
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.Bold,
            )
            if (!destination.address.isNullOrBlank()) {
                Spacer(Modifier.height(6.dp))
                Text(
                    destination.address,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    style = MaterialTheme.typography.bodyMedium,
                )
            }
            Spacer(Modifier.height(12.dp))
            InfoLine(
                Icons.Outlined.People,
                "${destination.confirmedBeneficiaries} penerima terkonfirmasi",
            )
            Spacer(Modifier.height(6.dp))
            InfoLine(
                Icons.Outlined.Restaurant,
                "${destination.smallPortions} kecil • ${destination.largePortions} besar",
            )
            if (!destination.routeName.isNullOrBlank()) {
                Spacer(Modifier.height(6.dp))
                InfoLine(Icons.Outlined.LocationOn, destination.routeName)
            }
            if (destination.recipientGroups.isNotEmpty()) {
                Spacer(Modifier.height(14.dp))
                HorizontalDivider()
                Spacer(Modifier.height(10.dp))
                destination.recipientGroups.forEachIndexed { index, group ->
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.SpaceBetween,
                    ) {
                        Text(group.categoryName, modifier = Modifier.weight(1f))
                        Spacer(Modifier.width(12.dp))
                        Text("${group.confirmedBeneficiaries} orang", fontWeight = FontWeight.SemiBold)
                    }
                    if (index < destination.recipientGroups.lastIndex) Spacer(Modifier.height(7.dp))
                }
            }
        }
    }
}

private data class RecipientGroupEditState(
    val id: Long,
    val name: String,
    val registered: Int,
    val confirmed: String,
    val menuAudience: String,
    val portionSize: String,
    val notes: String,
)

private data class DestinationEditState(
    val id: Long,
    val name: String,
    val sequenceOrder: Int,
    val routeName: String,
    val specialNotes: String,
    val changeReason: String,
    val groups: List<RecipientGroupEditState>,
)

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun FieldPlanEditScreen(
    state: FieldPlanUiState,
    planId: Long,
    onLoad: (Long) -> Unit,
    onBack: () -> Unit,
    onSave: (UpdateFieldPlanRequest) -> Unit,
    onSaved: () -> Unit,
) {
    LaunchedEffect(planId) { onLoad(planId) }
    LaunchedEffect(state.successMessage) {
        if (state.successMessage != null) onSaved()
    }

    Scaffold(
        containerColor = MaterialTheme.colorScheme.background,
        topBar = {
            TopAppBar(
                title = { Text(if (state.selectedPlan?.canReviseRoutes == true && state.selectedPlan?.canUpdate == false) "Sesuaikan Rute" else "Konfirmasi Tujuan", fontWeight = FontWeight.Bold) },
                navigationIcon = {
                    IconButton(onClick = onBack, enabled = !state.isSubmitting) {
                        Icon(Icons.AutoMirrored.Outlined.ArrowBack, contentDescription = "Kembali")
                    }
                },
                colors = sppgTopAppBarColors(),
            )
        },
    ) { innerPadding ->
        when {
            state.isLoading && state.selectedPlan == null -> LoadingContent(innerPadding)
            state.selectedPlan == null -> ErrorContent(
                message = state.errorMessage ?: "Rincian rencana tidak tersedia.",
                padding = innerPadding,
                onRetry = { onLoad(planId) },
            )
            else -> FieldPlanEditForm(
                plan = state.selectedPlan,
                padding = innerPadding,
                isSubmitting = state.isSubmitting,
                serverError = state.errorMessage,
                onSave = onSave,
            )
        }
    }
}

@Composable
private fun FieldPlanEditForm(
    plan: FieldPlan,
    padding: PaddingValues,
    isSubmitting: Boolean,
    serverError: String?,
    onSave: (UpdateFieldPlanRequest) -> Unit,
) {
    val routeOnly = plan.canReviseRoutes && !plan.canUpdate
    var generalNotes by remember(plan.id) { mutableStateOf(plan.generalNotes.orEmpty()) }
    var destinations by remember(plan.id) {
        mutableStateOf(
            plan.destinations.orEmpty().map { destination ->
                DestinationEditState(
                    id = destination.id,
                    name = destination.name,
                    sequenceOrder = destination.sequenceOrder,
                    routeName = destination.routeName.orEmpty(),
                    specialNotes = destination.specialNotes.orEmpty(),
                    changeReason = destination.changeReason.orEmpty(),
                    groups = destination.recipientGroups.map { group ->
                        RecipientGroupEditState(
                            id = group.id,
                            name = group.categoryName,
                            registered = group.registeredBeneficiaries,
                            confirmed = group.confirmedBeneficiaries.toString(),
                            menuAudience = group.menuAudience ?: "student",
                            portionSize = group.portionSize ?: "small",
                            notes = group.notes.orEmpty(),
                        )
                    },
                )
            },
        )
    }
    var localError by remember { mutableStateOf<String?>(null) }
    val routeOptions = remember(plan.id, destinations.size) {
        (
            destinations.map { it.routeName }.filter { it.isNotBlank() } +
                listOf("Rute Utama") +
                (1..maxOf(10, destinations.size)).map { "Rute $it" }
            ).distinct()
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
            Text(
                plan.planNumber,
                color = MaterialTheme.colorScheme.primary,
                fontWeight = FontWeight.Bold,
            )
            Spacer(Modifier.height(4.dp))
            Text(formatDate(plan.distributionDate), color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        if (routeOnly) {
            item {
                Card(
                    colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer),
                    shape = RoundedCornerShape(15.dp),
                ) {
                    Text(
                        "Rencana sudah aktif. Hanya nama rute dan urutan tujuan yang dapat diubah; data penerima dan jumlah porsi tetap terkunci.",
                        modifier = Modifier.padding(16.dp),
                        style = MaterialTheme.typography.bodyMedium,
                    )
                }
            }
        }
        if (serverError != null || localError != null) {
            item {
                FeedbackCard(
                    message = localError ?: serverError.orEmpty(),
                    isError = true,
                    onDismiss = { localError = null },
                )
            }
        }
        if (!routeOnly) item {
            OutlinedTextField(
                value = generalNotes,
                onValueChange = { generalNotes = it },
                modifier = Modifier.fillMaxWidth(),
                label = { Text("Catatan umum") },
                minLines = 2,
                enabled = !isSubmitting,
            )
        }
        items(destinations, key = { it.id }) { destination ->
            val destinationIndex = destinations.indexOfFirst { it.id == destination.id }
            DestinationEditCard(
                destination = destination,
                routeOptions = routeOptions,
                enabled = !isSubmitting,
                routeOnly = routeOnly,
                onDestinationChange = { updated ->
                    destinations = destinations.toMutableList().also { it[destinationIndex] = updated }
                    localError = null
                },
            )
        }
        item {
            Button(
                onClick = {
                    val servedDestinations = destinations.filter { destination ->
                        destination.groups.sumOf { it.confirmed.toIntOrNull() ?: 0 } > 0
                    }
                    val missingRoute = servedDestinations.firstOrNull { it.routeName.isBlank() }
                    if (missingRoute != null) {
                        localError = "Rute untuk ${missingRoute.name} wajib dipilih."
                        return@Button
                    }
                    val duplicateOrder = servedDestinations
                        .groupBy { it.routeName.trim() to it.sequenceOrder }
                        .entries
                        .firstOrNull { it.value.size > 1 }
                    if (duplicateOrder != null) {
                        localError = "Urutan ${duplicateOrder.key.second} pada ${duplicateOrder.key.first} digunakan lebih dari satu tujuan."
                        return@Button
                    }
                    val requestDestinations = destinations.map { destination ->
                        val groups = destination.groups.map { group ->
                            val confirmed = group.confirmed.toIntOrNull()
                            if (!routeOnly && (confirmed == null || confirmed < 0)) {
                                localError = "Jumlah aktual pada ${group.name} harus berupa angka nol atau lebih."
                                return@Button
                            }
                            UpdateRecipientGroupRequest(
                                id = group.id,
                                confirmedBeneficiaries = confirmed ?: 0,
                                menuAudience = group.menuAudience,
                                portionSize = group.portionSize,
                                notes = group.notes.trim().ifBlank { null },
                            )
                        }
                        val changed = destination.groups.any {
                            it.confirmed.toIntOrNull() != it.registered
                        }
                        if (!routeOnly && changed && destination.changeReason.isBlank()) {
                            localError = "${destination.name}: alasan perubahan jumlah penerima wajib diisi."
                            return@Button
                        }
                        UpdateFieldPlanDestinationRequest(
                            id = destination.id,
                            routeName = destination.routeName.trim().ifBlank { null },
                            sequenceOrder = destination.sequenceOrder,
                            specialNotes = destination.specialNotes.trim().ifBlank { null },
                            changeReason = destination.changeReason.trim().ifBlank { null },
                            noServiceReason = if (destination.groups.sumOf { it.confirmed.toIntOrNull() ?: 0 } == 0) destination.changeReason.trim().ifBlank { null } else null,
                            recipientGroups = groups,
                        )
                    }
                    localError = null
                    onSave(
                        UpdateFieldPlanRequest(
                            generalNotes = generalNotes.trim().ifBlank { null },
                            destinations = requestDestinations,
                        ),
                    )
                },
                modifier = Modifier
                    .fillMaxWidth()
                    .height(52.dp),
                enabled = !isSubmitting && destinations.isNotEmpty(),
                shape = RoundedCornerShape(14.dp),
            ) {
                if (isSubmitting) {
                    CircularProgressIndicator(
                        modifier = Modifier.size(21.dp),
                        strokeWidth = 2.dp,
                        color = MaterialTheme.colorScheme.onPrimary,
                    )
                } else {
                    Text(if (routeOnly) "Simpan penyesuaian rute" else "Simpan konfirmasi", fontWeight = FontWeight.SemiBold)
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun DestinationEditCard(
    destination: DestinationEditState,
    routeOptions: List<String>,
    enabled: Boolean,
    routeOnly: Boolean,
    onDestinationChange: (DestinationEditState) -> Unit,
) {
    var routeExpanded by remember(destination.id) { mutableStateOf(false) }
    val hasChangedCount = destination.groups.any {
        it.confirmed.toIntOrNull() != it.registered
    }
    val isServed = destination.groups.sumOf { it.confirmed.toIntOrNull() ?: 0 } > 0
    val routeFieldsEnabled = enabled && (!routeOnly || isServed)
    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(18.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
    ) {
        Column(modifier = Modifier.padding(18.dp)) {
            Text(
                "${destination.sequenceOrder}. ${destination.name}",
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.Bold,
            )
            Spacer(Modifier.height(14.dp))
            ExposedDropdownMenuBox(
                expanded = routeExpanded,
                onExpandedChange = { if (routeFieldsEnabled) routeExpanded = !routeExpanded },
            ) {
                OutlinedTextField(
                    value = destination.routeName,
                    onValueChange = {},
                    modifier = Modifier
                        .fillMaxWidth()
                        .menuAnchor(ExposedDropdownMenuAnchorType.PrimaryNotEditable),
                    label = { Text("Nama rute") },
                    placeholder = { Text("Pilih rute") },
                    readOnly = true,
                    singleLine = true,
                    enabled = routeFieldsEnabled,
                    trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(routeExpanded) },
                )
                ExposedDropdownMenu(
                    expanded = routeExpanded,
                    onDismissRequest = { routeExpanded = false },
                ) {
                    routeOptions.forEach { route ->
                        DropdownMenuItem(
                            text = { Text(route) },
                            onClick = {
                                onDestinationChange(destination.copy(routeName = route))
                                routeExpanded = false
                            },
                        )
                    }
                }
            }
            Spacer(Modifier.height(10.dp))
            OutlinedTextField(
                value = destination.sequenceOrder.toString(),
                onValueChange = { value ->
                    value.filter(Char::isDigit).toIntOrNull()?.let { sequence ->
                        onDestinationChange(destination.copy(sequenceOrder = maxOf(1, sequence)))
                    }
                },
                modifier = Modifier.fillMaxWidth(),
                label = { Text("Urutan tujuan dalam rute") },
                singleLine = true,
                enabled = routeFieldsEnabled,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
            )
            if (routeOnly) {
                Spacer(Modifier.height(12.dp))
                Text(
                    if (isServed) "${destination.groups.sumOf { it.confirmed.toIntOrNull() ?: 0 }} porsi · data penerima tetap terkunci" else "Tujuan tidak dilayani · rute tidak diperlukan",
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    style = MaterialTheme.typography.bodyMedium,
                )
            }
            if (!routeOnly) {
            Spacer(Modifier.height(16.dp))
            Text("Kelompok penerima", fontWeight = FontWeight.SemiBold)
            Spacer(Modifier.height(10.dp))
            destination.groups.forEach { group ->
                val groupIndex = destination.groups.indexOfFirst { it.id == group.id }
                Text(
                    "${group.name} • master ${group.registered}",
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    style = MaterialTheme.typography.bodyMedium,
                )
                Spacer(Modifier.height(5.dp))
                OutlinedTextField(
                    value = group.confirmed,
                    onValueChange = { value ->
                        val groups = destination.groups.toMutableList().also {
                            it[groupIndex] = group.copy(confirmed = value.filter(Char::isDigit))
                        }
                        onDestinationChange(destination.copy(groups = groups))
                    },
                    modifier = Modifier.fillMaxWidth(),
                    label = { Text("Jumlah aktual") },
                    singleLine = true,
                    enabled = enabled,
                    keyboardOptions = KeyboardOptions(
                        keyboardType = KeyboardType.Number,
                        imeAction = ImeAction.Next,
                    ),
                )
                Spacer(Modifier.height(8.dp))
                OutlinedTextField(
                    value = group.menuAudience,
                    onValueChange = { value ->
                        val groups = destination.groups.toMutableList().also { it[groupIndex] = group.copy(menuAudience = value) }
                        onDestinationChange(destination.copy(groups = groups))
                    },
                    modifier = Modifier.fillMaxWidth(),
                    label = { Text("Kelompok menu") },
                    singleLine = true,
                    enabled = enabled,
                )
                Spacer(Modifier.height(8.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    listOf("small" to "Porsi kecil", "large" to "Porsi besar").forEach { (value, label) ->
                        OutlinedButton(
                            onClick = {
                                val groups = destination.groups.toMutableList().also { it[groupIndex] = group.copy(portionSize = value) }
                                onDestinationChange(destination.copy(groups = groups))
                            },
                            enabled = enabled && group.portionSize != value,
                        ) { Text(label) }
                    }
                }
                Spacer(Modifier.height(8.dp))
                OutlinedTextField(
                    value = group.notes,
                    onValueChange = { value ->
                        val groups = destination.groups.toMutableList().also {
                            it[groupIndex] = group.copy(notes = value)
                        }
                        onDestinationChange(destination.copy(groups = groups))
                    },
                    modifier = Modifier.fillMaxWidth(),
                    label = { Text("Catatan kelompok") },
                    enabled = enabled,
                    minLines = 2,
                )
                Spacer(Modifier.height(12.dp))
            }
            if (hasChangedCount) {
                OutlinedTextField(
                    value = destination.changeReason,
                    onValueChange = { onDestinationChange(destination.copy(changeReason = it)) },
                    modifier = Modifier.fillMaxWidth(),
                    label = { Text(if (destination.groups.sumOf { it.confirmed.toIntOrNull() ?: 0 } == 0) "Alasan tidak dilayani*" else "Alasan perubahan jumlah*") },
                    minLines = 2,
                    enabled = enabled,
                    isError = destination.changeReason.isBlank(),
                )
                Spacer(Modifier.height(10.dp))
            }
            OutlinedTextField(
                value = destination.specialNotes,
                onValueChange = { onDestinationChange(destination.copy(specialNotes = it)) },
                modifier = Modifier.fillMaxWidth(),
                label = { Text("Catatan tujuan") },
                minLines = 2,
                enabled = enabled,
            )
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun FieldPlanCreateScreen(
    state: FieldPlanUiState,
    onBack: () -> Unit,
    onLoadOptions: () -> Unit,
    onCreate: (String, Long?, String?) -> Unit,
    onClearFeedback: () -> Unit,
) {
    LaunchedEffect(Unit) { onLoadOptions() }
    var selectedDate by remember { mutableStateOf<String?>(null) }
    var notes by remember { mutableStateOf("") }
    val context = LocalContext.current

    Scaffold(
        containerColor = MaterialTheme.colorScheme.background,
        topBar = {
            TopAppBar(
                title = { Text("Tambah Rencana Distribusi", fontWeight = FontWeight.Bold) },
                navigationIcon = {
                    IconButton(onClick = onBack, enabled = !state.isSubmitting) {
                        Icon(Icons.AutoMirrored.Outlined.ArrowBack, contentDescription = "Kembali")
                    }
                },
                colors = sppgTopAppBarColors(),
            )
        },
    ) { padding ->
        if (state.isLoading && state.options.isEmpty()) {
            LoadingContent(padding)
        } else {
            val optionsForDate = selectedDate?.let { date ->
                state.options.filter { it.distributionDate == date }
            }.orEmpty()
            val selectedOption = optionsForDate.firstOrNull()
            LazyColumn(
                modifier = Modifier.fillMaxSize(),
                contentPadding = PaddingValues(20.dp, padding.calculateTopPadding() + 12.dp, 20.dp, 32.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                state.errorMessage?.let { message ->
                    item { FeedbackCard(message, true, onClearFeedback) }
                }
                item {
                    Text("1. Tentukan tanggal distribusi", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                    Spacer(Modifier.height(6.dp))
                    Text("Penerima dimuat dari periode aktif. Rencana tidak terikat menu.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    Spacer(Modifier.height(12.dp))
                    OutlinedButton(
                        onClick = {
                            val initial = selectedDate?.let { runCatching { LocalDate.parse(it) }.getOrNull() } ?: LocalDate.now()
                            DatePickerDialog(
                                context,
                                { _, year, month, day ->
                                    selectedDate = LocalDate.of(year, month + 1, day).toString()
                                },
                                initial.year,
                                initial.monthValue - 1,
                                initial.dayOfMonth,
                            ).show()
                        },
                        modifier = Modifier.fillMaxWidth().height(52.dp),
                        enabled = !state.isSubmitting,
                    ) {
                        Icon(Icons.Outlined.CalendarMonth, contentDescription = null)
                        Spacer(Modifier.width(8.dp))
                        Text(selectedDate?.let(::formatDate) ?: "Pilih tanggal distribusi", fontWeight = FontWeight.Bold)
                    }
                }
                if (selectedDate == null) {
                    item {
                        SectionCard("2. Ketersediaan penerima") {
                            Text("Tentukan tanggal distribusi terlebih dahulu.")
                        }
                    }
                } else if (optionsForDate.isEmpty()) {
                    item {
                        SectionCard("Tidak ada menu pada tanggal ini") {
                        Text("Belum ada periode penerima aktif untuk ${formatDate(requireNotNull(selectedDate))}.")
                        }
                    }
                } else {
                    item {
                        Text("2. Ketersediaan penerima", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                    }
                    item {
                        SectionCard(if (selectedOption?.isAvailable == true) "Siap dibuat" else "Belum dapat digunakan") {
                            Text(selectedOption?.unavailableReason ?: "Penerima dari periode aktif siap dimuat.")
                        }
                    }
                }
                item {
                    Text("3. Catatan rencana", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                    Spacer(Modifier.height(8.dp))
                    OutlinedTextField(
                        value = notes,
                        onValueChange = { notes = it },
                        modifier = Modifier.fillMaxWidth(),
                        label = { Text("Catatan umum (opsional)") },
                        minLines = 3,
                        enabled = !state.isSubmitting,
                    )
                }
                item {
                    Button(
                        onClick = {
                            selectedDate?.let {
                                onCreate(it, selectedOption?.id, notes.trim().ifBlank { null })
                            }
                        },
                        enabled = selectedDate != null && selectedOption?.isAvailable == true && !state.isSubmitting,
                        modifier = Modifier.fillMaxWidth().height(52.dp),
                    ) {
                        if (state.isSubmitting) CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp)
                        else Text("Buat rencana dan muat penerima", fontWeight = FontWeight.Bold)
                    }
                    Spacer(Modifier.height(6.dp))
                    Text(
                        "Setelah rencana dibuat, sekolah/Posyandu dan jumlah penerima dimuat otomatis lalu Anda diarahkan untuk mengonfirmasi rute.",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
        }
    }
}

@Composable
private fun FieldPlanOptionCard(
    option: FieldPlanOption,
    selected: Boolean,
    enabled: Boolean = true,
    onClick: () -> Unit,
) {
    Card(
        modifier = Modifier.fillMaxWidth().clickable(enabled = enabled, onClick = onClick),
        colors = CardDefaults.cardColors(
            containerColor = if (selected) MaterialTheme.colorScheme.primaryContainer else MaterialTheme.colorScheme.surface,
        ),
        shape = RoundedCornerShape(18.dp),
    ) {
        Column(Modifier.padding(16.dp)) {
            Text(option.menuName, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            Spacer(Modifier.height(5.dp))
            Text(formatDate(option.distributionDate), color = MaterialTheme.colorScheme.onSurfaceVariant)
            val cycle = listOfNotNull(option.cycleCode, option.labelCode).joinToString(" • ")
            if (cycle.isNotBlank()) {
                Spacer(Modifier.height(4.dp))
                Text(cycle, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            if (option.isRapel) {
                Spacer(Modifier.height(5.dp))
                Text("Distribusi rapel", color = MaterialTheme.colorScheme.primary, fontWeight = FontWeight.SemiBold)
            }
            if (!enabled && !option.unavailableReason.isNullOrBlank()) {
                Spacer(Modifier.height(7.dp))
                Text(option.unavailableReason, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
            }
        }
    }
}

@Composable
private fun ReadinessCard(
    ready: Boolean,
    message: String,
    issues: List<String>,
    onActivate: (() -> Unit)?,
) {
    Card(
        colors = CardDefaults.cardColors(
            containerColor = if (ready) MaterialTheme.colorScheme.primaryContainer
            else MaterialTheme.colorScheme.secondaryContainer,
        ),
        shape = RoundedCornerShape(18.dp),
    ) {
        Column(modifier = Modifier.padding(18.dp)) {
            Text(message, fontWeight = FontWeight.Bold)
            if (issues.isNotEmpty()) {
                Spacer(Modifier.height(8.dp))
                issues.forEach { issue ->
                    Text("• $issue", style = MaterialTheme.typography.bodyMedium)
                    Spacer(Modifier.height(4.dp))
                }
            }
            if (onActivate != null) {
                Spacer(Modifier.height(12.dp))
                Button(onClick = onActivate, modifier = Modifier.fillMaxWidth()) {
                    Text("Aktifkan rencana")
                }
            }
        }
    }
}

@Composable
private fun FeedbackCard(message: String, isError: Boolean, onDismiss: () -> Unit) {
    Card(
        colors = CardDefaults.cardColors(
            containerColor = if (isError) MaterialTheme.colorScheme.errorContainer
            else MaterialTheme.colorScheme.primaryContainer,
        ),
        shape = RoundedCornerShape(15.dp),
    ) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(start = 16.dp, top = 12.dp, end = 8.dp, bottom = 12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(message, modifier = Modifier.weight(1f), style = MaterialTheme.typography.bodyMedium)
            TextButton(onClick = onDismiss) { Text("Tutup") }
        }
    }
}

@Composable
private fun SummaryCard(label: String, value: String, modifier: Modifier = Modifier) {
    Card(
        modifier = modifier,
        shape = RoundedCornerShape(17.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer),
    ) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .padding(vertical = 14.dp, horizontal = 10.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(value, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
            Text(label, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

@Composable
private fun SectionCard(title: String, content: @Composable () -> Unit) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(18.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
    ) {
        Column(modifier = Modifier.padding(18.dp)) {
            Text(title, fontWeight = FontWeight.Bold)
            Spacer(Modifier.height(8.dp))
            content()
        }
    }
}

@Composable
private fun StatusBadge(status: String, label: String) {
    SppgStatusPill(label)
}

@Composable
private fun InfoLine(icon: ImageVector, text: String) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Icon(
            imageVector = icon,
            contentDescription = null,
            modifier = Modifier.size(18.dp),
            tint = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Spacer(Modifier.width(8.dp))
        Text(
            text,
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

@Composable
private fun LoadingContent(padding: PaddingValues) {
    Box(
        modifier = Modifier
            .fillMaxSize()
            .padding(padding),
        contentAlignment = Alignment.Center,
    ) {
        CircularProgressIndicator()
    }
}

@Composable
private fun ErrorContent(message: String, padding: PaddingValues, onRetry: () -> Unit) {
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
private fun EmptyContent(padding: PaddingValues) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(padding)
            .padding(24.dp),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Icon(
            Icons.Outlined.CalendarMonth,
            contentDescription = null,
            modifier = Modifier.size(48.dp),
            tint = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Spacer(Modifier.height(12.dp))
        Text("Belum ada rencana distribusi", fontWeight = FontWeight.Bold)
        Spacer(Modifier.height(6.dp))
        Text(
            "Buat rencana dari menu siklus aktif melalui tombol tambah.",
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

private fun formatDate(value: String): String = runCatching {
    LocalDate.parse(value).format(DateTimeFormatter.ofPattern("EEEE, d MMMM yyyy", Locale.forLanguageTag("id-ID")))
}.getOrDefault(value)
