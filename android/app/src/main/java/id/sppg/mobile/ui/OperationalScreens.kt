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
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.automirrored.outlined.Assignment
import androidx.compose.material.icons.outlined.CalendarMonth
import androidx.compose.material.icons.outlined.Person
import androidx.compose.material.icons.outlined.Refresh
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import id.sppg.mobile.data.remote.OperationalField
import id.sppg.mobile.data.remote.OperationalRecord
import id.sppg.mobile.data.remote.OperationalSection
import java.time.LocalDate
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
    onRecordClick: (Long) -> Unit,
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
                    Text(
                        record.number,
                        color = MaterialTheme.colorScheme.primary,
                        style = MaterialTheme.typography.labelLarge,
                        fontWeight = FontWeight.Bold,
                    )
                    Spacer(Modifier.height(3.dp))
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
) {
    LaunchedEffect(module, recordId) { onLoad(module, recordId) }

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
            state.errorMessage != null -> OperationalError(
                message = state.errorMessage,
                padding = innerPadding,
                onRetry = { onLoad(module, recordId) },
            )
            state.selectedRecord != null -> OperationalDetailContent(
                record = state.selectedRecord,
                padding = innerPadding,
            )
        }
    }
}

@Composable
private fun OperationalDetailContent(record: OperationalRecord, padding: PaddingValues) {
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
                        horizontalArrangement = Arrangement.SpaceBetween,
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(record.number, fontWeight = FontWeight.Bold, color = Color.White)
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
                OperationalSectionCard(section)
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
private fun OperationalSectionCard(section: OperationalSection) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(22.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
    ) {
        Column(modifier = Modifier.padding(18.dp)) {
            Text(section.title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
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
        Text(
            field.value,
            modifier = Modifier.weight(1f),
            fontWeight = FontWeight.Medium,
            style = MaterialTheme.typography.bodyMedium,
        )
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
