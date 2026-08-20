package id.sppg.mobile.data

import android.content.Context
import id.sppg.mobile.data.remote.ActivateFieldPlanRequest
import id.sppg.mobile.data.remote.ApiErrorHandler
import id.sppg.mobile.data.remote.FieldPlan
import id.sppg.mobile.data.remote.FieldPlanOption
import id.sppg.mobile.data.remote.CreateFieldPlanRequest
import id.sppg.mobile.data.remote.MobileApi
import id.sppg.mobile.data.remote.ReadinessResponse
import id.sppg.mobile.data.remote.SessionExpiredException
import id.sppg.mobile.data.remote.UpdateFieldPlanRequest
import id.sppg.mobile.data.remote.safeApiCall
import id.sppg.mobile.data.session.SessionStore
import java.io.File
import java.io.IOException

data class FieldPlanPage(
    val plans: List<FieldPlan>,
    val currentPage: Int,
    val lastPage: Int,
)

class FieldPlanRepository(
    private val api: MobileApi,
    private val sessionStore: SessionStore,
    private val errorHandler: ApiErrorHandler,
    private val context: Context,
) {
    suspend fun getPlans(page: Int = 1, date: String? = null): Result<FieldPlanPage> = safeApiCall(errorHandler) {
        val response = api.fieldPlans(authorization(), dateFrom = date, dateTo = date, page = page)
        if (!response.isSuccessful) throw responseException(response.code(), response.errorBody()?.string())
        val body = response.body() ?: throw IOException("Daftar rencana tidak tersedia.")
        FieldPlanPage(
            plans = body.data,
            currentPage = body.meta?.currentPage ?: page,
            lastPage = body.meta?.lastPage ?: page,
        )
    }

    suspend fun getPlan(id: Long): Result<FieldPlan> = safeApiCall(errorHandler) {
        val response = api.fieldPlan(authorization(), id)
        if (!response.isSuccessful) throw responseException(response.code(), response.errorBody()?.string())
        response.body()?.data ?: throw IOException("Rincian rencana tidak tersedia.")
    }

    suspend fun getOptions(): Result<List<FieldPlanOption>> = safeApiCall(errorHandler) {
        val response = api.fieldPlanOptions(authorization())
        if (!response.isSuccessful) throw responseException(response.code(), response.errorBody()?.string())
        response.body()?.data ?: throw IOException("Pilihan menu distribusi tidak tersedia.")
    }

    suspend fun createPlan(distributionDate: String, notes: String?): Result<FieldPlan> = safeApiCall(errorHandler) {
        val response = api.createFieldPlan(
            authorization(),
            CreateFieldPlanRequest(distributionDate = distributionDate, generalNotes = notes?.trim()?.ifBlank { null }),
        )
        if (!response.isSuccessful) throw responseException(response.code(), response.errorBody()?.string())
        response.body()?.data ?: throw IOException("Rencana distribusi gagal dibuat.")
    }

    suspend fun updatePlan(id: Long, request: UpdateFieldPlanRequest): Result<FieldPlan> = safeApiCall(errorHandler) {
        val response = api.updateFieldPlan(authorization(), id, request)
        if (!response.isSuccessful) throw responseException(response.code(), response.errorBody()?.string())
        response.body()?.data ?: throw IOException("Rincian rencana tidak tersedia.")
    }

    suspend fun refreshBeneficiaries(id: Long): Result<FieldPlan> = safeApiCall(errorHandler) {
        val response = api.refreshFieldPlanBeneficiaries(authorization(), id)
        if (!response.isSuccessful) throw responseException(response.code(), response.errorBody()?.string())
        response.body()?.data ?: throw IOException("Data penerima gagal diperbarui.")
    }

    suspend fun deletePlan(id: Long): Result<Unit> = safeApiCall(errorHandler) {
        val response = api.deleteFieldPlan(authorization(), id)
        if (!response.isSuccessful) throw responseException(response.code(), response.errorBody()?.string())
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

    suspend fun downloadDocument(id: Long, format: String = "pdf"): Result<File> = safeApiCall(errorHandler) {
        val response = api.fieldPlanDocument(authorization(), id, format)
        if (!response.isSuccessful) throw responseException(response.code(), response.errorBody()?.string())
        val body = response.body() ?: throw IOException("Dokumen rencana tidak tersedia.")
        val directory = File(context.cacheDir, "documents").apply { mkdirs() }
        val filename = response.headers()["Content-Disposition"]
            ?.substringAfter("filename=", "")
            ?.trim('"', '\'', ' ')
            ?.takeIf { it.isNotBlank() }
            ?: "rencana-distribusi-$id.${if (format == "xlsx") "xlsx" else "pdf"}"
        val file = File(directory, filename.replace(Regex("[^A-Za-z0-9._-]"), "-"))
        body.byteStream().use { input -> file.outputStream().use { output -> input.copyTo(output) } }
        file
    }

    private suspend fun authorization(): String {
        val token = sessionStore.current()?.token
            ?: throw SessionExpiredException()
        return "Bearer $token"
    }

    private suspend fun responseException(code: Int, body: String?): IOException =
        errorHandler.exception(code, body, "Tidak dapat mengambil data rencana dari server.")
}
