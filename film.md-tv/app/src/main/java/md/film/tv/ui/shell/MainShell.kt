package md.film.tv.ui.shell

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.LiveTv
import androidx.compose.material.icons.filled.Movie
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.VideoLibrary
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.tv.material3.DrawerValue
import androidx.tv.material3.Icon
import androidx.tv.material3.NavigationDrawer
import androidx.tv.material3.NavigationDrawerItem
import androidx.tv.material3.Text
import md.film.tv.ui.catalog.CatalogScreen
import md.film.tv.ui.catalog.SearchScreen
import md.film.tv.ui.home.HomeScreen
import md.film.tv.ui.library.LibraryScreen
import md.film.tv.ui.theme.Accent
import md.film.tv.ui.theme.Background
import md.film.tv.ui.theme.TextPrimary

private enum class Destination(val label: String, val icon: ImageVector) {
    HOME("Acasă", Icons.Filled.Home),
    MOVIES("Filme", Icons.Filled.Movie),
    SERIES("Seriale", Icons.Filled.LiveTv),
    SEARCH("Caută", Icons.Filled.Search),
    LIBRARY("Contul meu", Icons.Filled.VideoLibrary),
}

@Composable
fun MainShell(
    onOpenDetail: (String) -> Unit,
    onPlay: (String, String?) -> Unit,
    onSwitchProfile: () -> Unit,
    onSignOut: () -> Unit,
) {
    var selected by remember { mutableStateOf(Destination.HOME) }

    NavigationDrawer(
        drawerContent = { drawerValue ->
            val open = drawerValue == DrawerValue.Open
            Column(
                modifier = Modifier
                    .fillMaxHeight()
                    .background(Background.copy(alpha = 0.96f))
                    .padding(vertical = 28.dp, horizontal = 12.dp),
                verticalArrangement = Arrangement.spacedBy(6.dp),
            ) {
                // Brand mark — a play disc that reveals the name when expanded.
                Box(
                    modifier = Modifier.padding(start = 4.dp, bottom = 20.dp),
                    contentAlignment = Alignment.CenterStart,
                ) {
                    Box(
                        modifier = Modifier.size(40.dp).clip(CircleShape).background(Accent),
                        contentAlignment = Alignment.Center,
                    ) {
                        Icon(Icons.Filled.PlayArrow, contentDescription = null, tint = TextPrimary)
                    }
                    if (open) {
                        Text(
                            text = "   FILMOTECA",
                            color = TextPrimary,
                            fontSize = 18.sp,
                            modifier = Modifier.padding(start = 44.dp),
                        )
                    }
                }

                Destination.entries.forEach { dest ->
                    NavigationDrawerItem(
                        selected = dest == selected,
                        onClick = { selected = dest },
                        leadingContent = {
                            Icon(imageVector = dest.icon, contentDescription = dest.label)
                        },
                    ) {
                        Text(dest.label)
                    }
                }
            }
        },
    ) {
        Box(modifier = Modifier.fillMaxSize().background(Background)) {
            when (selected) {
                Destination.HOME -> HomeScreen(
                    onOpenDetail = onOpenDetail,
                    onResume = { slug -> onPlay(slug, null) },
                )
                Destination.MOVIES -> CatalogScreen(
                    type = "movie",
                    onOpenDetail = onOpenDetail,
                    viewModelKey = "movies",
                )
                Destination.SERIES -> CatalogScreen(
                    type = "series",
                    onOpenDetail = onOpenDetail,
                    viewModelKey = "series",
                )
                Destination.SEARCH -> SearchScreen(onOpenDetail = onOpenDetail)
                Destination.LIBRARY -> LibraryScreen(
                    onOpenDetail = onOpenDetail,
                    onSwitchProfile = onSwitchProfile,
                    onSignOut = onSignOut,
                )
            }
        }
    }
}
