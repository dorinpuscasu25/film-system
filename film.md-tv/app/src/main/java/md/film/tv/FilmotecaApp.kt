package md.film.tv

import android.app.Application
import md.film.tv.data.FilmotecaRepository
import md.film.tv.data.ProfileStore
import md.film.tv.data.TokenStore
import md.film.tv.data.remote.Network

/**
 * Tiny hand-rolled service locator. The app is small enough that a full DI
 * framework would be overkill — everything hangs off the [repository] singleton.
 */
class FilmotecaApp : Application() {

    lateinit var repository: FilmotecaRepository
        private set

    override fun onCreate() {
        super.onCreate()

        val api = Network.create(
            baseUrl = BuildConfig.API_BASE_URL,
            debug = BuildConfig.DEBUG,
        )
        repository = FilmotecaRepository(api, TokenStore(this), ProfileStore(this))
    }
}
