package id.sppg.mobile.ui

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.ImageDecoder
import android.graphics.Matrix
import android.graphics.Paint
import android.graphics.Rect
import android.media.ExifInterface
import android.net.Uri
import android.os.Build
import android.util.Base64
import androidx.annotation.RequiresApi
import java.io.ByteArrayOutputStream
import java.io.File
import java.io.FileInputStream
import java.io.FileOutputStream
import java.io.InputStream
import java.time.LocalDateTime
import java.time.format.DateTimeFormatter
import java.util.Locale

private const val MAX_SOURCE_PHOTO_BYTES = 25L * 1024L * 1024L
private const val MAX_UPLOAD_PHOTO_BYTES = 5 * 1024 * 1024

data class PhotoWatermarkProfile(
    val name: String,
    val division: String,
)

fun watermarkedPhotoDataUri(
    context: Context,
    uri: Uri,
    profile: PhotoWatermarkProfile,
): Pair<Bitmap, String> {
    val bitmap = decodeSampledPhoto(context, uri)
    return watermarkedPhotoDataUri(bitmap, profile)
}

fun watermarkedPhotoDataUri(
    file: File,
    profile: PhotoWatermarkProfile,
): Pair<Bitmap, String> {
    val bitmap = decodeSampledPhoto(file)
    return watermarkedPhotoDataUri(bitmap, profile)
}

fun watermarkedPhotoDataUri(bitmap: Bitmap, profile: PhotoWatermarkProfile): Pair<Bitmap, String> {
    require(bitmap.width > 0 && bitmap.height > 0) { "Ukuran foto tidak valid." }

    val resized = resizeForUpload(bitmap)
    val watermarked = applyPhotoWatermark(resized, profile)

    if (resized !== bitmap && resized !== watermarked && !resized.isRecycled) {
        resized.recycle()
    }

    return watermarked to encodeJpegDataUri(watermarked)
}

private fun decodeSampledPhoto(
    context: Context,
    uri: Uri,
    maxDimension: Int = 1600,
): Bitmap {
    require(uri != Uri.EMPTY) { "Alamat foto tidak valid." }

    var imageDecoderFailure: Throwable? = null

    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
        try {
            return decodeWithImageDecoder(context, uri, maxDimension)
        } catch (error: Throwable) {
            imageDecoderFailure = error
        }
    }

    val cachedFile = try {
        copyUriToCacheFile(context, uri)
    } catch (error: Throwable) {
        imageDecoderFailure?.let(error::addSuppressed)
        throw photoReadException(error)
    }

    return try {
        decodeSampledPhoto(cachedFile, maxDimension)
    } catch (error: Throwable) {
        imageDecoderFailure?.let(error::addSuppressed)
        throw photoReadException(error)
    } finally {
        cachedFile.delete()
    }
}

private fun decodeSampledPhoto(
    file: File,
    maxDimension: Int = 1600,
): Bitmap {
    require(file.exists()) { "File foto tidak ditemukan." }
    require(file.isFile) { "Sumber foto bukan file yang valid." }
    require(file.length() > 0L) { "File foto kosong." }
    require(file.length() <= MAX_SOURCE_PHOTO_BYTES) {
        "Ukuran file foto terlalu besar. Maksimal 25 MB sebelum diproses."
    }

    val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
    BitmapFactory.decodeFile(file.absolutePath, bounds)

    require(bounds.outWidth > 0 && bounds.outHeight > 0) {
        "Format foto tidak dikenali. Gunakan JPG, PNG, WEBP, HEIC, atau HEIF."
    }

    val options = BitmapFactory.Options().apply {
        inSampleSize = calculateSampleSize(bounds.outWidth, bounds.outHeight, maxDimension)
        inPreferredConfig = Bitmap.Config.ARGB_8888
    }

    val decoded = try {
        BitmapFactory.decodeFile(file.absolutePath, options)
    } catch (error: OutOfMemoryError) {
        throw IllegalArgumentException("Memori perangkat tidak cukup untuk memproses foto ini.", error)
    } ?: error("Isi file foto tidak dapat diterjemahkan.")

    return applyExifOrientation(decoded, file)
}

@RequiresApi(Build.VERSION_CODES.P)
private fun decodeWithImageDecoder(
    context: Context,
    uri: Uri,
    maxDimension: Int,
): Bitmap {
    val source = ImageDecoder.createSource(context.contentResolver, uri)

    return ImageDecoder.decodeBitmap(source) { decoder, info, _ ->
        val width = info.size.width
        val height = info.size.height

        require(width > 0 && height > 0) { "Ukuran foto tidak valid." }

        decoder.allocator = ImageDecoder.ALLOCATOR_SOFTWARE
        decoder.isMutableRequired = false
        decoder.setTargetSampleSize(calculateSampleSize(width, height, maxDimension))
    }
}

private fun calculateSampleSize(width: Int, height: Int, maxDimension: Int): Int {
    var sampleSize = 1
    while (
        width / sampleSize > maxDimension * 2 ||
        height / sampleSize > maxDimension * 2
    ) {
        sampleSize *= 2
    }
    return sampleSize.coerceAtLeast(1)
}

