package id.sppg.mobile.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.Assignment
import androidx.compose.material.icons.outlined.CleaningServices
import androidx.compose.material.icons.outlined.Inventory2
import androidx.compose.material.icons.outlined.LocalShipping
import androidx.compose.material.icons.outlined.Map
import androidx.compose.material.icons.outlined.Notifications
import androidx.compose.material.icons.outlined.Restaurant
import androidx.compose.material.icons.outlined.Security
import androidx.compose.material.icons.outlined.SetMeal
import androidx.compose.material.icons.outlined.WaterDrop
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import id.sppg.mobile.ui.theme.Amber
import id.sppg.mobile.ui.theme.Forest
import id.sppg.mobile.ui.theme.Leaf

data class ModuleVisual(
    val icon: ImageVector,
    val color: Color,
    val container: Color,
)

@Composable
fun moduleVisual(slug: String): ModuleVisual = when {
    slug == "field-plans" -> ModuleVisual(Icons.Outlined.Map, Forest, MaterialTheme.colorScheme.primaryContainer)
    slug == "tasks" -> ModuleVisual(Icons.Outlined.Notifications, Color(0xFF2F6F8F), Color(0xFFDCEFFA))
    slug.startsWith("lapangan") -> ModuleVisual(Icons.AutoMirrored.Outlined.Assignment, Color(0xFF266E91), Color(0xFFDCEFFA))
    slug.startsWith("gudang") -> ModuleVisual(Icons.Outlined.Inventory2, Color(0xFF9B5C12), Color(0xFFFFE9C9))
    slug == "persiapan" -> ModuleVisual(Icons.Outlined.SetMeal, Color(0xFF6A65A8), Color(0xFFEAE7FF))
    slug == "pengolahan" -> ModuleVisual(Icons.Outlined.Restaurant, Color(0xFFC05A35), Color(0xFFFFE3D9))
    slug == "pemorsian" -> ModuleVisual(Icons.AutoMirrored.Outlined.Assignment, Color(0xFF9A4773), Color(0xFFFFE0EF))
    slug == "distribusi" -> ModuleVisual(Icons.Outlined.LocalShipping, Color(0xFF3272A8), Color(0xFFDDEEFF))
    slug == "pencucian" -> ModuleVisual(Icons.Outlined.WaterDrop, Color(0xFF167F89), Color(0xFFD8F3F3))
    slug == "kebersihan" -> ModuleVisual(Icons.Outlined.CleaningServices, Color(0xFF4B7C45), Color(0xFFE0F0DC))
    slug == "keamanan" -> ModuleVisual(Icons.Outlined.Security, Color(0xFF455A64), Color(0xFFE4EBEF))
    else -> ModuleVisual(Icons.AutoMirrored.Outlined.Assignment, Forest, MaterialTheme.colorScheme.primaryContainer)
}

@Composable
fun SppgStatusPill(
    label: String,
    modifier: Modifier = Modifier,
) {
    val normalized = label.lowercase()
    val color = when {
        normalized.contains("gagal") || normalized.contains("tolak") || normalized.contains("kritis") -> MaterialTheme.colorScheme.error
        normalized.contains("selesai") || normalized.contains("aktif") || normalized.contains("verifikasi") || normalized.contains("siap") -> Forest
        normalized.contains("proses") || normalized.contains("jalan") || normalized.contains("muat") -> Color(0xFF266E91)
        else -> Amber
    }
    Row(
        modifier = modifier
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
