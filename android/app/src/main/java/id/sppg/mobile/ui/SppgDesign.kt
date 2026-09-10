package id.sppg.mobile.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.height
import androidx.compose.material.icons.outlined.Check
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.Assignment
import androidx.compose.material.icons.outlined.CleaningServices
import androidx.compose.material.icons.outlined.Assessment
import androidx.compose.material.icons.outlined.Groups
import androidx.compose.material.icons.outlined.Person
import androidx.compose.material.icons.outlined.GridView
import androidx.compose.material.icons.outlined.MedicalInformation
import androidx.compose.material.icons.outlined.Inventory2
import androidx.compose.material.icons.outlined.LocalShipping
import androidx.compose.material.icons.outlined.Map
import androidx.compose.material.icons.outlined.Notifications
import androidx.compose.material.icons.outlined.Refresh
import androidx.compose.material.icons.outlined.Restaurant
import androidx.compose.material.icons.outlined.Security
import androidx.compose.material.icons.outlined.SetMeal
import androidx.compose.material.icons.outlined.WaterDrop
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBarColors
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.luminance
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import id.sppg.mobile.ui.theme.Amber
import id.sppg.mobile.ui.theme.Navy
import id.sppg.mobile.ui.theme.NavyMedium

val SppgPagePadding = 16.dp
val SppgSectionSpacing = 16.dp

