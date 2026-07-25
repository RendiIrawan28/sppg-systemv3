package id.sppg.mobile.data

import android.os.Build
import com.google.gson.Gson
import id.sppg.mobile.data.remote.ApiError
import id.sppg.mobile.data.remote.LoginRequest
import id.sppg.mobile.data.remote.MobileApi
import id.sppg.mobile.data.session.SessionStore
import id.sppg.mobile.data.session.UserSession
import kotlinx.coroutines.flow.Flow
import java.io.IOException

class AuthRepository(
    private val api: MobileApi,
    private val sessionStore: SessionStore,
) {
    val session: Flow<UserSession?> = sessionStore.session

    suspend fun login(login: String, password: String): Result<Unit> = runCatching {
        val response = api.login(
            LoginRequest(
                login = login.trim(),
                password = password,
                deviceName = "${Build.MANUFACTURER} ${Build.MODEL}",
            ),
        )

        if (!response.isSuccessful) {
            val apiError = response.errorBody()?.string()?.let {
                runCatching { Gson().fromJson(it, ApiError::class.java) }.getOrNull()
            }
            throw IOException(apiError?.message ?: "Tidak dapat masuk. Periksa kembali data Anda.")
        }

        val body = response.body() ?: throw IOException("Respons server tidak lengkap.")
        sessionStore.save(body.accessToken, body.user)
    }

    suspend fun logout(session: UserSession) {
        runCatching { api.logout("Bearer ${session.token}") }
        sessionStore.clear()
    }
}

