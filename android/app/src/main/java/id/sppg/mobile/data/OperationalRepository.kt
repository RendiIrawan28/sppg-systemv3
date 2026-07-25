package id.sppg.mobile.data

import com.google.gson.Gson
import id.sppg.mobile.data.remote.ApiError
import id.sppg.mobile.data.remote.MobileApi
import id.sppg.mobile.data.remote.OperationalModule
import id.sppg.mobile.data.remote.OperationalRecord
import id.sppg.mobile.data.session.SessionStore
import kotlinx.coroutines.flow.first
import java.io.IOException

class OperationalRepository(
    private val api: MobileApi,
    private val sessionStore: SessionStore,
) {
    suspend fun getModules(): Result<List<OperationalModule>> = runCatching {
        val response = api.operationalModules(authorization())
        if (!response.isSuccessful) throw apiException(response.errorBody()?.string())
        response.body()?.data ?: throw IOException("Ruang kerja tidak tersedia.")
    }

    suspend fun getRecords(module: String): Result<List<OperationalRecord>> = runCatching {
        val response = api.operationalRecords(authorization(), module)
        if (!response.isSuccessful) throw apiException(response.errorBody()?.string())
        response.body()?.data ?: throw IOException("Daftar pekerjaan tidak tersedia.")
    }

    suspend fun getRecord(module: String, id: Long): Result<OperationalRecord> = runCatching {
        val response = api.operationalRecord(authorization(), module, id)
        if (!response.isSuccessful) throw apiException(response.errorBody()?.string())
        response.body()?.data ?: throw IOException("Rincian pekerjaan tidak tersedia.")
    }

    private suspend fun authorization(): String {
        val token = sessionStore.session.first()?.token
            ?: throw IOException("Sesi Anda telah berakhir. Silakan masuk kembali.")
        return "Bearer $token"
    }

    private fun apiException(body: String?): IOException {
        val message = body?.let {
            runCatching { Gson().fromJson(it, ApiError::class.java) }.getOrNull()?.message
        }
        return IOException(message ?: "Tidak dapat mengambil data dari server.")
    }
}
