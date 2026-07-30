package id.sppg.mobile.data

import id.sppg.mobile.data.remote.ActivateFieldPlanRequest
import id.sppg.mobile.data.remote.ApiErrorHandler
import id.sppg.mobile.data.remote.FieldPlan
import id.sppg.mobile.data.remote.MobileApi
import id.sppg.mobile.data.remote.ReadinessResponse
import id.sppg.mobile.data.remote.SessionExpiredException
import id.sppg.mobile.data.remote.UpdateFieldPlanRequest
import id.sppg.mobile.data.remote.safeApiCall
import id.sppg.mobile.data.session.SessionStore
import java.io.IOException

class FieldPlanRepository(
    private val api: MobileApi,
    private val sessionStore: SessionStore,
    private val errorHandler: ApiErrorHandler,
) {
    suspend fun getPlans(): Result<List<FieldPlan>> = safeApiCall(errorHandler) {
        val response = api.fieldPlans(authorization())
        if (!response.isSuccessful) throw responseException(response.code(), response.errorBody()?.string())
        response.body()?.data ?: throw IOException("Daftar rencana tidak tersedia.")
    }

    suspend fun getPlan(id: Long): Result<FieldPlan> = safeApiCall(errorHandler) {
        val response = api.fieldPlan(authorization(), id)
        if (!response.isSuccessful) throw responseException(response.code(), response.errorBody()?.string())
        response.body()?.data ?: throw IOException("Rincian rencana tidak tersedia.")
    }

    suspend fun updatePlan(id: Long, request: UpdateFieldPlanRequest): Result<FieldPlan> = safeApiCall(errorHandler) {
        val response = api.updateFieldPlan(authorization(), id, request)
        if (!response.isSuccessful) throw responseException(response.code(), response.errorBody()?.string())
        response.body()?.data ?: throw IOException("Rincian rencana tidak tersedia.")
    }

    suspend fun checkReadiness(id: Long): Result<ReadinessResponse> = safeApiCall(errorHandler) {
        val response = api.fieldPlanReadiness(authorization(), id)
        if (!response.isSuccessful) throw responseException(response.code(), response.errorBody()?.string())
        response.body() ?: throw IOException("Status kesiapan tidak tersedia.")
    }

    suspend fun activatePlan(id: Long, notes: String?): Result<FieldPlan> = safeApiCall(errorHandler) {
        val response = api.activateFieldPlan(
            authorization(),
            id,
            ActivateFieldPlanRequest(notes?.trim()?.ifBlank { null }),
        )
        if (!response.isSuccessful) throw responseException(response.code(), response.errorBody()?.string())
        response.body()?.data ?: throw IOException("Rencana gagal diperbarui setelah aktivasi.")
    }

    private suspend fun authorization(): String {
        val token = sessionStore.current()?.token
            ?: throw SessionExpiredException()
        return "Bearer $token"
    }

    private suspend fun responseException(code: Int, body: String?): IOException =
        errorHandler.exception(code, body, "Tidak dapat mengambil data rencana dari server.")
}