@Composable
fun SppgTimelineItem(label: String, completed: Boolean, current: Boolean, last: Boolean) {
    val accent = if (completed) moduleVisual("pengolahan").color else MaterialTheme.colorScheme.primary
    Row(verticalAlignment = Alignment.Top) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Box(
                Modifier.size(24.dp)
                    .background(if (completed) accent else MaterialTheme.colorScheme.surface, CircleShape)
                    .border(BorderStroke(2.dp, if (completed || current) accent else MaterialTheme.colorScheme.outlineVariant), CircleShape),
                contentAlignment = Alignment.Center,
            ) {
                if (completed) Icon(Icons.Outlined.Check, null, tint = MaterialTheme.colorScheme.surface, modifier = Modifier.size(16.dp))
                else if (current) Box(Modifier.size(8.dp).background(accent, CircleShape))
            }
            if (!last) Box(Modifier.width(2.dp).height(16.dp).background(MaterialTheme.colorScheme.outlineVariant))
        }
        Text(label, modifier = Modifier.padding(start = 12.dp, top = 2.dp),
            style = MaterialTheme.typography.bodyMedium,
            fontWeight = if (current || completed) FontWeight.SemiBold else FontWeight.Normal,
            color = if (current || completed) MaterialTheme.colorScheme.onSurface else MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

data class ModuleVisual(
    val icon: ImageVector,
    val color: Color,
    val container: Color,
)

@Composable
fun moduleVisual(slug: String): ModuleVisual {
    val icon = when {
        slug == "field-plans" -> Icons.Outlined.Map
        slug == "tasks" -> Icons.Outlined.Notifications
        slug == "modules" -> Icons.Outlined.GridView
        slug == "beneficiaries" -> Icons.Outlined.Groups
        slug == "reports" -> Icons.Outlined.Assessment
        slug == "profile" -> Icons.Outlined.Person
        slug.startsWith("gizi") || slug.contains("menu") -> Icons.Outlined.MedicalInformation
        slug.startsWith("lapangan") -> Icons.AutoMirrored.Outlined.Assignment
        slug.startsWith("gudang") -> Icons.Outlined.Inventory2
        slug == "persiapan" -> Icons.Outlined.SetMeal
        slug == "pengolahan" -> Icons.Outlined.Restaurant
        slug == "pemorsian" -> Icons.AutoMirrored.Outlined.Assignment
        slug == "distribusi" || slug == "pengambilan-ompreng" -> Icons.Outlined.LocalShipping
        slug == "pencucian" -> Icons.Outlined.WaterDrop
        slug == "kebersihan" -> Icons.Outlined.CleaningServices
        slug == "keamanan" -> Icons.Outlined.Security
        else -> Icons.AutoMirrored.Outlined.Assignment
    }
    val accent = when {
        slug == "beneficiaries" -> Color(0xFFFFA62B)
        slug == "reports" -> Color(0xFF7B61FF)
        slug == "profile" -> Color(0xFF64748B)
        slug.startsWith("gizi") || slug.contains("menu") -> Color(0xFF22B573)
        slug.startsWith("gudang") -> Color(0xFF7B61FF)
        slug in listOf("persiapan", "pengolahan", "pemorsian") -> Color(0xFF22B573)
        slug == "kebersihan" || slug == "pencucian" || slug.startsWith("lapangan") -> Color(0xFF15C5CE)
        slug == "distribusi" || slug == "pengambilan-ompreng" -> Color(0xFF1687F8)
        else -> MaterialTheme.colorScheme.primary
    }
    val dark = MaterialTheme.colorScheme.background.luminance() < .5f
    return ModuleVisual(
        icon = icon,
        color = if (dark) androidx.compose.ui.graphics.lerp(accent, Color.White, .35f) else accent,
        container = accent.copy(alpha = if (dark) .2f else .10f),
    )
}

@Composable
fun sppgTopAppBarColors(): TopAppBarColors = TopAppBarDefaults.topAppBarColors(
    containerColor = MaterialTheme.colorScheme.surface,
    scrolledContainerColor = MaterialTheme.colorScheme.surface,
    navigationIconContentColor = MaterialTheme.colorScheme.onSurface,
    titleContentColor = MaterialTheme.colorScheme.onSurface,
    actionIconContentColor = MaterialTheme.colorScheme.primary,
)

@Composable
fun SppgStatusPill(
    label: String,
    modifier: Modifier = Modifier,
    colorOverride: Color? = null,
) {
    val normalized = label.lowercase()
    val darkTheme = MaterialTheme.colorScheme.background.luminance() < 0.5f
    val success = if (darkTheme) Color(0xFF72D9A3) else Color(0xFF176B43)
    val info = if (darkTheme) Color(0xFF8BCBFF) else Color(0xFF1F6488)
    val warning = if (darkTheme) Color(0xFFFFC46B) else Color(0xFF925600)
    val color = colorOverride ?: when {
        normalized.contains("tidak sesuai") || normalized.contains("tidak aman") || normalized.contains("darurat") -> MaterialTheme.colorScheme.error
        normalized.contains("belum") || normalized.contains("menunggu") || normalized.contains("pending") -> warning
        normalized.contains("gagal") || normalized.contains("tolak") || normalized.contains("kritis") ||
            normalized.contains("batal") -> MaterialTheme.colorScheme.error
        normalized == "aman" || normalized.contains("selesai") || normalized.contains("diterima") || normalized.contains("terkirim") ||
            normalized.contains("dikirim") || normalized.contains("sesuai") || normalized.contains("verifikasi") -> success
        normalized.contains("aktif") || normalized.contains("proses") || normalized.contains("jalan") ||
            normalized.contains("muat") || normalized.contains("dikerjakan") || normalized.contains("jadwal") -> info
        else -> warning
    }
    Row(
        modifier = modifier
            .widthIn(max = 180.dp)
            .background(color.copy(alpha = 0.12f), RoundedCornerShape(50))
            .padding(horizontal = 10.dp, vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(7.dp).background(color, CircleShape))
        Spacer(Modifier.width(7.dp))
        Text(
            label,
            color = color,
            style = MaterialTheme.typography.labelMedium,
            fontWeight = FontWeight.Bold,
            maxLines = 3,
            softWrap = true,
            overflow = TextOverflow.Ellipsis,
        )
    }
}

@Composable
fun ModuleIcon(slug: String, modifier: Modifier = Modifier) {
    val visual = moduleVisual(slug)
    Box(
        modifier = modifier
            .size(48.dp)
            .background(visual.container, RoundedCornerShape(15.dp)),
        contentAlignment = Alignment.Center,
    ) {
        Icon(visual.icon, contentDescription = null, tint = visual.color)
    }
}

@Composable
fun SppgSectionCard(
    modifier: Modifier = Modifier,
    content: @Composable () -> Unit,
) {
    Card(
        modifier = modifier.fillMaxWidth(),
        shape = MaterialTheme.shapes.medium,
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
        content = { Box(Modifier.padding(SppgPagePadding)) { content() } },
    )
}

@Composable
fun SppgPrimaryButton(
    label: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
) {
    Button(
        onClick = onClick,
        enabled = enabled,
        modifier = modifier.heightIn(min = 50.dp),
        shape = RoundedCornerShape(12.dp),
        colors = ButtonDefaults.buttonColors(
            containerColor = MaterialTheme.colorScheme.primary,
            contentColor = MaterialTheme.colorScheme.onPrimary,
            disabledContainerColor = MaterialTheme.colorScheme.surfaceVariant,
            disabledContentColor = MaterialTheme.colorScheme.onSurfaceVariant,
        ),
    ) {
        Text(label, style = MaterialTheme.typography.labelLarge)
    }
}

@Composable
fun SppgLoadingState(
    message: String = "Memuat data…",
    modifier: Modifier = Modifier,
) {
    Column(
        modifier = modifier.fillMaxWidth().padding(vertical = 32.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        CircularProgressIndicator(color = MaterialTheme.colorScheme.primary, strokeWidth = 3.dp)
        Spacer(Modifier.width(8.dp))
        Text(message, modifier = Modifier.padding(top = 12.dp), color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

@Composable
fun SppgEmptyState(
    title: String,
    message: String,
    modifier: Modifier = Modifier,
) {
    SppgSectionCard(modifier) {
        Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.fillMaxWidth()) {
            Icon(
                Icons.AutoMirrored.Outlined.Assignment,
                contentDescription = null,
                tint = MaterialTheme.colorScheme.primary,
                modifier = Modifier.size(36.dp),
            )
            Text(title, style = MaterialTheme.typography.titleMedium, modifier = Modifier.padding(top = 10.dp))
            Text(
                message,
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.padding(top = 4.dp),
            )
        }
    }
}

@Composable
fun SppgErrorState(
    message: String,
    onRetry: (() -> Unit)? = null,
    modifier: Modifier = Modifier,
) {
    Card(
        modifier = modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.errorContainer),
        shape = MaterialTheme.shapes.medium,
    ) {
        Column(Modifier.padding(SppgPagePadding)) {
            Text("Data belum dapat ditampilkan", style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.onErrorContainer)
            Text(userFriendlyUiMessage(message), modifier = Modifier.padding(top = 4.dp), color = MaterialTheme.colorScheme.onErrorContainer)
            if (onRetry != null) {
                OutlinedButton(onClick = onRetry, modifier = Modifier.padding(top = 12.dp).heightIn(min = 48.dp)) {
                    Icon(Icons.Outlined.Refresh, contentDescription = null)
                    Spacer(Modifier.width(8.dp))
                    Text("Coba lagi")
                }
            }
        }
    }
}

fun userFriendlyUiMessage(message: String?): String {
    if (message.isNullOrBlank()) return "Periksa koneksi lalu coba lagi."
    val normalized = message.lowercase()
    return if (
        normalized.contains("sqlstate") || normalized.contains("exception") ||
        normalized.contains("undefined variable") || normalized.contains("undefined array") ||
        normalized.contains("http request returned") || normalized.contains("status code") ||
        normalized.contains("stack trace")
    ) {
        "Terjadi kendala saat memproses data. Silakan coba lagi atau hubungi administrator."
    } else message
}
