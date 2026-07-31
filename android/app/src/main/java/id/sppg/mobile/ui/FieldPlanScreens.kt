package id.sppg.mobile.ui

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
import androidx.compose.material.icons.outlined.LocationOn
import androidx.compose.material.icons.outlined.People
import androidx.compose.material.icons.outlined.PictureAsPdf
import androidx.compose.material.icons.outlined.Refresh
import androidx.compose.material.icons.outlined.Restaurant
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ExperimentalMaterial3Api
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import id.sppg.mobile.data.remote.FieldPlan
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
    onPlanClick: (Long) -> Unit,
) {
    LaunchedEffect(Unit) { onLoad() }

    Scaffold(
        containerColor = MaterialTheme.colorScheme.background,
        topBar = {
            TopAppBar(
                title = { Text("Rencana Lapangan", fontWeight = FontWeight.Bold) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Outlined.ArrowBack, contentDescription = "Kembali")
                    }
                },
                actions = {
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
            state.isLoading && state.plans.isEmpty() -> LoadingContent(innerPadding)
            state.errorMessage != null && state.plans.isEmpty() -> ErrorContent(
                message = state.errorMessage,
                padding = innerPadding,
                onRetry = onRefresh,
            )
            state.plans.isEmpty() -> EmptyContent(innerPadding)
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
                                    "Rencana H-3",
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
                    Text(
                        "RENCANA TERBARU",
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        style = MaterialTheme.typography.labelMedium,
                        fontWeight = FontWeight.Bold,
                    )
                }
                items(state.plans, key = { it.id }) { plan ->
                    FieldPlanCard(plan = plan, onClick = { onPlanClick(plan.id) })
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
    onOpenDocument: () -> Unit,
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
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = MaterialTheme.colorScheme.background,
                ),
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
                onOpenDocument = onOpenDocument,
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
    onOpenDocument: () -> Unit,
    onClearFeedback: () -> Unit,
) {
    var showActivationDialog by remember { mutableStateOf(false) }
    var activationNotes by remember { mutableStateOf("") }

    if (showActivationDialog) {
        AlertDialog(
            onDismissRequest = { if (!state.isSubmitting) showActivationDialog = false },
            title = { Text("Aktifkan rencana?") },
            text = {
                Column {
                    Text("Aktivasi akan membuat pekerjaan Pengolahan, Pemorsian, dan Distribusi. Data rencana tidak dapat diubah lagi.")
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
        if (plan.canUpdate || plan.canActivate) {
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
                    if (plan.canActivate) {
                        Button(
                            onClick = onCheckReadiness,
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
                                Text("Periksa kesiapan")
                            }
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
                    onActivate = if (state.readiness.ready && plan.canActivate) {
                        { showActivationDialog = true }
                    } else {
                        null
                    },
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
                OutlinedButton(
                    onClick = onOpenDocument,
                    enabled = !state.isSubmitting,
                    modifier = Modifier.fillMaxWidth().height(50.dp),
                    shape = RoundedCornerShape(16.dp),
                ) {
                    Icon(Icons.Outlined.PictureAsPdf, contentDescription = null)
                    Spacer(Modifier.width(8.dp))
                    Text("Lihat dokumen PDF", fontWeight = FontWeight.Bold)
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
            if (!destination.plannedArrivalTime.isNullOrBlank()) {
                Spacer(Modifier.height(6.dp))
                InfoLine(Icons.Outlined.CalendarMonth, "Tiba ${destination.plannedArrivalTime}")
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
    val notes: String,
)

private data class DestinationEditState(
    val id: Long,
    val name: String,
    val sequenceOrder: Int,
    val routeName: String,
    val departureTime: String,
    val arrivalTime: String,
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
                title = { Text("Konfirmasi Tujuan", fontWeight = FontWeight.Bold) },
                navigationIcon = {
                    IconButton(onClick = onBack, enabled = !state.isSubmitting) {
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
    var generalNotes by remember(plan.id) { mutableStateOf(plan.generalNotes.orEmpty()) }
    var destinations by remember(plan.id) {
        mutableStateOf(
            plan.destinations.orEmpty().map { destination ->
                DestinationEditState(
                    id = destination.id,
                    name = destination.name,
                    sequenceOrder = destination.sequenceOrder,
                    routeName = destination.routeName.orEmpty(),
                    departureTime = destination.plannedDepartureTime.orEmpty(),
                    arrivalTime = destination.plannedArrivalTime.orEmpty(),
                    specialNotes = destination.specialNotes.orEmpty(),
                    changeReason = destination.changeReason.orEmpty(),
                    groups = destination.recipientGroups.map { group ->
                        RecipientGroupEditState(
                            id = group.id,
                            name = group.categoryName,
                            registered = group.registeredBeneficiaries,
                            confirmed = group.confirmedBeneficiaries.toString(),
                            notes = group.notes.orEmpty(),
                        )
                    },
                )
            },
        )
    }
    var localError by remember { mutableStateOf<String?>(null) }

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
        if (serverError != null || localError != null) {
            item {
                FeedbackCard(
                    message = localError ?: serverError.orEmpty(),
                    isError = true,
                    onDismiss = { localError = null },
                )
            }
        }
        item {
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
                enabled = !isSubmitting,
                onDestinationChange = { updated ->
                    destinations = destinations.toMutableList().also { it[destinationIndex] = updated }
                    localError = null
                },
            )
        }
        item {
            Button(
                onClick = {
                    val requestDestinations = destinations.map { destination ->
                        val groups = destination.groups.map { group ->
                            val confirmed = group.confirmed.toIntOrNull()
                            if (confirmed == null || confirmed < 0) {
                                localError = "Jumlah aktual pada ${group.name} harus berupa angka nol atau lebih."
                                return@Button
                            }
                            UpdateRecipientGroupRequest(
                                id = group.id,
                                confirmedBeneficiaries = confirmed,
                                notes = group.notes.trim().ifBlank { null },
                            )
                        }
                        val changed = destination.groups.any {
                            it.confirmed.toIntOrNull() != it.registered
                        }
                        if (changed && destination.changeReason.isBlank()) {
                            localError = "${destination.name}: alasan perubahan jumlah penerima wajib diisi."
                            return@Button
                        }
                        UpdateFieldPlanDestinationRequest(
                            id = destination.id,
                            routeName = destination.routeName.trim().ifBlank { null },
                            sequenceOrder = destination.sequenceOrder,
                            plannedDepartureTime = destination.departureTime.ifBlank { null },
                            plannedArrivalTime = destination.arrivalTime.ifBlank { null },
                            specialNotes = destination.specialNotes.trim().ifBlank { null },
                            changeReason = destination.changeReason.trim().ifBlank { null },
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
                    Text("Simpan konfirmasi", fontWeight = FontWeight.SemiBold)
                }
            }
        }
    }
}

@Composable
private fun DestinationEditCard(
    destination: DestinationEditState,
    enabled: Boolean,
    onDestinationChange: (DestinationEditState) -> Unit,
) {
    val hasChangedCount = destination.groups.any {
        it.confirmed.toIntOrNull() != it.registered
    }
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
            OutlinedTextField(
                value = destination.routeName,
                onValueChange = { onDestinationChange(destination.copy(routeName = it)) },
                modifier = Modifier.fillMaxWidth(),
                label = { Text("Nama rute") },
                singleLine = true,
                enabled = enabled,
            )
            Spacer(Modifier.height(10.dp))
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                OutlinedTextField(
                    value = destination.departureTime,
                    onValueChange = { onDestinationChange(destination.copy(departureTime = it)) },
                    modifier = Modifier.weight(1f),
                    label = { Text("Berangkat") },
                    placeholder = { Text("07:00") },
                    singleLine = true,
                    enabled = enabled,
                    keyboardOptions = KeyboardOptions(
                        keyboardType = KeyboardType.Number,
                        imeAction = ImeAction.Next,
                    ),
                )
                OutlinedTextField(
                    value = destination.arrivalTime,
                    onValueChange = { onDestinationChange(destination.copy(arrivalTime = it)) },
                    modifier = Modifier.weight(1f),
                    label = { Text("Tiba") },
                    placeholder = { Text("08:00") },
                    singleLine = true,
                    enabled = enabled,
                    keyboardOptions = KeyboardOptions(
                        keyboardType = KeyboardType.Number,
                        imeAction = ImeAction.Next,
                    ),
                )
            }
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
                    label = { Text("Alasan perubahan jumlah*") },
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

@Composable
private fun ReadinessCard(
    ready: Boolean,
    message: String,
    issues: List<String>,
    onActivate: (() -> Unit)?,
) {
    Card(
        colors = CardDefaults.cardColors(
            containerColor = if (ready) Color(0xFFD8EBDD) else Color(0xFFFFE2C7),
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
            containerColor = if (isError) Color(0xFFFFDAD6) else Color(0xFFD8EBDD),
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
        Text("Belum ada rencana lapangan", fontWeight = FontWeight.Bold)
        Spacer(Modifier.height(6.dp))
        Text(
            "Rencana yang dibuat pada sistem SPPG V3 akan tampil di sini.",
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

private fun formatDate(value: String): String = runCatching {
    LocalDate.parse(value).format(DateTimeFormatter.ofPattern("EEEE, d MMMM yyyy", Locale.forLanguageTag("id-ID")))
}.getOrDefault(value)