private fun copyUriToCacheFile(context: Context, uri: Uri): File {
    val suffix = when (context.contentResolver.getType(uri)?.lowercase(Locale.ROOT)) {
        "image/png" -> ".png"
        "image/webp" -> ".webp"
        "image/heic", "image/heif" -> ".heic"
        else -> ".jpg"
    }

    val directory = File(context.cacheDir, "photo_import").apply {
        check(exists() || mkdirs()) { "Folder sementara foto tidak dapat dibuat." }
    }
    val target = File.createTempFile("photo_", suffix, directory)

    try {
        if (uri.scheme.equals("file", ignoreCase = true)) {
            val path = requireNotNull(uri.path) { "Lokasi file foto tidak tersedia." }
            FileInputStream(File(path)).use { input ->
                copyPhotoStream(input, target)
            }
            return target
        }

        var firstFailure: Throwable? = null

        try {
            val input = context.contentResolver.openInputStream(uri)
                ?: error("Penyedia foto tidak memberikan aliran data.")
            input.use { copyPhotoStream(it, target) }
            return target
        } catch (error: Throwable) {
            firstFailure = error
        }

        var descriptorFailure: Throwable? = null

        try {
            val descriptor = context.contentResolver.openFileDescriptor(uri, "r")
                ?: error("Penyedia foto tidak memberikan file descriptor.")
            descriptor.use {
                FileInputStream(it.fileDescriptor).use { input ->
                    copyPhotoStream(input, target)
                }
            }
            return target
        } catch (error: Throwable) {
            descriptorFailure = error
        }

        try {
            val asset = context.contentResolver.openTypedAssetFileDescriptor(uri, "image/*", null)
                ?: error("Penyedia foto tidak memberikan data gambar.")
            asset.use {
                it.createInputStream().use { input ->
                    copyPhotoStream(input, target)
                }
            }
            return target
        } catch (error: Throwable) {
            firstFailure?.let(error::addSuppressed)
            descriptorFailure?.let(error::addSuppressed)
            throw error
        }
    } catch (error: Throwable) {
        target.delete()
        throw error
    }
}

private fun copyPhotoStream(input: InputStream, target: File) {
    var copiedBytes = 0L
    val buffer = ByteArray(DEFAULT_BUFFER_SIZE)

    FileOutputStream(target, false).use { output ->
        while (true) {
            val read = input.read(buffer)
            if (read < 0) break
            if (read == 0) continue

            copiedBytes += read
            require(copiedBytes <= MAX_SOURCE_PHOTO_BYTES) {
                "Ukuran file foto terlalu besar. Maksimal 25 MB sebelum diproses."
            }
            output.write(buffer, 0, read)
        }
        output.flush()
    }

    require(copiedBytes > 0L) { "File foto kosong atau belum selesai diunduh." }
}

private fun photoReadException(error: Throwable): IllegalArgumentException {
    val detail = generateSequence(error) { it.cause }
        .mapNotNull { it.message?.trim()?.takeIf { it.isNotBlank() } }
        .firstOrNull()

    val message = buildString {
        append("Foto tidak dapat dibaca dari penyimpanan perangkat")
        if (!detail.isNullOrBlank()) append(": $detail")
        append(". Coba simpan foto ke perangkat lalu pilih kembali.")
    }

    return IllegalArgumentException(message, error)
}

private fun applyExifOrientation(bitmap: Bitmap, file: File): Bitmap {
    val orientation = runCatching {
        ExifInterface(file.absolutePath).getAttributeInt(
            ExifInterface.TAG_ORIENTATION,
            ExifInterface.ORIENTATION_NORMAL,
        )
    }.getOrDefault(ExifInterface.ORIENTATION_NORMAL)

    val matrix = Matrix()
    when (orientation) {
        ExifInterface.ORIENTATION_FLIP_HORIZONTAL -> matrix.setScale(-1f, 1f)
        ExifInterface.ORIENTATION_ROTATE_180 -> matrix.setRotate(180f)
        ExifInterface.ORIENTATION_FLIP_VERTICAL -> matrix.setScale(1f, -1f)
        ExifInterface.ORIENTATION_TRANSPOSE -> {
            matrix.setRotate(90f)
            matrix.postScale(-1f, 1f)
        }
        ExifInterface.ORIENTATION_ROTATE_90 -> matrix.setRotate(90f)
        ExifInterface.ORIENTATION_TRANSVERSE -> {
            matrix.setRotate(-90f)
            matrix.postScale(-1f, 1f)
        }
        ExifInterface.ORIENTATION_ROTATE_270 -> matrix.setRotate(-90f)
        else -> return bitmap
    }

    return try {
        Bitmap.createBitmap(bitmap, 0, 0, bitmap.width, bitmap.height, matrix, true).also {
            if (it !== bitmap && !bitmap.isRecycled) bitmap.recycle()
        }
    } catch (_: Throwable) {
        bitmap
    }
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
    val padding = 20f * densityScale
    val textSize = 28f * densityScale
    val lineGap = 8f * densityScale

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
    } while (bytes.size > MAX_UPLOAD_PHOTO_BYTES && quality >= 54)

    require(bytes.size <= MAX_UPLOAD_PHOTO_BYTES) { "Ukuran foto maksimal 5 MB." }

    return "data:image/jpeg;base64," + Base64.encodeToString(bytes, Base64.NO_WRAP)
}
