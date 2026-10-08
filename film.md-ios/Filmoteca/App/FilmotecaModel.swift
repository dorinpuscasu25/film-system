import Foundation
import Observation

@MainActor @Observable
final class FilmotecaModel {
    enum SessionState { case loading, guest, authenticated }

    var session: SessionState = .loading
    var user: User?
    var activeProfile: Profile?
    var account: AccountResponse?
    var locale: LocaleCode {
        didSet { UserDefaults.standard.set(locale.rawValue, forKey: "filmoteca.locale") }
    }
    var authPresented = false
    var profilePickerPresented = false
    var globalError: String?
    var refreshID = UUID()
    /// Session restored and the first screen has its data — the intro may hand over.
    private(set) var isInitialRouteReady = false
    let container: AppContainer
    /// Home is fetched while the intro plays instead of after it.
    @ObservationIgnored private var homePrefetch: (locale: LocaleCode, task: Task<HomeResponse, Error>)?

    init(container: AppContainer) {
        self.container = container
        locale = LocaleCode(rawValue: UserDefaults.standard.string(forKey: "filmoteca.locale") ?? "ro") ?? .ro
        container.storeKitService.onBackgroundRedeem = { [weak self] in
            Task { await self?.refreshAccount() }
        }
        let locale = locale
        homePrefetch = (locale, Task { [catalog = container.catalogRepository] in try await catalog.home(locale: locale) })
        Task { await restoreSession() }
    }

    /// The home response fetched at launch, handed out once. Nil if it failed or the locale changed.
    func takePrefetchedHome(locale: LocaleCode) async -> HomeResponse? {
        guard let prefetch = homePrefetch else { return nil }
        homePrefetch = nil
        guard prefetch.locale == locale else { prefetch.task.cancel(); return nil }
        return try? await prefetch.task.value
    }

    func markInitialRouteReady() {
        guard !isInitialRouteReady else { return }
        isInitialRouteReady = true
    }

    var isAuthenticated: Bool { session == .authenticated }
    var balance: Double { account?.wallet.balanceAmount ?? user?.wallet?.balanceAmount ?? 0 }
    var currency: String { account?.wallet.currency ?? user?.wallet?.currency ?? "MDL" }
    var favorites: Set<String> {
        guard let id = activeProfile?.id else { return [] }
        return Set(account?.favoritesByProfile[id] ?? activeProfile?.favoriteSlugs ?? [])
    }

    var isKidsProfile: Bool { activeProfile?.isKids == true }

    func allows(_ content: Content) -> Bool {
        activeProfile?.allows(ageRating: content.ageRating) ?? true
    }

    func allows(ageRating: String?) -> Bool {
        activeProfile?.allows(ageRating: ageRating) ?? true
    }

    func restoreSession() async {
        guard container.sessionRepository.hasStoredSession else { session = .guest; return }
        do {
            let response = try await container.sessionRepository.currentUser()
            applyUser(response)
            session = .authenticated
            await refreshAccount()
        } catch let error as APIError where error.status == 401 {
            await container.sessionRepository.logout()
            session = .guest
        } catch {
            // Offline or the server hiccuped — the token is still valid, so don't sign the
            // user out; the account is fetched again on the next refresh.
            session = .authenticated
        }
    }

    func authenticate(_ response: AuthResponse) async {
        container.sessionRepository.store(token: response.token)
        applyUser(response.user)
        session = .authenticated
        authPresented = false
        profilePickerPresented = (response.user.profiles?.count ?? 0) > 1
        await refreshAccount()
        // A purchase Apple charged while logged out (or offline) is credited now.
        container.storeKitService.retryUnfinishedTransactions()
    }

    func applyUser(_ newUser: User) {
        user = newUser
        let preferredID = UserDefaults.standard.string(forKey: "filmoteca.activeProfile.\(newUser.id)")
        activeProfile = newUser.profiles?.first(where: { $0.id == preferredID })
            ?? newUser.profiles?.first(where: { $0.isDefault == true })
            ?? newUser.profiles?.first
    }

    func selectProfile(_ profile: Profile) {
        activeProfile = profile
        if let user { UserDefaults.standard.set(profile.id, forKey: "filmoteca.activeProfile.\(user.id)") }
        profilePickerPresented = false
    }

    func refreshAccount() async {
        guard isAuthenticated else { account = nil; return }
        do {
            if user == nil { applyUser(try await container.sessionRepository.currentUser()) }
            account = try await container.sessionRepository.account(locale: locale)
        } catch let error as APIError where error.status == 401 {
            // Token revoked elsewhere (password change, account deleted, signed out on web).
            logout()
            authPresented = true
        } catch {
            globalError = error.localizedDescription
        }
    }

    func toggleFavorite(_ slug: String) async {
        guard let profile = activeProfile else { authPresented = true; return }
        do {
            try await container.sessionRepository.favorite(profileID: profile.id, slug: slug, add: !favorites.contains(slug))
            await refreshAccount()
        } catch { globalError = error.localizedDescription }
    }

    func logout() {
        Task { await container.sessionRepository.logout() }
        clearSession()
    }

