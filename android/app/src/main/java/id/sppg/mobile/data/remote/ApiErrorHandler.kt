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
            ?.values
            ?.flatten()
            ?.filter { it.isNotBlank() }
            ?.distinct()
            ?.joinToString(" ")
        val message = validationMessage ?: apiError?.message ?: when (statusCode) {
            403 -> "Anda tidak memiliki izin untuk menjalankan tindakan ini."
            404 -> "Data yang diminta tidak ditemukan."
            422 -> "Data yang dikirim belum lengkap atau tidak valid."
            429 -> "Permintaan terlalu sering. Silakan tunggu lalu coba kembali."
            else -> fallbackMessage
        }

        if (statusCode == 401) {
            val expired = SessionExpiredException(apiError?.message ?: "Sesi Anda telah berakhir. Silakan masuk kembali.")
            sessionStore.clear(expired.message)
            return expired
        }

        return IOException(message)
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
