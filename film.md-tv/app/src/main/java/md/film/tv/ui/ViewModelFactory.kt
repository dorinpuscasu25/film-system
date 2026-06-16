package md.film.tv.ui

import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.ViewModelProvider.AndroidViewModelFactory.Companion.APPLICATION_KEY
import androidx.lifecycle.viewmodel.CreationExtras
import androidx.lifecycle.viewmodel.initializer
import androidx.lifecycle.viewmodel.viewModelFactory
import md.film.tv.FilmotecaApp
import md.film.tv.ui.catalog.CatalogViewModel
import md.film.tv.ui.detail.DetailViewModel
import md.film.tv.ui.home.HomeViewModel
import md.film.tv.ui.library.LibraryViewModel
import md.film.tv.ui.pairing.PairingViewModel
import md.film.tv.ui.player.PlayerViewModel
import md.film.tv.ui.profile.ProfileViewModel

private fun CreationExtras.app(): FilmotecaApp =
    (this[APPLICATION_KEY] as FilmotecaApp)

/** Central place that wires every ViewModel to the shared repository. */
object AppViewModelFactory {
    val factory: ViewModelProvider.Factory = viewModelFactory {
        initializer { PairingViewModel(app().repository) }
        initializer { HomeViewModel(app().repository) }
        initializer { DetailViewModel(app().repository) }
        initializer { PlayerViewModel(app().repository) }
        initializer { CatalogViewModel(app().repository) }
        initializer { LibraryViewModel(app().repository) }
        initializer { ProfileViewModel(app().repository) }
    }
}
