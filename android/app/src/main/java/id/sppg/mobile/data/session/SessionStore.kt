package id.sppg.mobile.data.session

import android.content.Context
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import id.sppg.mobile.data.remote.MobileUser
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map

private val Context.sessionDataStore by preferencesDataStore(name = "mobile_session")

data class UserSession(
    val token: String,
    val userName: String,
    val role: String,
    val roleLabel: String,
    val unitName: String,
)

class SessionStore(private val context: Context) {
    private object Keys {
        val token = stringPreferencesKey("token")
        val userName = stringPreferencesKey("user_name")
        val role = stringPreferencesKey("role")
        val roleLabel = stringPreferencesKey("role_label")
        val unitName = stringPreferencesKey("unit_name")
    }

    val session: Flow<UserSession?> = context.sessionDataStore.data.map { preferences ->
        val token = preferences[Keys.token]
        if (token.isNullOrBlank()) {
            null
        } else {
            UserSession(
                token = token,
                userName = preferences[Keys.userName].orEmpty(),
                role = preferences[Keys.role].orEmpty(),
                roleLabel = preferences[Keys.roleLabel].orEmpty(),
                unitName = preferences[Keys.unitName].orEmpty(),
            )
        }
    }

    suspend fun save(token: String, user: MobileUser) {
        context.sessionDataStore.edit { preferences ->
            preferences[Keys.token] = token
            preferences[Keys.userName] = user.name
            preferences[Keys.role] = user.primaryRole.orEmpty()
            preferences[Keys.roleLabel] = user.primaryRoleLabel
            preferences[Keys.unitName] = user.unit?.name.orEmpty()
        }
    }

    suspend fun clear() {
        context.sessionDataStore.edit { it.clear() }
    }
}

