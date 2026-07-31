package id.sppg.mobile.core.notification

import android.content.Context
import com.google.firebase.FirebaseApp
import com.google.firebase.messaging.FirebaseMessaging
import id.sppg.mobile.BuildConfig
import id.sppg.mobile.data.NotificationRepository
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException
import kotlinx.coroutines.suspendCancellableCoroutine

class FirebaseTokenRegistrar(
    private val context: Context,
    private val repository: NotificationRepository,
) {
    suspend fun registerCurrentToken(): Result<Unit> = runCatching {
        if (!BuildConfig.FIREBASE_CONFIG_PRESENT) {
            throw IllegalStateException(
                "google-services.json belum ditemukan pada folder android/app.",
            )
        }

        val firebaseApp = FirebaseApp.getApps(context).firstOrNull()
            ?: FirebaseApp.initializeApp(context)
            ?: throw IllegalStateException(
                "Firebase tidak dapat diinisialisasi. Periksa package id.sppg.mobile pada Firebase Console.",
            )
        check(firebaseApp.options.projectId?.isNotBlank() == true) {
            "Project ID Firebase tidak ditemukan pada google-services.json."
        }

        val token = suspendCancellableCoroutine<String> { continuation ->
            FirebaseMessaging.getInstance().token
                .addOnSuccessListener { value ->
                    if (continuation.isActive) continuation.resume(value)
                }
                .addOnFailureListener { error ->
                    if (continuation.isActive) continuation.resumeWithException(error)
                }
        }
        check(token.isNotBlank()) { "Firebase tidak mengembalikan token perangkat." }
        repository.registerDeviceToken(token).getOrThrow()
    }

    suspend fun unregisterCurrentDevice(): Result<Unit> {
        val serverResult = repository.unregisterDevice()
        runCatching {
            if (FirebaseApp.getApps(context).isNotEmpty()) {
                suspendCancellableCoroutine<Unit> { continuation ->
                    FirebaseMessaging.getInstance().deleteToken()
                        .addOnSuccessListener {
                            if (continuation.isActive) continuation.resume(Unit)
                        }
                        .addOnFailureListener { error ->
                            if (continuation.isActive) continuation.resumeWithException(error)
                        }
                }
            }
        }
        return serverResult
    }
}
