package md.film.tv.ui.navigation

import androidx.compose.runtime.Composable
import androidx.compose.runtime.rememberCoroutineScope
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import kotlinx.coroutines.launch
import md.film.tv.data.FilmotecaRepository
import md.film.tv.ui.detail.DetailScreen
import md.film.tv.ui.pairing.PairingScreen
import md.film.tv.ui.player.PlayerScreen
import md.film.tv.ui.profile.ProfileSelectScreen
import md.film.tv.ui.shell.MainShell
import md.film.tv.ui.splash.SplashScreen
import md.film.tv.ui.trailer.TrailerScreen

@Composable
fun AppNavigation(postSplashRoute: String, repository: FilmotecaRepository) {
    val navController = rememberNavController()
    val scope = rememberCoroutineScope()

    fun goToPairing() {
        navController.navigate(Routes.PAIRING) { popUpTo(0) { inclusive = true } }
    }

    fun goToProfiles() {
        navController.navigate(Routes.PROFILES) { popUpTo(0) { inclusive = true } }
    }

    NavHost(navController = navController, startDestination = Routes.SPLASH) {

        composable(Routes.SPLASH) {
            SplashScreen(
                onDone = {
                    navController.navigate(postSplashRoute) {
                        popUpTo(Routes.SPLASH) { inclusive = true }
                    }
                },
            )
        }

        composable(Routes.PAIRING) {
            PairingScreen(
                onPaired = {
                    navController.navigate(Routes.PROFILES) {
                        popUpTo(Routes.PAIRING) { inclusive = true }
                    }
                },
            )
        }

        composable(Routes.PROFILES) {
            ProfileSelectScreen(
                onProfileChosen = {
                    navController.navigate(Routes.BROWSE) {
                        popUpTo(Routes.PROFILES) { inclusive = true }
                    }
                },
            )
        }

        composable(Routes.BROWSE) {
            MainShell(
                onOpenDetail = { slug -> navController.navigate(Routes.detail(slug)) },
                onPlay = { slug, episodeId -> navController.navigate(Routes.player(slug, episodeId)) },
                onSwitchProfile = { goToProfiles() },
                onSignOut = {
                    scope.launch {
                        repository.signOut()
                        goToPairing()
                    }
                },
            )
        }

        composable(
            route = Routes.DETAIL,
            arguments = listOf(navArgument("slug") { type = NavType.StringType }),
        ) { backStackEntry ->
            val slug = backStackEntry.arguments?.getString("slug").orEmpty()
            DetailScreen(
                slug = slug,
                onPlay = { s, episodeId -> navController.navigate(Routes.player(s, episodeId)) },
                onTrailer = { videoId -> navController.navigate(Routes.trailer(videoId)) },
            )
        }

        composable(
            route = Routes.TRAILER,
            arguments = listOf(navArgument("videoId") { type = NavType.StringType }),
        ) { backStackEntry ->
            TrailerScreen(videoId = backStackEntry.arguments?.getString("videoId").orEmpty())
        }

        composable(
            route = Routes.PLAYER,
            arguments = listOf(
                navArgument("slug") { type = NavType.StringType },
                navArgument("episodeId") {
                    type = NavType.StringType
                    nullable = true
                    defaultValue = null
                },
            ),
        ) { backStackEntry ->
            val slug = backStackEntry.arguments?.getString("slug").orEmpty()
            val episodeId = backStackEntry.arguments?.getString("episodeId")
            PlayerScreen(
                slug = slug,
                episodeId = episodeId,
                onNeedReauth = { goToPairing() },
            )
        }
    }
}
