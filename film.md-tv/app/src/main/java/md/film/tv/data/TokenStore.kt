package md.film.tv.data

import android.content.Context
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map

private val Context.dataStore by preferencesDataStore(name = "filmoteca_session")

/**
 * Persists the paired access token (and a little user info) across app restarts,
 * so the TV stays logged in until the user signs out or the token expires.
 */
class TokenStore(private val context: Context) {

    private val tokenKey = stringPreferencesKey("auth_token")
    private val userNameKey = stringPreferencesKey("user_name")

    val tokenFlow: Flow<String?> = context.dataStore.data.map { it[tokenKey] }

    suspend fun save(token: String, userName: String?) {
        context.dataStore.edit { prefs ->
            prefs[tokenKey] = token
            if (userName != null) prefs[userNameKey] = userName
        }
    }

    suspend fun clear() {
        context.dataStore.edit { it.clear() }
    }
}
