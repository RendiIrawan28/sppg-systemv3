package id.sppg.mobile.data.remote

import com.google.gson.Gson
import id.sppg.mobile.data.session.SessionStore
import java.io.IOException
import java.net.ConnectException
import java.net.SocketTimeoutException
import java.net.UnknownHostException
import javax.net.ssl.SSLException

class SessionExpiredException(
    message: String = "Sesi Anda telah berakhir. Silakan masuk kembali.",
) : IOException(message)

class ApiErrorHandler(
    private val sessionStore: SessionStore,
    private val gson: Gson = Gson(),
) {
    suspend fun exception(
        statusCode: Int,
        rawBody: String?,
        fallbackMessage: String = "Permintaan tidak dapat diproses oleh server.",
    ): IOException {
        val apiError = rawBody?.let {
            runCatching { gson.fromJson(it, ApiError::class.java) }.getOrNull()
        }
        val validationMessage = apiError?.errors
            ?.flatMap { (key, messages) ->
                messages.filter { it.isNotBlank() }.map { message ->
                    val label = fieldLabel(key)
                    val readable = readableMessage(message)
                    if (readable.startsWith(label.substringAfter(" — "), ignoreCase = true)) {
                        "${label}: ${readable.substring(label.substringAfter(" — ").length).trimStart()}".trimEnd()
                    } else {
                        "$label: $readable"
                    }
                }
            }
            ?.distinct()
            ?.take(6)
            ?.takeIf { it.isNotEmpty() }
            ?.joinToString("\n")
        val message = when {
            statusCode >= 500 -> "Data belum dapat diproses karena server sedang mengalami kendala. Coba lagi; jika tetap gagal, hubungi administrator."
            validationMessage != null -> validationMessage
            statusCode == 422 -> "Ada isian yang belum benar. Periksa kembali kolom wajib dan jumlah yang dimasukkan."
            !apiError?.message.isNullOrBlank() -> readableMessage(apiError?.message.orEmpty())
            else -> when (statusCode) {
                403 -> "Anda tidak memiliki izin untuk menjalankan tindakan ini."
                404 -> "Data yang diminta tidak ditemukan."
                429 -> "Permintaan terlalu sering. Silakan tunggu lalu coba kembali."
                else -> fallbackMessage
            }
        }

        if (statusCode == 401) {
            val expired = SessionExpiredException("Sesi Anda telah berakhir. Silakan masuk kembali.")
            sessionStore.clear(expired.message)
            return expired
        }

        return IOException(message)
    }

    private fun fieldLabel(key: String): String {
        val parts = key.split('.')
        val field = parts.lastOrNull().orEmpty()
        val name = mapOf(
            "supplier_id" to "Supplier", "ingredient_id" to "Bahan", "non_food_item_id" to "Barang non-pangan",
            "inventory_lot_id" to "Barang dan lot", "photo" to "Foto", "photo_path" to "Foto",
            "quantity" to "Jumlah", "actual_quantity" to "Jumlah fisik aktual",
            "received_quantity" to "Jumlah diterima", "accepted_quantity" to "Jumlah baik",
            "rejected_quantity" to "Jumlah ditolak", "measurement_unit_id" to "Satuan",
            "route_name" to "Nama rute", "reason" to "Alasan", "notes" to "Catatan",
            "completion" to "Penyelesaian pekerjaan", "submission" to "Pengajuan laporan",
            "fields" to "Data formulir", "items" to "Daftar barang",
            "manual_rows_payload" to "Daftar barang", "rows_payload" to "Daftar barang",
            "distribution_date" to "Tanggal distribusi", "preparation_date" to "Tanggal persiapan",
            "production_date" to "Tanggal pengolahan", "portioning_date" to "Tanggal pemorsian",
        )[field] ?: field.replace('_', ' ').replace(Regex("([a-z])([A-Z])"), "$1 $2")
            .replaceFirstChar { it.uppercase() }
        val row = parts.firstOrNull { it.toIntOrNull() != null }?.toIntOrNull()
        return if (row == null) name else "Baris ${row + 1} — $name"
    }

    private fun readableMessage(message: String): String {
        val lower = message.lowercase()
        if (lower.startsWith("validation.")) {
            return when {
                lower.endsWith(".required") -> "wajib diisi."
                lower.endsWith(".numeric") -> "harus berupa angka."
                else -> "belum benar. Periksa kembali isian ini."
            }
        }
        if (listOf("sqlstate", "exception", "undefined variable", "undefined array", "stack trace").any { lower.contains(it) }) {
            return "belum dapat disimpan. Coba lagi atau hubungi administrator."
        }
        if (lower.startsWith("the ") || lower.startsWith("this field")) {
            return "belum benar. Periksa kembali isian ini."
        }
        return message
    }

    fun normalize(throwable: Throwable): Throwable = when (throwable) {
        is SessionExpiredException -> throwable
        is UnknownHostException -> IOException("Server SPPG tidak dapat ditemukan. Periksa alamat API dan koneksi internet.", throwable)
        is ConnectException -> IOException("Tidak dapat terhubung ke server SPPG.", throwable)
        is SocketTimeoutException -> IOException("Koneksi ke server terlalu lama. Silakan coba kembali.", throwable)
        is SSLException -> IOException("Koneksi aman ke server gagal diverifikasi.", throwable)
        else -> throwable
    }
}

suspend inline fun <T> safeApiCall(
    errorHandler: ApiErrorHandler,
    crossinline block: suspend () -> T,
): Result<T> = try {
    Result.success(block())
} catch (throwable: Throwable) {
    Result.failure(errorHandler.normalize(throwable))
}
