package id.sppg.mobile.data

import com.google.gson.Gson
import id.sppg.mobile.data.remote.ApiError
import id.sppg.mobile.data.remote.ActivateFieldPlanRequest
import id.sppg.mobile.data.remote.FieldPlan
import id.sppg.mobile.data.remote.MobileApi
import id.sppg.mobile.data.remote.ReadinessResponse
import id.sppg.mobile.data.remote.UpdateFieldPlanRequest
import id.sppg.mobile.data.session.SessionStore
import kotlinx.coroutines.flow.first
import java.io.IOException

class FieldPlanRepository(
    private val api: MobileApi,
    private val sessionStore: SessionStore,
) {
    suspend fun getPlans(): Result<List<FieldPlan>> = runCatching {
        val response = api.fieldPlans(authorization())
        if (!response.isSuccessful) throw apiException(response.errorBody()?.string())
        response.body()?.data ?: throw IOException("Daftar rencana tidak tersedia.")
    }

    suspend fun getPlan(id: Long): Result<FieldPlan> = runCatching {
        val response = api.fieldPlan(authorization(), id)
        if (!response.isSuccessful) throw apiException(response.errorBody()?.string())
        response.body()?.data ?: throw IOException("Rincian rencana tidak tersedia.")
    }

    suspend fun updatePlan(id: Long, request: UpdateFieldPlanRequest): Result<FieldPlan> = runCatching {
        val response = api.updateFieldPlan(authorization(), id, request)
        if (!response.isSuccessful) throw apiException(response.errorBody()?.string())
        response.body()?.data ?: throw IOException("Rincian rencana tidak tersedia.")
    }

    suspend fun checkReadiness(id: Long): Result<ReadinessResponse> = runCatching {
        val response = api.fieldPlanReadiness(authorization(), id)
        if (!response.isSuccessful) throw apiException(response.errorBody()?.string())
        response.body() ?: throw IOException("Status kesiapan tidak tersedia.")
    }

    suspend fun activatePlan(id: Long, notes: String?): Result<FieldPlan> = runCatching {
        val response = api.activateFieldPlan(
            authorization(),
            id,
            ActivateFieldPlanRequest(notes?.trim()?.ifBlank { null }),
        )
        if (!response.isSuccessful) throw apiException(response.errorBody()?.string())
        response.body()?.data ?: throw IOException("Rencana gagal diperbarui setelah aktivasi.")
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
