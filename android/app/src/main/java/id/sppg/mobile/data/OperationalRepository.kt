package id.sppg.mobile.data

import com.google.gson.Gson
import id.sppg.mobile.data.remote.ApiError
import id.sppg.mobile.data.remote.MobileApi
import id.sppg.mobile.data.remote.OperationalModule
import id.sppg.mobile.data.remote.OperationalRecord
import id.sppg.mobile.data.remote.OperationalSaveRequest
import id.sppg.mobile.data.remote.OperationalActionRequest
import id.sppg.mobile.data.remote.OperationalRelationSaveRequest
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

    suspend fun updateRecord(module: String, id: Long, fields: Map<String, String?>): Result<OperationalRecord> =
        runCatching {
            val response = api.updateOperationalRecord(
                authorization = authorization(),
                module = module,
                id = id,
                request = OperationalSaveRequest(fields),
            )
            if (!response.isSuccessful) throw apiException(response.errorBody()?.string())
            response.body()?.data ?: throw IOException("Perubahan tidak dapat disimpan.")
        }

    suspend fun createRecord(module: String, fields: Map<String, String?>): Result<OperationalRecord> =
        runCatching {
            val response = api.createOperationalRecord(
                authorization = authorization(),
                module = module,
                request = OperationalSaveRequest(fields),
            )
            if (!response.isSuccessful) throw apiException(response.errorBody()?.string())
            response.body()?.data ?: throw IOException("Data baru tidak dapat dibuat.")
        }

    suspend fun deleteRecord(module: String, id: Long): Result<String> = runCatching {
        val response = api.deleteOperationalRecord(authorization(), module, id)
        if (!response.isSuccessful) throw apiException(response.errorBody()?.string())
        response.body()?.message ?: "Data berhasil dihapus."
    }

    suspend fun runAction(module: String, id: Long, action: String, notes: String?): Result<OperationalRecord> =
        runCatching {
            val response = api.runOperationalAction(
                authorization(),
                module,
                id,
                action,
                OperationalActionRequest(notes),
            )
            if (!response.isSuccessful) throw apiException(response.errorBody()?.string())
            response.body()?.data ?: throw IOException("Tahap pekerjaan tidak dapat diperbarui.")
        }

    suspend fun createRelation(
        module: String,
        id: Long,
        relation: String,
        fields: Map<String, String?>,
        files: Map<String, String>,
    ): Result<Unit> = runCatching {
        val response = api.createOperationalRelation(
            authorization(), module, id, relation, OperationalRelationSaveRequest(fields, files),
        )
        if (!response.isSuccessful) throw apiException(response.errorBody()?.string())
    }

    suspend fun updateRelation(
        module: String,
        id: Long,
        relation: String,
        item: Long,
        fields: Map<String, String?>,
        files: Map<String, String>,
    ): Result<Unit> = runCatching {
        val response = api.updateOperationalRelation(
            authorization(), module, id, relation, item, OperationalRelationSaveRequest(fields, files),
        )
        if (!response.isSuccessful) throw apiException(response.errorBody()?.string())
    }

    suspend fun deleteRelation(module: String, id: Long, relation: String, item: Long): Result<String> =
        runCatching {
            val response = api.deleteOperationalRelation(authorization(), module, id, relation, item)
            if (!response.isSuccessful) throw apiException(response.errorBody()?.string())
            response.body()?.message ?: "Rincian berhasil dihapus."
        }

    suspend fun runRelationAction(
        module: String,
        id: Long,
        relation: String,
        item: Long,
        action: String,
    ): Result<String> = runCatching {
        val response = api.runOperationalRelationAction(
            authorization(), module, id, relation, item, action, OperationalActionRequest(null),
        )
        if (!response.isSuccessful) throw apiException(response.errorBody()?.string())
        response.body()?.message ?: "Status tujuan berhasil diperbarui."
    }

    private suspend fun authorization(): String {
        val token = sessionStore.session.first()?.token
            ?: throw IOException("Sesi Anda telah berakhir. Silakan masuk kembali.")
        return "Bearer $token"
    }

    private fun apiException(body: String?): IOException {
        val error = body?.let {
            runCatching { Gson().fromJson(it, ApiError::class.java) }.getOrNull()
        }
        val validationMessage = error?.errors
            ?.values
            ?.flatten()
            ?.distinct()
            ?.joinToString(" ")
        return IOException(validationMessage ?: error?.message ?: "Tidak dapat mengambil data dari server.")
    }
}
