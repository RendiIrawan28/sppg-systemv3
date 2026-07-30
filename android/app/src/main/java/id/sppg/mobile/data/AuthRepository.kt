package id.sppg.mobile.data

import android.os.Build
import id.sppg.mobile.data.remote.ApiErrorHandler
import id.sppg.mobile.data.remote.LoginRequest
import id.sppg.mobile.data.remote.MobileApi
import id.sppg.mobile.data.remote.SessionExpiredException
import id.sppg.mobile.data.session.SessionEvent
import id.sppg.mobile.data.session.SessionStore
import id.sppg.mobile.data.session.UserSession
import id.sppg.mobile.data.remote.safeApiCall
import kotlinx.coroutines.flow.Flow
import java.io.IOException
import java.time.OffsetDateTime

/** Hasil pemeriksaan sesi saat aplikasi dibuka. */
data class SessionBootstrap(
    val session: UserSession?,
    val warningMessage: String? = null,
)

class AuthRepository(
    private val api: MobileApi,
    private val sessionStore: SessionStore,
    private val errorHandler: ApiErrorHandler,
) {
    val session: Flow<UserSession?> = sessionStore.session
    val sessionEvents: Flow<SessionEvent> = sessionStore.events

    suspend fun bootstrap(): SessionBootstrap {
        val local = sessionStore.current() ?: return SessionBootstrap(session = null)
        val expiredLocally = local.tokenExpiresAt
            ?.let { value -> runCatching { OffsetDateTime.parse(value).isBefore(OffsetDateTime.now()) }.getOrDefault(false) }
            ?: false
        if (expiredLocally) {
            sessionStore.clear("Sesi Anda telah kedaluwarsa. Silakan masuk kembali.")
            return SessionBootstrap(session = null)
        }

        return try {
            val response = api.user("Bearer ${local.token}")
            if (response.isSuccessful) {
                val user = response.body()?.user
                    ?: return SessionBootstrap(local, "Profil pengguna dari server tidak lengkap.")
                sessionStore.save(local.token, local.tokenExpiresAt, user)
                SessionBootstrap(sessionStore.current())
            } else {
                val error = errorHandler.exception(
                    statusCode = response.code(),
                    rawBody = response.errorBody()?.string(),
                    fallbackMessage = "Sesi belum dapat diverifikasi ke server.",
                )
                if (error is SessionExpiredException) {
                    SessionBootstrap(null, error.message)
                } else {
                    SessionBootstrap(local, error.message)
                }
            }
        } catch (throwable: Throwable) {
            val normalized = errorHandler.normalize(throwable)
            SessionBootstrap(
                session = local,
                warningMessage = "Sesi lokal digunakan sementara. ${normalized.message.orEmpty()}".trim(),
            )
        }
    }

    suspend fun login(login: String, password: String): Result<Unit> = safeApiCall(errorHandler) {
        val response = api.login(
            LoginRequest(
                login = login.trim(),
                password = password,
                deviceName = "${Build.MANUFACTURER} ${Build.MODEL}".trim(),
                deviceId = sessionStore.installationId(),
            ),
        )

        if (!response.isSuccessful) {
            throw errorHandler.exception(
                response.code(),
                response.errorBody()?.string(),
                "Tidak dapat masuk. Periksa kembali data Anda.",
            )
        }

        val body = response.body() ?: throw IOException("Respons server tidak lengkap.")
        sessionStore.save(body.accessToken, body.expiresAt, body.user)
    }

    suspend fun logout() {
        val current = sessionStore.current()
        if (current != null) {
            runCatching { api.logout("Bearer ${current.token}") }
        }
        sessionStore.clear()
    }
}
