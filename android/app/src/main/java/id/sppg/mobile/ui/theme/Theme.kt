package id.sppg.mobile.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.Typography
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.foundation.shape.RoundedCornerShape

val Navy = Color(0xFF081D3A)
val NavyMedium = Color(0xFF0F63C9)
val NavySoft = Color(0xFFEAF4FF)
val BlueAction = Color(0xFF1687F8)
val Forest = NavyMedium
val ForestDark = Navy
val Leaf = BlueAction
val Mint = NavySoft
val Amber = Color(0xFFF5A623)
val AmberSoft = Color(0xFFFFF5E6)
val Ink = Color(0xFF10233D)
val Slate = Color(0xFF6B7A90)
val Canvas = Color(0xFFF7F9FC)
val Night = Color(0xFF061326)

private val LightColors = lightColorScheme(
    primary = BlueAction,
    onPrimary = Color.White,
    primaryContainer = Mint,
    onPrimaryContainer = Navy,
    secondary = Amber,
    onSecondary = Color(0xFF402600),
    secondaryContainer = AmberSoft,
    onSecondaryContainer = Color(0xFF4C2B00),
    tertiary = BlueAction,
    onTertiary = Color.White,
    tertiaryContainer = NavySoft,
    onTertiaryContainer = Navy,
    background = Canvas,
    onBackground = Ink,
    surface = Color.White,
    onSurface = Ink,
    surfaceVariant = Color(0xFFEAF0F7),
    onSurfaceVariant = Slate,
    outline = Color(0xFFB7C4D5),
    outlineVariant = Color(0xFFE6EBF2),
    error = Color(0xFFF05252),
    errorContainer = Color(0xFFFDECEC),
)

private val DarkColors = darkColorScheme(
    primary = Color(0xFF8BC3FF),
    onPrimary = Color(0xFF002F5F),
    primaryContainer = Color(0xFF123B68),
    onPrimaryContainer = Color(0xFFD4E6FF),
    secondary = Color(0xFFFFB95F),
    onSecondary = Color(0xFF442B00),
    secondaryContainer = Color(0xFF624000),
    onSecondaryContainer = Color(0xFFFFDDB0),
    tertiary = Color(0xFF8FCBFF),
    onTertiary = Color(0xFF003452),
    tertiaryContainer = Color(0xFF153E62),
    onTertiaryContainer = Color(0xFFD4EAFF),
    background = Color(0xFF07182B),
    onBackground = Color(0xFFE2EAF5),
    surface = Color(0xFF10243D),
    onSurface = Color(0xFFE2EAF5),
    surfaceVariant = Color(0xFF1A324F),
    onSurfaceVariant = Color(0xFFC0CDDD),
    outline = Color(0xFF8C9DB2),
    outlineVariant = Color(0xFF3B526C),
    error = Color(0xFFFFB4AB),
    errorContainer = Color(0xFF93000A),
)

private val SppgTypography = Typography(
    displaySmall = TextStyle(
        fontFamily = FontFamily.SansSerif,
        fontWeight = FontWeight.Bold,
        fontSize = 32.sp,
        lineHeight = 38.sp,
        letterSpacing = (-0.6).sp,
    ),
    headlineLarge = TextStyle(
        fontFamily = FontFamily.SansSerif,
        fontWeight = FontWeight.SemiBold,
        fontSize = 28.sp,
        lineHeight = 34.sp,
    ),
    headlineMedium = TextStyle(
        fontFamily = FontFamily.SansSerif,
        fontWeight = FontWeight.SemiBold,
        fontSize = 25.sp,
        lineHeight = 31.sp,
    ),
    headlineSmall = TextStyle(
        fontFamily = FontFamily.SansSerif,
        fontWeight = FontWeight.SemiBold,
        fontSize = 21.sp,
        lineHeight = 27.sp,
    ),
    titleLarge = TextStyle(fontWeight = FontWeight.SemiBold, fontSize = 20.sp, lineHeight = 26.sp),
    titleMedium = TextStyle(fontWeight = FontWeight.Medium, fontSize = 16.sp, lineHeight = 22.sp),
    bodyLarge = TextStyle(fontSize = 15.sp, lineHeight = 22.sp),
    bodyMedium = TextStyle(fontSize = 14.sp, lineHeight = 21.sp),
    bodySmall = TextStyle(fontSize = 13.sp, lineHeight = 19.sp),
    labelLarge = TextStyle(fontWeight = FontWeight.SemiBold, fontSize = 14.sp, lineHeight = 20.sp),
    labelMedium = TextStyle(fontWeight = FontWeight.Medium, fontSize = 13.sp, lineHeight = 18.sp),
    labelSmall = TextStyle(fontWeight = FontWeight.Medium, fontSize = 12.sp, lineHeight = 17.sp),
)

private val SppgShapes = Shapes(
    extraSmall = RoundedCornerShape(8.dp),
    small = RoundedCornerShape(12.dp),
    medium = RoundedCornerShape(16.dp),
    large = RoundedCornerShape(20.dp),
    extraLarge = RoundedCornerShape(24.dp),
)

@Composable
fun SppgTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    content: @Composable () -> Unit,
) {
    MaterialTheme(
        colorScheme = if (darkTheme) DarkColors else LightColors,
        typography = SppgTypography,
        shapes = SppgShapes,
        content = content,
    )
}
