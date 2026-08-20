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

val Navy = Color(0xFF071D3A)
val NavyMedium = Color(0xFF0B376B)
val NavySoft = Color(0xFFEAF1FA)
val BlueAction = Color(0xFF0B5FA5)
val Forest = NavyMedium
val ForestDark = Navy
val Leaf = BlueAction
val Mint = NavySoft
val Amber = Color(0xFFF4A340)
val AmberSoft = Color(0xFFFFECD2)
val Ink = Color(0xFF10213B)
val Slate = Color(0xFF657690)
val Canvas = Color(0xFFF4F7FB)
val Night = Color(0xFF061326)

private val LightColors = lightColorScheme(
    primary = NavyMedium,
    onPrimary = Color.White,
    primaryContainer = Mint,
    onPrimaryContainer = Navy,
    secondary = Amber,
    onSecondary = Color(0xFF402600),
    secondaryContainer = AmberSoft,
    onSecondaryContainer = Color(0xFF4C2B00),
    tertiary = BlueAction,
    background = Canvas,
    onBackground = Ink,
    surface = Color.White,
    onSurface = Ink,
    surfaceVariant = Color(0xFFEAF0F7),
    onSurfaceVariant = Slate,
    outline = Color(0xFFB7C4D5),
    outlineVariant = Color(0xFFDCE4EF),
    error = Color(0xFFBA1A1A),
    errorContainer = Color(0xFFFFDAD6),
)

private val DarkColors = darkColorScheme(
    primary = Color(0xFF91C4FF),
    onPrimary = Color(0xFF002F5F),
    primaryContainer = Color(0xFF123B68),
    onPrimaryContainer = Color(0xFFD4E6FF),
    secondary = Color(0xFFFFB95F),
    onSecondary = Color(0xFF442B00),
    secondaryContainer = Color(0xFF624000),
    onSecondaryContainer = Color(0xFFFFDDB0),
    tertiary = Color(0xFF8FCBFF),
    background = Night,
    onBackground = Color(0xFFE2EAF5),
    surface = Color(0xFF0D2038),
    onSurface = Color(0xFFE2EAF5),
    surfaceVariant = Color(0xFF172D48),
    onSurfaceVariant = Color(0xFFB8C7DA),
    outline = Color(0xFF8394AA),
    outlineVariant = Color(0xFF31465F),
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
    titleLarge = TextStyle(fontWeight = FontWeight.SemiBold, fontSize = 19.sp, lineHeight = 25.sp),
    titleMedium = TextStyle(fontWeight = FontWeight.Medium, fontSize = 16.sp, lineHeight = 22.sp),
    bodyLarge = TextStyle(fontSize = 16.sp, lineHeight = 24.sp),
    bodyMedium = TextStyle(fontSize = 14.sp, lineHeight = 21.sp),
    labelLarge = TextStyle(fontWeight = FontWeight.SemiBold, fontSize = 14.sp, lineHeight = 20.sp),
)

private val SppgShapes = Shapes(
    extraSmall = RoundedCornerShape(10.dp),
    small = RoundedCornerShape(14.dp),
    medium = RoundedCornerShape(18.dp),
    large = RoundedCornerShape(22.dp),
    extraLarge = RoundedCornerShape(28.dp),
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
