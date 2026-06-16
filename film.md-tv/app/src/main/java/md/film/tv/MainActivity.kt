package md.film.tv

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.tv.material3.Surface
import md.film.tv.ui.navigation.AppNavigation
import md.film.tv.ui.navigation.Routes
import md.film.tv.ui.theme.Background
import md.film.tv.ui.theme.FilmotecaTheme

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val repository = (application as FilmotecaApp).repository

        setContent {
            FilmotecaTheme {
                Surface(
                    modifier = Modifier.fillMaxSize().background(Background),
                ) {
                    // Resolve where to go after the splash, once we know whether a
                    // token is stored. The splash animation plays meanwhile.
                    var postSplash by remember { mutableStateOf<String?>(null) }

                    LaunchedEffect(Unit) {
                        val signedIn = repository.restoreSession()
                        postSplash = if (signedIn) Routes.PROFILES else Routes.PAIRING
                    }

                    postSplash?.let {
                        AppNavigation(postSplashRoute = it, repository = repository)
                    }
                }
            }
        }
    }
}
