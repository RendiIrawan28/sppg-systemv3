package id.sppg.mobile.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material.icons.outlined.Image
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import coil3.compose.SubcomposeAsyncImage
import id.sppg.mobile.BuildConfig
import java.net.URI

@Composable
fun InAppImageButton(
    url: String,
    title: String,
    label: String = "Lihat foto",
    modifier: Modifier = Modifier,
) {
    var open by remember(url) { mutableStateOf(false) }

    OutlinedButton(onClick = { open = true }, modifier = modifier) {
        Icon(Icons.Outlined.Image, contentDescription = null)
        Spacer(Modifier.width(6.dp))
        Text(label)
    }

    if (open) {
        Dialog(
            onDismissRequest = { open = false },
            properties = DialogProperties(usePlatformDefaultWidth = false),
        ) {
            Surface(
                modifier = Modifier.fillMaxWidth(0.94f).fillMaxHeight(0.90f),
                shape = RoundedCornerShape(24.dp),
                color = MaterialTheme.colorScheme.surface,
            ) {
                Column(
                    Modifier.padding(16.dp).verticalScroll(rememberScrollState()),
                    verticalArrangement = Arrangement.spacedBy(12.dp),
                ) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(
                            text = title,
                            modifier = Modifier.weight(1f),
                            style = MaterialTheme.typography.titleMedium,
                            fontWeight = FontWeight.Bold,
                        )
                        IconButton(onClick = { open = false }) {
                            Icon(Icons.Outlined.Close, contentDescription = "Tutup foto")
                        }
                    }

                    SubcomposeAsyncImage(
                        model = resolveAppMediaUrl(url),
                        contentDescription = title,
                        modifier = Modifier.fillMaxWidth().heightIn(min = 220.dp, max = 620.dp),
                        contentScale = ContentScale.Fit,
                        loading = {
                            Box(Modifier.fillMaxWidth().heightIn(min = 220.dp), contentAlignment = Alignment.Center) {
                                CircularProgressIndicator()
                            }
                        },
                        error = {
                            Box(Modifier.fillMaxWidth().heightIn(min = 220.dp), contentAlignment = Alignment.Center) {
                                Text(
                                    "Foto belum dapat dimuat. Periksa koneksi ke server SPPG.",
                                    modifier = Modifier.padding(24.dp),
                                    color = MaterialTheme.colorScheme.error,
                                )
                            }
                        },
                    )
                }
            }
        }
    }
}

internal fun resolveAppMediaUrl(
    rawUrl: String,
    apiBaseUrl: String = BuildConfig.API_BASE_URL,
): String = runCatching {
    val media = URI(rawUrl)
    val api = URI(apiBaseUrl)
    if (!media.isAbsolute) {
        return@runCatching URI(
            api.scheme,
            null,
            api.host,
            api.port,
            if (media.path.startsWith('/')) media.path else "/${media.path}",
            media.query,
            media.fragment,
        ).toString()
    }
    if (media.host !in setOf("127.0.0.1", "localhost", "::1")) {
        return@runCatching rawUrl
    }

    URI(
        api.scheme,
        media.userInfo,
        api.host,
        api.port,
        media.path,
        media.query,
        media.fragment,
    ).toString()
}.getOrDefault(rawUrl)

internal fun isImageUrl(url: String): Boolean {
    val path = runCatching { URI(url).path.lowercase() }.getOrDefault(url.lowercase())

    return listOf(".jpg", ".jpeg", ".png", ".webp", ".heic", ".heif").any(path::endsWith)
}