    /// Drops local session state without calling the API. Used after account
    /// deletion, where the token is already revoked server-side.
    func clearSession() {
        user = nil; activeProfile = nil; account = nil; session = .guest
    }

    func t(_ key: String) -> String {
        let values: [String: [LocaleCode: String]] = [
            "home": [.ro: "Acasă", .ru: "Главная", .en: "Home"], "search": [.ro: "Caută", .ru: "Поиск", .en: "Search"],
            "library": [.ro: "Biblioteca", .ru: "Библиотека", .en: "Library"], "account": [.ro: "Cont", .ru: "Профиль", .en: "Account"],
            "watch": [.ro: "Vizionează", .ru: "Смотреть", .en: "Watch"], "details": [.ro: "Detalii", .ru: "Подробнее", .en: "Details"],
            "continue": [.ro: "Continuă vizionarea", .ru: "Продолжить просмотр", .en: "Continue watching"], "new": [.ro: "Noutăți", .ru: "Новинки", .en: "New releases"],
            "movies": [.ro: "Filme", .ru: "Фильмы", .en: "Movies"], "series": [.ro: "Seriale", .ru: "Сериалы", .en: "Series"],
            "free": [.ro: "Vizionare gratuită", .ru: "Бесплатно", .en: "Free to watch"], "login": [.ro: "Autentificare", .ru: "Войти", .en: "Sign in"],
            "retry": [.ro: "Încearcă din nou", .ru: "Повторить", .en: "Try again"], "topup": [.ro: "Alimentează contul", .ru: "Пополнить счёт", .en: "Add funds"],
            "loading": [.ro: "Se încarcă…", .ru: "Загрузка…", .en: "Loading…"], "load_more": [.ro: "Încarcă mai multe", .ru: "Показать ещё", .en: "Load more"],
            "filters": [.ro: "Filtre", .ru: "Фильтры", .en: "Filters"], "show_results": [.ro: "Vezi rezultate", .ru: "Показать", .en: "Show results"],
            "clear_filters": [.ro: "Curăță filtrele", .ru: "Сбросить фильтры", .en: "Clear filters"], "all": [.ro: "Toate", .ru: "Все", .en: "All"],
            "content_type": [.ro: "Tip conținut", .ru: "Тип контента", .en: "Content type"], "genre": [.ro: "Gen", .ru: "Жанр", .en: "Genre"],
            "country": [.ro: "Țara", .ru: "Страна", .en: "Country"], "release_year": [.ro: "Anul lansării", .ru: "Год выпуска", .en: "Release year"],
            "access": [.ro: "Acces", .ru: "Доступ", .en: "Access"], "free_short": [.ro: "Gratuit", .ru: "Бесплатно", .en: "Free"],
            "paid": [.ro: "Cu plată", .ru: "Платно", .en: "Paid"], "minimum_rating": [.ro: "Rating minim", .ru: "Минимальный рейтинг", .en: "Minimum rating"],
            "any_rating": [.ro: "Oricare", .ru: "Любой", .en: "Any"], "search_prompt": [.ro: "Titlu, actor sau gen", .ru: "Название, актёр или жанр", .en: "Title, actor or genre"],
            "documentaries": [.ro: "Documentare", .ru: "Документальные", .en: "Documentaries"], "short_films": [.ro: "Scurtmetraje", .ru: "Короткий метр", .en: "Short films"],
            "animation": [.ro: "Animație", .ru: "Анимация", .en: "Animation"], "no_results": [.ro: "Niciun rezultat", .ru: "Ничего не найдено", .en: "No results"],
            "discover_cinema": [.ro: "Descoperă cinematografia", .ru: "Откройте мир кино", .en: "Discover cinema"],
            "discover_subtitle": [.ro: "Explorează filmele moldovenești și internaționale.", .ru: "Откройте молдавские и зарубежные фильмы.", .en: "Explore Moldovan and international films."],
            "adjust_filters": [.ro: "Încearcă alt titlu sau modifică filtrele.", .ru: "Попробуйте другой запрос или измените фильтры.", .en: "Try another title or adjust the filters."],
            "loading_legal": [.ro: "Se încarcă meniul legal…", .ru: "Загрузка правового меню…", .en: "Loading legal menu…"],
            "close": [.ro: "Închide", .ru: "Закрыть", .en: "Close"],
            "ad_label": [.ro: "Publicitate", .ru: "Реклама", .en: "Advertisement"],
            "ad_skip": [.ro: "Sari peste", .ru: "Пропустить", .en: "Skip ad"],
            "ad_skip_in": [.ro: "Sari peste în", .ru: "Пропустить через", .en: "Skip in"],
            "ad_learn_more": [.ro: "Află mai mult", .ru: "Подробнее", .en: "Learn more"],
            "ad_mute": [.ro: "Oprește sonorul", .ru: "Выключить звук", .en: "Mute"],
            "ad_unmute": [.ro: "Pornește sonorul", .ru: "Включить звук", .en: "Unmute"],
            "cancel": [.ro: "Anulează", .ru: "Отмена", .en: "Cancel"],
            "forgot_password": [.ro: "Ai uitat parola?", .ru: "Забыли пароль?", .en: "Forgot your password?"],
            "password_reset_intro": [.ro: "Îți trimitem un cod din 6 cifre pe email pentru a-ți alege o parolă nouă.", .ru: "Мы отправим на почту код из 6 цифр, чтобы вы задали новый пароль.", .en: "We'll email you a 6-digit code so you can choose a new password."],
            "password_reset_send": [.ro: "Trimite codul", .ru: "Отправить код", .en: "Send code"],
            "password_reset_code_sent": [.ro: "Dacă există un cont cu acest email, codul a fost trimis. Verifică inbox-ul și folderul spam.", .ru: "Если аккаунт с такой почтой существует, код отправлен. Проверьте входящие и спам.", .en: "If an account exists for this email, the code has been sent. Check your inbox and spam folder."],
            "password_reset_code": [.ro: "Cod de resetare", .ru: "Код сброса", .en: "Reset code"],
            "password_new": [.ro: "Parolă nouă", .ru: "Новый пароль", .en: "New password"],
            "password_min_length": [.ro: "Minimum 12 caractere, cu literă mare, literă mică, cifră și simbol.", .ru: "Минимум 12 символов: заглавная и строчная буквы, цифра и символ.", .en: "At least 12 characters, with upper- and lowercase letters, a number and a symbol."],
            "password_reset_confirm": [.ro: "Schimbă parola", .ru: "Изменить пароль", .en: "Change password"],
            "password_reset_done": [.ro: "Parola a fost schimbată. Autentifică-te cu parola nouă.", .ru: "Пароль изменён. Войдите с новым паролем.", .en: "Password changed. Sign in with your new password."],
            "playback_settings": [.ro: "Setări redare", .ru: "Настройки воспроизведения", .en: "Playback settings"],
            "subtitles": [.ro: "Subtitrări", .ru: "Субтитры", .en: "Subtitles"],
            "subtitles_off": [.ro: "Dezactivate", .ru: "Выключены", .en: "Off"],
            "audio_track": [.ro: "Pistă audio", .ru: "Аудиодорожка", .en: "Audio track"],
            "quality": [.ro: "Calitate", .ru: "Качество", .en: "Quality"],
            "quality_auto": [.ro: "Automat", .ru: "Автоматически", .en: "Auto"],
            "quality_high": [.ro: "Înaltă", .ru: "Высокое", .en: "High"],
            "quality_medium": [.ro: "Medie", .ru: "Среднее", .en: "Medium"],
            "quality_low": [.ro: "Redusă (economie date)", .ru: "Низкое (экономия трафика)", .en: "Low (data saver)"],
            "playback_speed": [.ro: "Viteză de redare", .ru: "Скорость воспроизведения", .en: "Playback speed"],
            "speed_normal": [.ro: "Normală", .ru: "Обычная", .en: "Normal"],
            "no_tracks_available": [.ro: "Acest titlu nu are subtitrări sau piste audio suplimentare.", .ru: "У этого фильма нет субтитров или дополнительных аудиодорожек.", .en: "This title has no subtitles or additional audio tracks."],
            "recommended": [.ro: "S-ar putea să-ți placă și", .ru: "Вам может понравиться", .en: "You might also like"],
            "credit_packs_header": [.ro: "Pachete de credit", .ru: "Пакеты кредитов", .en: "Credit packs"],
            "credit_packs_footer": [.ro: "1 credit = 1 MDL, valabil pe orice platformă. Creditele nu expiră niciodată.", .ru: "1 кредит = 1 MDL на любой платформе. Кредиты никогда не сгорают.", .en: "1 credit = 1 MDL on every platform. Credits never expire."],
            "credits_amount": [.ro: "%d credite", .ru: "%d кредитов", .en: "%d credits"],
            "current_balance": [.ro: "Sold curent", .ru: "Текущий баланс", .en: "Current balance"],
            "credit_pack_redeem_pending": [.ro: "Plata a fost înregistrată de Apple, dar creditele nu au putut fi adăugate încă. Reîncercăm automat la următoarea deschidere a aplicației. Dacă nu apar, scrie-ne.", .ru: "Apple принял оплату, но кредиты пока не зачислены. Мы повторим автоматически при следующем запуске приложения. Если они не появятся, напишите нам.", .en: "Apple recorded the payment, but the credits couldn't be added yet. We'll retry automatically next time you open the app. If they don't show up, contact us."],
            "credit_packs_unavailable": [.ro: "Pachetele de credit nu sunt disponibile momentan.", .ru: "Пакеты кредитов сейчас недоступны.", .en: "Credit packs aren't available right now."],
        ]
        return values[key]?[locale] ?? key
    }

    func t(_ key: String, count: Int) -> String {
        guard key == "results_count" else { return t(key) }
        switch locale {
        case .ro: return count == 1 ? "1 rezultat" : "\(count) rezultate"
        case .ru: return "Результатов: \(count)"
        case .en: return count == 1 ? "1 result" : "\(count) results"
        }
    }
}
