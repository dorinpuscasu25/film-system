package md.film.tv.data

import android.content.Context
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map

private val Context.profileDataStore by preferencesDataStore(name = "filmoteca_profile")

/** Remembers which account profile is active on this TV. */
class ProfileStore(private val context: Context) {

    private val idKey = stringPreferencesKey("active_profile_id")
    private val nameKey = stringPreferencesKey("active_profile_name")

    val activeProfileId: Flow<String?> = context.profileDataStore.data.map { it[idKey] }
    val activeProfileName: Flow<String?> = context.profileDataStore.data.map { it[nameKey] }

    suspend fun setActive(id: String, name: String) {
        context.profileDataStore.edit {
            it[idKey] = id
            it[nameKey] = name
        }
    }

    suspend fun clear() {
        context.profileDataStore.edit { it.clear() }
    }
}
