package id.sppg.mobile.ui

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.graphics.Rect
import android.net.Uri
import android.util.Base64
import java.io.ByteArrayOutputStream
import java.time.LocalDateTime
import java.time.format.DateTimeFormatter
import java.util.Locale

data class PhotoWatermarkProfile(
    val name: String,
    val division: String,
)

fun watermarkedPhotoDataUri(context: Context, uri: Uri, profile: PhotoWatermarkProfile): Pair<Bitmap, String> {
    val bitmap = decodeSampledPhoto(context, uri)
    return watermarkedPhotoDataUri(bitmap, profile)
}

fun watermarkedPhotoDataUri(bitmap: Bitmap, profile: PhotoWatermarkProfile): Pair<Bitmap, String> {
    require(bitmap.width > 0 && bitmap.height > 0) { "Ukuran foto tidak valid." }
    val resized = resizeForUpload(bitmap)
    val watermarked = applyPhotoWatermark(resized, profile)
    if (resized !== bitmap && resized !== watermarked) resized.recycle()
    return watermarked to encodeJpegDataUri(watermarked)
}

private fun decodeSampledPhoto(context: Context, uri: Uri, maxDimension: Int = 1600): Bitmap {
    val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
    context.contentResolver.openInputStream(uri)?.use { input ->
        BitmapFactory.decodeStream(input, null, bounds)
    } ?: error("Foto tidak dapat dibaca.")

    var sampleSize = 1
    while (bounds.outWidth / sampleSize > maxDimension * 2 || bounds.outHeight / sampleSize > maxDimension * 2) {
        sampleSize *= 2
    }

    val options = BitmapFactory.Options().apply { inSampleSize = sampleSize }
    return context.contentResolver.openInputStream(uri)?.use { input ->
        BitmapFactory.decodeStream(input, null, options)
            ?: error("Format foto tidak dapat dibaca. Gunakan JPG, PNG, atau WEBP.")
    } ?: error("Foto tidak dapat dibuka dari penyimpanan perangkat.")
}

private fun resizeForUpload(bitmap: Bitmap, maxDimension: Int = 1600): Bitmap {
    val ratio = minOf(maxDimension.toFloat() / bitmap.width, maxDimension.toFloat() / bitmap.height, 1f)
    if (ratio >= 1f) return bitmap

    return Bitmap.createScaledBitmap(
        bitmap,
        (bitmap.width * ratio).toInt().coerceAtLeast(1),
        (bitmap.height * ratio).toInt().coerceAtLeast(1),
        true,
    )
}

private fun applyPhotoWatermark(bitmap: Bitmap, profile: PhotoWatermarkProfile): Bitmap {
    val output = bitmap.copy(Bitmap.Config.ARGB_8888, true)
    val canvas = Canvas(output)
    val width = output.width
    val height = output.height
    val densityScale = (width / 1080f).coerceIn(0.8f, 1.6f)
    val padding = (20f * densityScale)
    val textSize = (28f * densityScale)
    val lineGap = (8f * densityScale)

    val timestamp = LocalDateTime.now().format(
        DateTimeFormatter.ofPattern("dd MMM yyyy, HH:mm", Locale.forLanguageTag("id-ID")),
    )
    val lines = listOf(
        profile.name.ifBlank { "Petugas SPPG" },
        profile.division.ifBlank { "Divisi belum tercatat" },
        timestamp,
    )

    val textPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.WHITE
        this.textSize = textSize
        typeface = android.graphics.Typeface.DEFAULT_BOLD
        setShadowLayer(4f * densityScale, 1f, 1f, Color.BLACK)
    }
    val textBounds = Rect()
    val lineHeight = textSize + lineGap
    val overlayHeight = (padding * 2 + lineHeight * lines.size).toInt()

    val overlayPaint = Paint().apply { color = Color.argb(150, 0, 0, 0) }
    canvas.drawRect(0f, (height - overlayHeight).toFloat(), width.toFloat(), height.toFloat(), overlayPaint)

    var y = height - overlayHeight + padding + textSize
    lines.forEach { line ->
        val trimmed = fitText(line, textPaint, width - padding * 2, textBounds)
        canvas.drawText(trimmed, padding, y, textPaint)
        y += lineHeight
    }

    return output
}

private fun fitText(value: String, paint: Paint, maxWidth: Float, bounds: Rect): String {
    if (paint.measureText(value) <= maxWidth) return value
    var candidate = value
    while (candidate.length > 1 && paint.measureText("$candidate...") > maxWidth) {
        candidate = candidate.dropLast(1)
    }
    paint.getTextBounds(candidate, 0, candidate.length, bounds)
    return "$candidate..."
}

private fun encodeJpegDataUri(bitmap: Bitmap): String {
    var quality = 86
    var bytes: ByteArray
    do {
        val output = ByteArrayOutputStream()
        bitmap.compress(Bitmap.CompressFormat.JPEG, quality, output)
        bytes = output.toByteArray()
        quality -= 8
    } while (bytes.size > 5 * 1024 * 1024 && quality >= 54)

    require(bytes.size <= 5 * 1024 * 1024) { "Ukuran foto maksimal 5 MB." }

    return "data:image/jpeg;base64," + Base64.encodeToString(bytes, Base64.NO_WRAP)
}
