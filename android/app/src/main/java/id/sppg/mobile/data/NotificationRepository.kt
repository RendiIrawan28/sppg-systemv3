package id.sppg.mobile.data

import android.os.Build
import id.sppg.mobile.BuildConfig
import id.sppg.mobile.data.remote.ApiErrorHandler
import id.sppg.mobile.data.remote.MobileApi
import id.sppg.mobile.data.remote.MobileNotificationItem
import id.sppg.mobile.data.remote.MobileTaskItem
import id.sppg.mobile.data.remote.PushNotificationStatus
import id.sppg.mobile.data.remote.RegisterDeviceTokenRequest
import id.sppg.mobile.data.remote.SessionExpiredException
import id.sppg.mobile.data.remote.TestNotificationRequest
import id.sppg.mobile.data.remote.TestNotificationResult
import id.sppg.mobile.data.remote.safeApiCall
import id.sppg.mobile.data.session.SessionStore
import java.io.IOException

class NotificationRepository(
    private val api: MobileApi,
    private val sessionStore: SessionStore,
    private val errorHandler: ApiErrorHandler,
) {
    suspend fun registerDeviceToken(token: String): Result<Unit> = safeApiCall(errorHandler) {
        if (token.isBlank()) return@safeApiCall
        val response = api.registerDeviceToken(
            authorization(),
            RegisterDeviceTokenRequest(
                fcmToken = token,
                installationId = sessionStore.installationId(),
                deviceName = "${Build.MANUFACTURER} ${Build.MODEL}".trim(),
                appVersion = BuildConfig.VERSION_NAME,
            ),
        )
        if (!response.isSuccessful) {
            throw apiException(response.code(), response.errorBody()?.string(), "Perangkat belum dapat didaftarkan.")
        }
    }

    suspend fun unregisterDevice(): Result<Unit> = safeApiCall(errorHandler) {
        val response = api.unregisterDeviceToken(authorization(), sessionStore.installationId())
        if (!response.isSuccessful) {
            throw apiException(response.code(), response.errorBody()?.string(), "Notifikasi perangkat belum dapat dinonaktifkan.")
        }
    }

    suspend fun pushStatus(): Result<PushNotificationStatus> = safeApiCall(errorHandler) {
        val response = api.notificationStatus(
            authorization = authorization(),
            installationId = sessionStore.installationId(),
        )
        if (!response.isSuccessful) {
            throw apiException(response.code(), response.errorBody()?.string(), "Status notifikasi belum dapat diperiksa.")
        }
        response.body()?.data ?: throw IOException("Respons status notifikasi tidak lengkap.")
    }

    suspend fun sendTestNotification(): Result<TestNotificationResult> = safeApiCall(errorHandler) {
        val response = api.sendTestNotification(
            authorization = authorization(),
            request = TestNotificationRequest(sessionStore.installationId()),
        )
        if (!response.isSuccessful) {
            throw apiException(response.code(), response.errorBody()?.string(), "Notifikasi uji belum dapat dikirim.")
        }
        response.body()?.data ?: throw IOException("Respons notifikasi uji tidak lengkap.")
    }

    suspend fun tasks(status: String = "pending"): Result<Pair<List<MobileTaskItem>, Int>> = safeApiCall(errorHandler) {
        val response = api.tasks(authorization(), status)
        if (!response.isSuccessful) {
            throw apiException(response.code(), response.errorBody()?.string(), "Daftar tugas belum dapat dimuat.")
        }
        val body = response.body() ?: throw IOException("Respons daftar tugas tidak lengkap.")
        body.data to body.meta.unreadNotificationCount
    }

    suspend fun notifications(): Result<List<MobileNotificationItem>> = safeApiCall(errorHandler) {
        val response = api.notifications(authorization())
        if (!response.isSuccessful) {
            throw apiException(response.code(), response.errorBody()?.string(), "Riwayat notifikasi belum dapat dimuat.")
        }
        response.body()?.data ?: throw IOException("Respons notifikasi tidak lengkap.")
    }

    suspend fun markRead(id: Long): Result<Unit> = safeApiCall(errorHandler) {
        val response = api.readNotification(authorization(), id)
        if (!response.isSuccessful) {
            throw apiException(response.code(), response.errorBody()?.string(), "Notifikasi belum dapat ditandai dibaca.")
        }
    }

    suspend fun markAllRead(): Result<Unit> = safeApiCall(errorHandler) {
        val response = api.readAllNotifications(authorization())
        if (!response.isSuccessful) {
            throw apiException(response.code(), response.errorBody()?.string(), "Notifikasi belum dapat diperbarui.")
        }
    }

    private suspend fun authorization(): String {
        val token = sessionStore.current()?.token ?: throw SessionExpiredException()
        return "Bearer $token"
    }

    private suspend fun apiException(code: Int, body: String?, fallback: String): IOException =
        errorHandler.exception(code, body, fallback)
}
