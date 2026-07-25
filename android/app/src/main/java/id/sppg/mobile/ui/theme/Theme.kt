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

val Forest = Color(0xFF176B50)
val ForestDark = Color(0xFF0C4938)
val Leaf = Color(0xFF50A26F)
val Mint = Color(0xFFDDF3E7)
val Amber = Color(0xFFF4A340)
val AmberSoft = Color(0xFFFFECD2)
val Ink = Color(0xFF16231D)
val Slate = Color(0xFF5B6861)
val Canvas = Color(0xFFF6F8F3)
val Night = Color(0xFF101713)

private val LightColors = lightColorScheme(
    primary = Forest,
    onPrimary = Color.White,
    primaryContainer = Mint,
    onPrimaryContainer = ForestDark,
    secondary = Amber,
    onSecondary = Color(0xFF402600),
    secondaryContainer = AmberSoft,
    onSecondaryContainer = Color(0xFF4C2B00),
    tertiary = Color(0xFF39708A),
    background = Canvas,
    onBackground = Ink,
    surface = Color.White,
    onSurface = Ink,
    surfaceVariant = Color(0xFFE8EEE9),
    onSurfaceVariant = Slate,
    outline = Color(0xFFB7C3BB),
    error = Color(0xFFBA1A1A),
    errorContainer = Color(0xFFFFDAD6),
)

private val DarkColors = darkColorScheme(
    primary = Color(0xFF83D5A5),
    onPrimary = Color(0xFF003824),
    primaryContainer = Color(0xFF07513A),
    onPrimaryContainer = Color(0xFFA0F2C0),
    secondary = Color(0xFFFFB95F),
    onSecondary = Color(0xFF442B00),
    secondaryContainer = Color(0xFF624000),
    onSecondaryContainer = Color(0xFFFFDDB0),
    tertiary = Color(0xFF8FCFF0),
    background = Night,
    onBackground = Color(0xFFE1EAE3),
    surface = Color(0xFF18211C),
    onSurface = Color(0xFFE1EAE3),
    surfaceVariant = Color(0xFF28342D),
    onSurfaceVariant = Color(0xFFBBC8BF),
    outline = Color(0xFF85948A),
    error = Color(0xFFFFB4AB),
    errorContainer = Color(0xFF93000A),
)

private val SppgTypography = Typography(
    displaySmall = TextStyle(
        fontFamily = FontFamily.SansSerif,
        fontWeight = FontWeight.ExtraBold,
        fontSize = 34.sp,
        lineHeight = 40.sp,
        letterSpacing = (-0.6).sp,
    ),
    headlineLarge = TextStyle(
        fontFamily = FontFamily.SansSerif,
        fontWeight = FontWeight.Bold,
        fontSize = 30.sp,
        lineHeight = 36.sp,
    ),
    headlineMedium = TextStyle(
        fontFamily = FontFamily.SansSerif,
        fontWeight = FontWeight.Bold,
        fontSize = 25.sp,
        lineHeight = 31.sp,
    ),
    headlineSmall = TextStyle(
        fontFamily = FontFamily.SansSerif,
        fontWeight = FontWeight.Bold,
        fontSize = 21.sp,
        lineHeight = 27.sp,
    ),
    titleLarge = TextStyle(fontWeight = FontWeight.Bold, fontSize = 19.sp, lineHeight = 25.sp),
    titleMedium = TextStyle(fontWeight = FontWeight.SemiBold, fontSize = 16.sp, lineHeight = 22.sp),
    bodyLarge = TextStyle(fontSize = 16.sp, lineHeight = 24.sp),
    bodyMedium = TextStyle(fontSize = 14.sp, lineHeight = 21.sp),
    labelLarge = TextStyle(fontWeight = FontWeight.SemiBold, fontSize = 14.sp, lineHeight = 20.sp),
)

private val SppgShapes = Shapes(
    extraSmall = RoundedCornerShape(8.dp),
    small = RoundedCornerShape(12.dp),
    medium = RoundedCornerShape(18.dp),
    large = RoundedCornerShape(24.dp),
    extraLarge = RoundedCornerShape(32.dp),
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
