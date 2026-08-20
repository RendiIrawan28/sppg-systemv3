package id.sppg.mobile.data

import id.sppg.mobile.data.remote.ApiErrorHandler
import id.sppg.mobile.data.remote.MobileApi
import id.sppg.mobile.data.remote.SecurityOverview
import id.sppg.mobile.data.remote.SecurityShiftData
import id.sppg.mobile.data.remote.SessionExpiredException
import id.sppg.mobile.data.remote.SubmitSecurityReportRequest
import id.sppg.mobile.data.remote.safeApiCall
import id.sppg.mobile.data.session.SessionStore
import java.io.IOException

class SecurityRepository(
    private val api: MobileApi,
    private val sessionStore: SessionStore,
    private val errorHandler: ApiErrorHandler,
) {
    suspend fun overview(date: String? = null): Result<SecurityOverview> = safeApiCall(errorHandler) {
        val response = api.securityOverview(authorization(), date)
        if (!response.isSuccessful) {
            throw apiException(response.code(), response.errorBody()?.string(), "Data keamanan belum dapat dimuat.")
        }
        response.body()?.data ?: throw IOException("Respons keamanan tidak lengkap.")
    }

    suspend fun startShift(): Result<SecurityShiftData> = safeApiCall(errorHandler) {
        val response = api.startSecurityShift(authorization())
        if (!response.isSuccessful) {
            throw apiException(response.code(), response.errorBody()?.string(), "Shift keamanan belum dapat dimulai.")
        }
        response.body()?.data ?: throw IOException("Respons shift keamanan tidak lengkap.")
    }

    suspend fun submitReport(
        shiftId: Long,
        situation: String,
        gateSecure: Boolean,
        perimeterSecure: Boolean,
        accessActivity: String,
        visitorActivity: String,
        notes: String,
        photo: String,
    ): Result<SecurityShiftData> = safeApiCall(errorHandler) {
        val response = api.submitSecurityReport(
            authorization(),
            shiftId,
            SubmitSecurityReportRequest(
                situation = situation,
                gateSecure = gateSecure,
                perimeterSecure = perimeterSecure,
                accessActivity = accessActivity.trim().ifBlank { null },
                visitorActivity = visitorActivity.trim().ifBlank { null },
                notes = notes.trim().ifBlank { null },
                photo = photo,
            ),
        )
        if (!response.isSuccessful) {
            throw apiException(response.code(), response.errorBody()?.string(), "Laporan keamanan belum dapat disimpan.")
        }
        response.body()?.data ?: throw IOException("Respons laporan keamanan tidak lengkap.")
    }

    private suspend fun authorization(): String {
        val token = sessionStore.current()?.token ?: throw SessionExpiredException()
        return "Bearer $token"
    }

    private suspend fun apiException(code: Int, body: String?, fallback: String): IOException =
        errorHandler.exception(code, body, fallback)
}
