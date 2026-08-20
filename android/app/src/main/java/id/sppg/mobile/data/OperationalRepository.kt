package id.sppg.mobile.data

import android.content.Context
import id.sppg.mobile.data.remote.ApiErrorHandler
import id.sppg.mobile.data.remote.MobileApi
import id.sppg.mobile.data.remote.OperationalActionRequest
import id.sppg.mobile.data.remote.OperationalModule
import id.sppg.mobile.data.remote.MobileDailySummary
import id.sppg.mobile.data.remote.OperationalRecord
import id.sppg.mobile.data.remote.OperationalRelationSaveRequest
import id.sppg.mobile.data.remote.OperationalSaveRequest
import id.sppg.mobile.data.remote.SessionExpiredException
import id.sppg.mobile.data.remote.safeApiCall
import id.sppg.mobile.data.session.SessionStore
import java.io.File
import java.io.IOException

data class OperationalPage(
    val records: List<OperationalRecord>,
    val currentPage: Int,
    val lastPage: Int,
)

data class OperationalWorkspace(
    val modules: List<OperationalModule>,
    val dailySummary: MobileDailySummary?,
)

class OperationalRepository(
    private val api: MobileApi,
    private val sessionStore: SessionStore,
    private val errorHandler: ApiErrorHandler,
    private val context: Context,
) {
    suspend fun getModules(): Result<OperationalWorkspace> = safeApiCall(errorHandler) {
        val response = api.operationalModules(authorization())
        if (!response.isSuccessful) throw apiException(response.code(), response.errorBody()?.string())
        val body = response.body() ?: throw IOException("Ruang kerja tidak tersedia.")
        OperationalWorkspace(body.data, body.dailySummary)
    }

    suspend fun getRecords(
        module: String,
        page: Int = 1,
        status: String? = null,
        date: String? = null,
    ): Result<OperationalPage> = safeApiCall(errorHandler) {
        val response = api.operationalRecords(
            authorization(),
            module,
            status = status,
            dateFrom = date,
            dateTo = date,
            page = page,
        )
        if (!response.isSuccessful) throw apiException(response.code(), response.errorBody()?.string())
        val body = response.body() ?: throw IOException("Daftar pekerjaan tidak tersedia.")
        OperationalPage(
            records = body.data,
            currentPage = body.meta?.currentPage ?: page,
            lastPage = body.meta?.lastPage ?: page,
        )
    }

    suspend fun getRecord(module: String, id: Long): Result<OperationalRecord> = safeApiCall(errorHandler) {
        val response = api.operationalRecord(authorization(), module, id)
        if (!response.isSuccessful) throw apiException(response.code(), response.errorBody()?.string())
        response.body()?.data ?: throw IOException("Rincian pekerjaan tidak tersedia.")
    }

    suspend fun updateRecord(
        module: String,
        id: Long,
        fields: Map<String, String?>,
        files: Map<String, String>,
    ): Result<OperationalRecord> =
        safeApiCall(errorHandler) {
            val response = api.updateOperationalRecord(
                authorization = authorization(),
                module = module,
                id = id,
                request = OperationalSaveRequest(fields, files),
            )
            if (!response.isSuccessful) throw apiException(response.code(), response.errorBody()?.string())
            response.body()?.data ?: throw IOException("Perubahan tidak dapat disimpan.")
        }

    suspend fun createRecord(
        module: String,
        fields: Map<String, String?>,
        files: Map<String, String>,
    ): Result<OperationalRecord> =
        safeApiCall(errorHandler) {
            val response = api.createOperationalRecord(
                authorization = authorization(),
                module = module,
                request = OperationalSaveRequest(fields, files),
            )
            if (!response.isSuccessful) throw apiException(response.code(), response.errorBody()?.string())
            response.body()?.data ?: throw IOException("Data baru tidak dapat dibuat.")
        }

    suspend fun deleteRecord(module: String, id: Long): Result<String> = safeApiCall(errorHandler) {
        val response = api.deleteOperationalRecord(authorization(), module, id)
        if (!response.isSuccessful) throw apiException(response.code(), response.errorBody()?.string())
        response.body()?.message ?: "Data berhasil dihapus."
    }

    suspend fun runAction(
        module: String,
        id: Long,
        action: String,
        notes: String?,
        fields: Map<String, String?> = emptyMap(),
        files: Map<String, String> = emptyMap(),
    ): Result<OperationalRecord> = safeApiCall(errorHandler) {
            val response = api.runOperationalAction(
                authorization(),
                module,
                id,
                action,
                OperationalActionRequest(notes, fields, files),
            )
            if (!response.isSuccessful) throw apiException(response.code(), response.errorBody()?.string())
            response.body()?.data ?: throw IOException("Tahap pekerjaan tidak dapat diperbarui.")
        }

    suspend fun createRelation(
        module: String,
        id: Long,
        relation: String,
        fields: Map<String, String?>,
        files: Map<String, String>,
    ): Result<Unit> = safeApiCall(errorHandler) {
        val response = api.createOperationalRelation(
            authorization(), module, id, relation, OperationalRelationSaveRequest(fields, files),
        )
        if (!response.isSuccessful) throw apiException(response.code(), response.errorBody()?.string())
    }

    suspend fun updateRelation(
        module: String,
        id: Long,
        relation: String,
        item: Long,
        fields: Map<String, String?>,
        files: Map<String, String>,
    ): Result<Unit> = safeApiCall(errorHandler) {
        val response = api.updateOperationalRelation(
            authorization(), module, id, relation, item, OperationalRelationSaveRequest(fields, files),
        )
        if (!response.isSuccessful) throw apiException(response.code(), response.errorBody()?.string())
    }

    suspend fun deleteRelation(module: String, id: Long, relation: String, item: Long): Result<String> =
        safeApiCall(errorHandler) {
            val response = api.deleteOperationalRelation(authorization(), module, id, relation, item)
            if (!response.isSuccessful) throw apiException(response.code(), response.errorBody()?.string())
            response.body()?.message ?: "Rincian berhasil dihapus."
        }

    suspend fun runRelationAction(
        module: String,
        id: Long,
        relation: String,
        item: Long,
        action: String,
        notes: String? = null,
        fields: Map<String, String?> = emptyMap(),
        files: Map<String, String> = emptyMap(),
    ): Result<String> = safeApiCall(errorHandler) {
        val response = api.runOperationalRelationAction(
            authorization(), module, id, relation, item, action, OperationalActionRequest(notes, fields, files),
        )
        if (!response.isSuccessful) throw apiException(response.code(), response.errorBody()?.string())
        response.body()?.message ?: "Status tujuan berhasil diperbarui."
    }

    suspend fun downloadDocument(module: String, id: Long, type: String? = null): Result<File> = safeApiCall(errorHandler) {
        val response = api.operationalDocument(authorization(), module, id, type)
        if (!response.isSuccessful) throw apiException(response.code(), response.errorBody()?.string())
        val body = response.body() ?: throw IOException("Dokumen tidak tersedia.")
        val directory = File(context.cacheDir, "documents").apply { mkdirs() }
        val filename = response.headers()["Content-Disposition"]
            ?.substringAfter("filename=", "")
            ?.trim('"', '\'', ' ')
            ?.takeIf { it.isNotBlank() }
            ?: listOfNotNull(module, type, id.toString()).joinToString("-") + ".pdf"
        val file = File(directory, filename.replace(Regex("[^A-Za-z0-9._-]"), "-"))
        body.byteStream().use { input -> file.outputStream().use { output -> input.copyTo(output) } }
        file
    }

    private suspend fun authorization(): String {
        val token = sessionStore.current()?.token ?: throw SessionExpiredException()
        return "Bearer $token"
    }

    private suspend fun apiException(code: Int, body: String?): IOException =
        errorHandler.exception(code, body, "Tidak dapat mengambil data dari server.")
}
