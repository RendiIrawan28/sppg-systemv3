package id.sppg.mobile.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.CalendarMonth
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp

@Composable
fun WorkHistoryTabs(
    showHistory: Boolean,
    activeLabel: String = "Pekerjaan Hari Ini",
    onShowHistoryChange: (Boolean) -> Unit,
) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        FilterChip(
            selected = !showHistory,
            onClick = { onShowHistoryChange(false) },
            label = { Text(activeLabel) },
            modifier = Modifier.weight(1f),
        )
        FilterChip(
            selected = showHistory,
            onClick = { onShowHistoryChange(true) },
            label = { Text("Riwayat") },
            modifier = Modifier.weight(1f),
        )
    }
}

@Composable
fun HistoryDateSelector(label: String, onClick: () -> Unit) {
    OutlinedButton(
        onClick = onClick,
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(15.dp),
    ) {
        Icon(Icons.Outlined.CalendarMonth, contentDescription = null)
        Text("Tanggal: $label", modifier = Modifier.padding(start = 8.dp))
    }
}

@Composable
fun HistoryEmptyState() {
    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(18.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
    ) {
        Column(Modifier.padding(22.dp)) {
            Text("Belum ada riwayat", fontWeight = FontWeight.Bold)
            Text(
                "Tidak ada pekerjaan selesai pada tanggal ini",
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                style = MaterialTheme.typography.bodyMedium,
            )
        }
    }
}
