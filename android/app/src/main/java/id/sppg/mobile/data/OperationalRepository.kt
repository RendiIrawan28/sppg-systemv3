package id.sppg.mobile.data

import id.sppg.mobile.data.remote.ApiErrorHandler
import id.sppg.mobile.data.remote.MobileApi
import id.sppg.mobile.data.remote.OperationalActionRequest
import id.sppg.mobile.data.remote.OperationalModule
import id.sppg.mobile.data.remote.OperationalRecord
import id.sppg.mobile.data.remote.OperationalRelationSaveRequest
import id.sppg.mobile.data.remote.OperationalSaveRequest
import id.sppg.mobile.data.remote.SessionExpiredException
import id.sppg.mobile.data.remote.safeApiCall
import id.sppg.mobile.data.session.SessionStore
import java.io.IOException

class OperationalRepository(
    private val api: MobileApi,
    private val sessionStore: SessionStore,
    private val errorHandler: ApiErrorHandler,
) {
    suspend fun getModules(): Result<List<OperationalModule>> = safeApiCall(errorHandler) {
        val response = api.operationalModules(authorization())
        if (!response.isSuccessful) throw apiException(response.code(), response.errorBody()?.string())
        response.body()?.data ?: throw IOException("Ruang kerja tidak tersedia.")
    }

    suspend fun getRecords(module: String): Result<List<OperationalRecord>> = safeApiCall(errorHandler) {
        val response = api.operationalRecords(authorization(), module)
        if (!response.isSuccessful) throw apiException(response.code(), response.errorBody()?.string())
        response.body()?.data ?: throw IOException("Daftar pekerjaan tidak tersedia.")
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

    suspend fun runAction(module: String, id: Long, action: String, notes: String?): Result<OperationalRecord> =
        safeApiCall(errorHandler) {
            val response = api.runOperationalAction(
                authorization(),
                module,
                id,
                action,
                OperationalActionRequest(notes),
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
    ): Result<String> = safeApiCall(errorHandler) {
        val response = api.runOperationalRelationAction(
            authorization(), module, id, relation, item, action, OperationalActionRequest(null),
        )
        if (!response.isSuccessful) throw apiException(response.code(), response.errorBody()?.string())
        response.body()?.message ?: "Status tujuan berhasil diperbarui."
    }

    private suspend fun authorization(): String {
        val token = sessionStore.current()?.token ?: throw SessionExpiredException()
        return "Bearer $token"
    }

    private suspend fun apiException(code: Int, body: String?): IOException =
        errorHandler.exception(code, body, "Tidak dapat mengambil data dari server.")
}
