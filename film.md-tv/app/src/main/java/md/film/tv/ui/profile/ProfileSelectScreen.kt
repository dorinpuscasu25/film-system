package md.film.tv.ui.profile

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.compose.foundation.text.KeyboardOptions
import androidx.tv.material3.Button
import androidx.tv.material3.Card
import androidx.tv.material3.CardDefaults
import androidx.tv.material3.Text
import md.film.tv.data.remote.dto.ProfileDto
import md.film.tv.ui.AppViewModelFactory
import md.film.tv.ui.theme.Accent
import md.film.tv.ui.theme.Background
import md.film.tv.ui.theme.Surface
import md.film.tv.ui.theme.TextPrimary
import md.film.tv.ui.theme.TextSecondary

private fun parseColor(hex: String?): Color = runCatching {
    Color(android.graphics.Color.parseColor(hex ?: "#E50914"))
}.getOrDefault(Accent)

@Composable
fun ProfileSelectScreen(
    onProfileChosen: () -> Unit,
    viewModel: ProfileViewModel = viewModel(key = "profiles", factory = AppViewModelFactory.factory),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val selected by viewModel.selected.collectAsStateWithLifecycle()
    val pinError by viewModel.pinError.collectAsStateWithLifecycle()
    var pinTarget by remember { mutableStateOf<ProfileDto?>(null) }

    LaunchedEffect(Unit) { viewModel.load() }
    LaunchedEffect(selected) { if (selected) onProfileChosen() }

    Box(
        modifier = Modifier.fillMaxSize().background(Background),
        contentAlignment = Alignment.Center,
    ) {
        when (val s = state) {
            ProfileUiState.Loading -> Text("Se încarcă…", color = TextSecondary, fontSize = 20.sp)
            is ProfileUiState.Error -> Column(
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.spacedBy(16.dp),
            ) {
                Text(s.message, color = TextPrimary, fontSize = 20.sp)
                Button(onClick = viewModel::load) { Text("Reîncearcă") }
            }
            is ProfileUiState.Ready -> {
                if (s.profiles.size == 1) {
                    // Single profile — pick it automatically.
                    LaunchedEffect(Unit) { viewModel.choose(s.profiles.first()) }
                }
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Text("Cine se uită?", color = TextPrimary, fontSize = 36.sp, fontWeight = FontWeight.Bold)
                    Spacer(Modifier.height(40.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(32.dp)) {
                        s.profiles.forEach { profile ->
                            ProfileAvatar(
                                profile = profile,
                                onClick = {
                                    if (profile.hasPin) {
                                        viewModel.clearPinError()
                                        pinTarget = profile
                                    } else {
                                        viewModel.choose(profile)
                                    }
                                },
                            )
                        }
                    }
                }
            }
        }
    }

    pinTarget?.let { target ->
        ParentalPinDialog(
            profileName = target.name,
            errorMessage = pinError,
            onSubmit = { pin -> viewModel.chooseWithPin(target, pin) },
            onDismiss = { pinTarget = null; viewModel.clearPinError() },
        )
    }
}

@Composable
private fun ProfileAvatar(profile: ProfileDto, onClick: () -> Unit) {
    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        Card(
            onClick = onClick,
            shape = CardDefaults.shape(CircleShape),
            modifier = Modifier.size(140.dp),
            scale = CardDefaults.scale(focusedScale = 1.1f),
            colors = CardDefaults.colors(containerColor = parseColor(profile.avatarColor)),
        ) {
            Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                Text(
                    text = (profile.avatarLabel ?: profile.name.firstOrNull()?.toString() ?: "?").uppercase(),
                    color = TextPrimary,
                    fontSize = 48.sp,
                    fontWeight = FontWeight.Bold,
                )
            }
        }
        Spacer(Modifier.height(12.dp))
        Text(
            text = profile.name + if (profile.isKids) "  •  Copii" else "",
            color = TextSecondary,
            fontSize = 18.sp,
        )
    }
}

@Composable
private fun ParentalPinDialog(
    profileName: String,
    errorMessage: String?,
    onSubmit: (String) -> Unit,
    onDismiss: () -> Unit,
) {
    var pin by remember { mutableStateOf("") }

    Box(
        modifier = Modifier.fillMaxSize().background(Color.Black.copy(alpha = 0.7f)),
        contentAlignment = Alignment.Center,
    ) {
        Column(
            modifier = Modifier
                .clip(RoundedCornerShape(20.dp))
                .background(Surface)
                .padding(40.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(20.dp),
        ) {
            Text("Profil protejat", color = TextPrimary, fontSize = 26.sp, fontWeight = FontWeight.Bold)
            Text("Introdu PIN-ul pentru „$profileName”.", color = TextSecondary, fontSize = 16.sp)

            Box(
                modifier = Modifier
                    .size(width = 220.dp, height = 56.dp)
                    .clip(RoundedCornerShape(12.dp))
                    .background(Background)
                    .border(1.dp, Accent.copy(alpha = 0.4f), RoundedCornerShape(12.dp)),
                contentAlignment = Alignment.Center,
            ) {
                BasicTextField(
                    value = pin,
                    onValueChange = { if (it.length <= 6 && it.all(Char::isDigit)) pin = it },
                    singleLine = true,
                    textStyle = TextStyle(
                        color = TextPrimary,
                        fontSize = 28.sp,
                        textAlign = TextAlign.Center,
                        fontWeight = FontWeight.Bold,
                    ),
                    visualTransformation = PasswordVisualTransformation(),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
                    cursorBrush = SolidColor(Accent),
                )
            }

            errorMessage?.let { Text(it, color = Accent, fontSize = 15.sp) }

            Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                Button(onClick = onDismiss) { Text("Anulează") }
                Button(onClick = { if (pin.length >= 4) onSubmit(pin) }) { Text("Deblochează") }
            }
        }
    }
}
