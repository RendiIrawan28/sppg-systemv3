package id.sppg.mobile.core.notification

import android.content.Context
import com.google.firebase.FirebaseApp
import com.google.firebase.messaging.FirebaseMessaging
import id.sppg.mobile.data.NotificationRepository
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException
import kotlinx.coroutines.suspendCancellableCoroutine

class FirebaseTokenRegistrar(
    private val context: Context,
    private val repository: NotificationRepository,
) {
    suspend fun registerCurrentToken(): Result<Unit> = runCatching {
        val firebaseApp = FirebaseApp.initializeApp(context)
            ?: throw IllegalStateException(
                "Firebase belum dikonfigurasi. Tambahkan google-services.json pada folder android/app.",
            )
        val token = suspendCancellableCoroutine<String> { continuation ->
            FirebaseMessaging.getInstance().token
                .addOnSuccessListener { value ->
                    if (continuation.isActive) continuation.resume(value)
                }
                .addOnFailureListener { error ->
                    if (continuation.isActive) continuation.resumeWithException(error)
                }
        }
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
