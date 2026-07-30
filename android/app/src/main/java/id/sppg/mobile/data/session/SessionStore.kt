package id.sppg.mobile.data.session

import android.content.Context
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.emptyPreferences
import androidx.datastore.preferences.core.longPreferencesKey
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.core.stringSetPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import id.sppg.mobile.core.security.TokenCipher
import id.sppg.mobile.data.remote.MobileUser
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.flow.catch
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map
import java.io.IOException
import java.util.UUID

private val Context.sessionDataStore by preferencesDataStore(name = "mobile_session")

data class UserSession(
    val token: String,
    val tokenExpiresAt: String?,
    val userId: Long,
    val employeeNumber: String,
    val userName: String,
    val email: String,
    val role: String,
    val roleLabel: String,
    val roles: Set<String>,
    val permissions: Set<String>,
    val unitId: Long?,
    val unitName: String,
)

data class SessionEvent(val message: String?)

class SessionStore(
    private val context: Context,
    private val tokenCipher: TokenCipher = TokenCipher(),
) {
    private object Keys {
        val token = stringPreferencesKey("token")
        val tokenExpiresAt = stringPreferencesKey("token_expires_at")
        val userId = longPreferencesKey("user_id")
        val employeeNumber = stringPreferencesKey("employee_number")
        val userName = stringPreferencesKey("user_name")
        val email = stringPreferencesKey("email")
        val role = stringPreferencesKey("role")
        val roleLabel = stringPreferencesKey("role_label")
        val roles = stringSetPreferencesKey("roles")
        val permissions = stringSetPreferencesKey("permissions")
        val unitId = longPreferencesKey("unit_id")
        val unitName = stringPreferencesKey("unit_name")
        val installationId = stringPreferencesKey("installation_id")
    }

    private val _events = MutableSharedFlow<SessionEvent>(extraBufferCapacity = 1)
    val events = _events.asSharedFlow()

    val session: Flow<UserSession?> = context.sessionDataStore.data
        .catch { error ->
            if (error is IOException) emit(emptyPreferences()) else throw error
        }
        .map { preferences ->
            val storedToken = preferences[Keys.token]
            val token = storedToken
                ?.takeIf { it.isNotBlank() }
                ?.let { encrypted -> runCatching { tokenCipher.decrypt(encrypted) }.getOrNull() }

            if (token.isNullOrBlank()) {
                null
            } else {
                UserSession(
                    token = token,
                    tokenExpiresAt = preferences[Keys.tokenExpiresAt],
                    userId = preferences[Keys.userId] ?: 0L,
                    employeeNumber = preferences[Keys.employeeNumber].orEmpty(),
                    userName = preferences[Keys.userName].orEmpty(),
                    email = preferences[Keys.email].orEmpty(),
                    role = preferences[Keys.role].orEmpty(),
                    roleLabel = preferences[Keys.roleLabel].orEmpty(),
                    roles = preferences[Keys.roles]?.toSet().orEmpty(),
                    permissions = preferences[Keys.permissions]?.toSet().orEmpty(),
                    unitId = preferences[Keys.unitId],
                    unitName = preferences[Keys.unitName].orEmpty(),
                )
            }
        }
        .distinctUntilChanged()

    suspend fun current(): UserSession? = session.first()

    suspend fun installationId(): String {
        val existing = context.sessionDataStore.data.first()[Keys.installationId]
        if (!existing.isNullOrBlank()) return existing

        val generated = UUID.randomUUID().toString()
        context.sessionDataStore.edit { preferences ->
            if (preferences[Keys.installationId].isNullOrBlank()) {
                preferences[Keys.installationId] = generated
            }
        }
        return context.sessionDataStore.data.first()[Keys.installationId] ?: generated
    }

    suspend fun save(token: String, tokenExpiresAt: String?, user: MobileUser) {
        context.sessionDataStore.edit { preferences ->
            preferences[Keys.token] = tokenCipher.encrypt(token)
            tokenExpiresAt?.let { preferences[Keys.tokenExpiresAt] = it }
                ?: preferences.remove(Keys.tokenExpiresAt)
            writeUser(preferences, user)
        }
    }

    suspend fun refreshUser(user: MobileUser) {
        context.sessionDataStore.edit { preferences -> writeUser(preferences, user) }
    }

    suspend fun clear(message: String? = null) {
        context.sessionDataStore.edit { preferences ->
            val installationId = preferences[Keys.installationId]
            preferences.clear()
            installationId?.let { preferences[Keys.installationId] = it }
        }
        _events.emit(SessionEvent(message))
    }

    private fun writeUser(
        preferences: androidx.datastore.preferences.core.MutablePreferences,
        user: MobileUser,
    ) {
        preferences[Keys.userId] = user.id
        preferences[Keys.employeeNumber] = user.employeeNumber.orEmpty()
        preferences[Keys.userName] = user.name
        preferences[Keys.email] = user.email
        preferences[Keys.role] = user.primaryRole.orEmpty()
        preferences[Keys.roleLabel] = user.primaryRoleLabel
        preferences[Keys.roles] = user.roles.toSet()
        preferences[Keys.permissions] = user.permissions.toSet()
        user.unit?.id?.let { preferences[Keys.unitId] = it }
            ?: preferences.remove(Keys.unitId)
        preferences[Keys.unitName] = user.unit?.name.orEmpty()
    }
}
