import { Movie, UserProfile, Review, Language } from '../types';

export const MOCK_LANGUAGES: Language[] = [
{ code: 'en', name: 'English', flag: '🇬🇧' },
{ code: 'ro', name: 'Română', flag: '🇷🇴' },
{ code: 'ru', name: 'Русский', flag: '🇷🇺' }];


export const MOCK_PROFILES: UserProfile[] = [
{
  id: 'p1',
  name: 'Alex',
  avatarUrl: 'A',
  isKids: false,
  color: 'from-blue-500 to-purple-600'
},
{
  id: 'p2',
  name: 'Maria',
  avatarUrl: 'M',
  isKids: false,
  color: 'from-pink-500 to-rose-500'
},
{
  id: 'p3',
  name: 'Kids',
  avatarUrl: 'K',
  isKids: true,
  color: 'from-green-400 to-emerald-600'
}];


export const MOCK_MOVIES: Movie[] = [
{
  id: 'm1',
  title: 'Dune: Part Two',
  originalTitle: 'Dune: Part Two',
  year: 2024,
  genres: ['Sci-Fi', 'Adventure', 'Drama'],
  country: 'USA',
  rating: 8.8,
  platformRating: 4.9,
  price: 4.99,
  accessDuration: 48,
  posterUrl: 'https://picsum.photos/seed/dune2/400/600',
  backdropUrl: 'https://picsum.photos/seed/dune2-bg/1200/600',
  description:
  'Paul Atreides unites with Chani and the Fremen while on a warpath of revenge against the conspirators who destroyed his family.',
  cast: [
  {
    id: 'c1',
    name: 'Timothée Chalamet',
    role: 'Paul Atreides',
    avatarUrl: 'https://picsum.photos/seed/tim/100/100'
  },
  {
    id: 'c2',
    name: 'Zendaya',
    role: 'Chani',
    avatarUrl: 'https://picsum.photos/seed/zen/100/100'
  }],

  trailerUrl: 'mock-trailer-url',
  isNew: true,
  isTrending: true,
  type: 'movie'
},
{
  id: 'm2',
  title: 'Oppenheimer',
  originalTitle: 'Oppenheimer',
  year: 2023,
  genres: ['Biography', 'Drama', 'History'],
  country: 'USA',
  rating: 8.4,
  platformRating: 4.8,
  price: 3.99,
  accessDuration: 48,
  posterUrl: 'https://picsum.photos/seed/oppenheimer/400/600',
  backdropUrl: 'https://picsum.photos/seed/oppenheimer-bg/1200/600',
  description:
  'The story of American scientist J. Robert Oppenheimer and his role in the development of the atomic bomb.',
  cast: [
  {
    id: 'c3',
    name: 'Cillian Murphy',
    role: 'J. Robert Oppenheimer',
    avatarUrl: 'https://picsum.photos/seed/cil/100/100'
  },
  {
    id: 'c4',
    name: 'Emily Blunt',
    role: 'Kitty Oppenheimer',
    avatarUrl: 'https://picsum.photos/seed/emi/100/100'
  }],
  crew: [
  {
    id: 'cr1',
    name: 'Christopher Nolan',
    job: 'Director',
    avatarUrl: 'https://picsum.photos/seed/nolan/100/100'
  },
  {
    id: 'cr2',
    name: 'Ludwig Göransson',
    job: 'Composer',
    avatarUrl: 'https://picsum.photos/seed/ludwig/100/100'
  }],
  videos: [
  {
    id: 'v1',
    type: 'trailer',
    title: 'Official Trailer',
    videoUrl: 'https://example.com/video/oppenheimer-trailer.mp4',
    thumbnailUrl: 'https://picsum.photos/seed/oppenheimer-video-1/1280/720',
    isPrimary: true
  },
  {
    id: 'v2',
    type: 'extra',
    title: 'Behind the Scenes',
    videoUrl: 'https://example.com/video/oppenheimer-bts.mp4',
    thumbnailUrl: 'https://picsum.photos/seed/oppenheimer-video-2/1280/720'
  }],
  offers: [
  {
    id: 'opp-rental-hd',
    name: '2 days HD',
    accessType: 'rental',
    quality: 'HD',
    price: 3.99,
    currency: 'MDL',
    rentalDays: 2
  },
  {
    id: 'opp-rental-sd',
    name: '2 days SD',
    accessType: 'rental',
    quality: 'SD',
    price: 2.79,
    currency: 'MDL',
    rentalDays: 2
  },
  {
    id: 'opp-life-hd',
    name: 'Forever HD',
    accessType: 'lifetime',
    quality: 'HD',
    price: 7.99,
    currency: 'MDL'
  }],
  trailerUrl: 'mock-trailer-url',
  isNew: false,
  isTrending: true,
  type: 'movie'
},
{
  id: 'm3',
  title: 'Shōgun',
  originalTitle: 'Shōgun',
  year: 2024,
  genres: ['Drama', 'History', 'War'],
  country: 'USA',
  rating: 9.1,
  platformRating: 5.0,
  price: 5.99,
  accessDuration: 48,
  posterUrl: 'https://picsum.photos/seed/shogun/400/600',
  backdropUrl: 'https://picsum.photos/seed/shogun-bg/1200/600',
  description:
  'When a mysterious European ship is found marooned in a nearby fishing village, Lord Yoshii Toranaga discovers secrets that could tip the scales of power.',
  cast: [
  {
    id: 'c5',
    name: 'Hiroyuki Sanada',
    role: 'Lord Yoshii Toranaga',
    avatarUrl: 'https://picsum.photos/seed/hir/100/100'
  },
  {
    id: 'c6',
    name: 'Cosmo Jarvis',
    role: 'John Blackthorne',
    avatarUrl: 'https://picsum.photos/seed/cos/100/100'
  }],

  trailerUrl: 'mock-trailer-url',
  isNew: true,
  isTrending: true,
  type: 'series',
  seasons: 1,
  episodes: 10
},
{
  id: 'm4',
  title: 'Poor Things',
  originalTitle: 'Poor Things',
  year: 2023,
  genres: ['Comedy', 'Drama', 'Romance'],
  country: 'UK',
  rating: 8.0,
  platformRating: 4.5,
  price: 2.99,
  accessDuration: 24,
  posterUrl: 'https://picsum.photos/seed/poorthings/400/600',
  backdropUrl: 'https://picsum.photos/seed/poorthings-bg/1200/600',
  description:
  'The incredible tale about the fantastical evolution of Bella Baxter, a young woman brought back to life by the brilliant and unorthodox scientist Dr. Godwin Baxter.',
  cast: [
  {
    id: 'c7',
    name: 'Emma Stone',
    role: 'Bella Baxter',
    avatarUrl: 'https://picsum.photos/seed/emma/100/100'
  },
  {
    id: 'c8',
    name: 'Mark Ruffalo',
    role: 'Duncan Wedderburn',
    avatarUrl: 'https://picsum.photos/seed/mark/100/100'
  }],

  trailerUrl: 'mock-trailer-url',
  isNew: false,
  isTrending: false,
  type: 'movie'
},
{
  id: 'm5',
  title: 'Spider-Man: Across the Spider-Verse',
  originalTitle: 'Spider-Man: Across the Spider-Verse',
  year: 2023,
  genres: ['Animation', 'Action', 'Adventure'],
  country: 'USA',
  rating: 8.6,
  platformRating: 4.9,
  price: 3.99,
  accessDuration: 48,
  posterUrl: 'https://picsum.photos/seed/spiderverse/400/600',
  backdropUrl: 'https://picsum.photos/seed/spiderverse-bg/1200/600',
  description:
  'Miles Morales catapults across the Multiverse, where he encounters a team of Spider-People charged with protecting its very existence.',
  cast: [
  {
    id: 'c9',
    name: 'Shameik Moore',
    role: 'Miles Morales (voice)',
    avatarUrl: 'https://picsum.photos/seed/sham/100/100'
  },
  {
    id: 'c10',
    name: 'Hailee Steinfeld',
    role: 'Gwen Stacy (voice)',
    avatarUrl: 'https://picsum.photos/seed/hai/100/100'
  }],

  trailerUrl: 'mock-trailer-url',
  isNew: false,
  isTrending: true,
  type: 'movie'
},
{
  id: 'm6',
  title: 'The Batman',
  originalTitle: 'The Batman',
  year: 2022,
  genres: ['Action', 'Crime', 'Drama'],
  country: 'USA',
  rating: 7.8,
  platformRating: 4.6,
  price: 1.99,
  accessDuration: 24,
  posterUrl: 'https://picsum.photos/seed/batman/400/600',
  backdropUrl: 'https://picsum.photos/seed/batman-bg/1200/600',
  description:
  "When a sadistic serial killer begins murdering key political figures in Gotham, Batman is forced to investigate the city's hidden corruption and question his family's involvement.",
  cast: [
  {
    id: 'c11',
    name: 'Robert Pattinson',
    role: 'Bruce Wayne',
    avatarUrl: 'https://picsum.photos/seed/rob/100/100'
  },
  {
    id: 'c12',
    name: 'Zoë Kravitz',
    role: 'Selina Kyle',
    avatarUrl: 'https://picsum.photos/seed/zoe/100/100'
  }],

  trailerUrl: 'mock-trailer-url',
  isNew: false,
  isTrending: false,
  type: 'movie'
},
{
  id: 'm7',
  title: 'Interstellar',
  originalTitle: 'Interstellar',
  year: 2014,
  genres: ['Adventure', 'Drama', 'Sci-Fi'],
  country: 'USA',
  rating: 8.7,
  platformRating: 4.9,
  price: 1.99,
  accessDuration: 24,
  posterUrl: 'https://picsum.photos/seed/interstellar/400/600',
  backdropUrl: 'https://picsum.photos/seed/interstellar-bg/1200/600',
  description:
  "A team of explorers travel through a wormhole in space in an attempt to ensure humanity's survival.",
  cast: [
  {
    id: 'c13',
    name: 'Matthew McConaughey',
    role: 'Cooper',
    avatarUrl: 'https://picsum.photos/seed/matt/100/100'
  },
  {
    id: 'c14',
    name: 'Anne Hathaway',
    role: 'Brand',
    avatarUrl: 'https://picsum.photos/seed/anne/100/100'
  }],

  trailerUrl: 'mock-trailer-url',
  isNew: false,
  isTrending: false,
  type: 'movie'
},
{
  id: 'm8',
  title: 'The Last of Us',
  originalTitle: 'The Last of Us',
  year: 2023,
  genres: ['Action', 'Adventure', 'Drama'],
  country: 'USA',
  rating: 8.8,
  platformRating: 4.8,
  price: 4.99,
  accessDuration: 48,
  posterUrl: 'https://picsum.photos/seed/tlou/400/600',
  backdropUrl: 'https://picsum.photos/seed/tlou-bg/1200/600',
  description:
  "After a global pandemic destroys civilization, a hardened survivor takes charge of a 14-year-old girl who may be humanity's last hope.",
  cast: [
  {
    id: 'c15',
    name: 'Pedro Pascal',
    role: 'Joel',
    avatarUrl: 'https://picsum.photos/seed/pedro/100/100'
  },
  {
    id: 'c16',
    name: 'Bella Ramsey',
    role: 'Ellie',
    avatarUrl: 'https://picsum.photos/seed/bella/100/100'
  }],
  crew: [
  {
    id: 'cr3',
    name: 'Craig Mazin',
    job: 'Creator',
    avatarUrl: 'https://picsum.photos/seed/mazin/100/100'
  },
  {
    id: 'cr4',
    name: 'Neil Druckmann',
    job: 'Creator',
    avatarUrl: 'https://picsum.photos/seed/druckmann/100/100'
  }],
  videos: [
  {
    id: 'v3',
    type: 'trailer',
    title: 'Official Trailer',
    videoUrl: 'https://example.com/video/tlou-trailer.mp4',
    thumbnailUrl: 'https://picsum.photos/seed/tlou-video-1/1280/720',
    isPrimary: true
  },
  {
    id: 'v4',
    type: 'extra',
    title: 'Production Featurette',
    videoUrl: 'https://example.com/video/tlou-featurette.mp4',
    thumbnailUrl: 'https://picsum.photos/seed/tlou-video-2/1280/720'
  }],
  offers: [
  {
    id: 'tlou-rental-hd',
    name: '2 days HD',
    accessType: 'rental',
    quality: 'HD',
    price: 4.99,
    currency: 'MDL',
    rentalDays: 2
  },
  {
    id: 'tlou-life-hd',
    name: 'Forever HD',
    accessType: 'lifetime',
    quality: 'HD',
    price: 9.99,
    currency: 'MDL'
  }],
  seasonsData: [
  {
    id: 'tlou-s1',
    seasonNumber: 1,
    title: 'Season 1',
    episodes: Array.from({ length: 9 }).map((_, index) => ({
      id: `tlou-s1-e${index + 1}`,
      episodeNumber: index + 1,
      title: `Episode ${index + 1}`,
      runtimeMinutes: 45,
      thumbnailUrl: 'https://picsum.photos/seed/tlou-episode/1280/720',
      videoUrl: `https://example.com/video/tlou-s1-e${index + 1}.mp4`
    }))
  },
  {
    id: 'tlou-s2',
    seasonNumber: 2,
    title: 'Season 2',
    episodes: Array.from({ length: 3 }).map((_, index) => ({
      id: `tlou-s2-e${index + 1}`,
      episodeNumber: index + 1,
      title: `Episode ${index + 1}`,
      runtimeMinutes: 45,
      thumbnailUrl: 'https://picsum.photos/seed/tlou-episode-two/1280/720',
      videoUrl: `https://example.com/video/tlou-s2-e${index + 1}.mp4`
    }))
  }],
  trailerUrl: 'mock-trailer-url',
  isNew: false,
  isTrending: true,
  type: 'series',
  seasons: 2,
  episodes: 9
},
{
  id: 'm9',
  title: 'Moldova: Land of Wine',
  originalTitle: 'Moldova: Țara Vinului',
  year: 2024,
  genres: ['Documentary'],
  country: 'Moldova',
  rating: 7.2,
  platformRating: 4.0,
  price: 0,
  accessDuration: 48,
  posterUrl: 'https://picsum.photos/seed/moldova-doc/400/600',
  backdropUrl: 'https://picsum.photos/seed/moldova-doc-bg/1200/600',
  description:
  "A stunning documentary exploring Moldova's ancient wine traditions, from the legendary underground cellars of Mileștii Mici to the family vineyards of Cricova.",
  cast: [
  {
    id: 'c17',
    name: 'Ion Druță',
    role: 'Narrator',
    avatarUrl: 'https://picsum.photos/seed/ion/100/100'
  },
  {
    id: 'c18',
    name: 'Ana Popescu',
    role: 'Winemaker',
    avatarUrl: 'https://picsum.photos/seed/anapop/100/100'
  }],

  trailerUrl: 'mock-trailer-url',
  isNew: true,
  isTrending: false,
  type: 'movie'
}];


export const MOCK_REVIEWS: Review[] = [
{
  id: 'r1',
  userId: 'u1',
  userName: 'MovieBuff99',
  userAvatar: 'M',
  rating: 5,
  comment:
  'Absolutely stunning visuals and incredible sound design. A masterpiece of modern cinema.',
  date: '2024-03-15'
},
{
  id: 'r2',
  userId: 'u2',
  userName: 'SarahCritic',
  userAvatar: 'S',
  rating: 4,
  comment:
  'Great pacing and acting, though the third act felt a bit rushed. Still highly recommended.',
  date: '2024-03-10'
},
{
  id: 'r3',
  userId: 'u3',
  userName: 'JohnDoe',
  userAvatar: 'J',
  rating: 5,
  comment:
  'Worth every penny. The 48h access gave me enough time to rewatch my favorite scenes.',
  date: '2024-03-05'
}];


export const TRANSLATIONS: Record<string, Record<string, string>> = {
  en: {
    'nav.home': 'Home',
    'nav.movies': 'Movies',
    'nav.series': 'Series',
    'nav.search': 'Search',
    'auth.login': 'Log In',
    'auth.register': 'Register',
    'auth.email': 'Email Address',
    'auth.password': 'Password',
    'btn.watch': 'Watch Now',
    'btn.buy': 'Buy Access',
    'btn.addList': 'My List',
    'home.trending': 'Trending Now',
    'home.new': 'New Releases',
    'home.recommended': 'Recommended for You',
    'movie.cast': 'Cast & Crew',
    'movie.reviews': 'Reviews',
    'movie.trailers': 'Trailers',
    'wallet.balance': 'Wallet Balance',
    'wallet.add': 'Add Funds'
  },
  ro: {
    'nav.home': 'Acasă',
    'nav.movies': 'Filme',
    'nav.series': 'Seriale',
    'nav.search': 'Căutare',
    'auth.login': 'Autentificare',
    'auth.register': 'Înregistrare',
    'auth.email': 'Adresă Email',
    'auth.password': 'Parolă',
    'btn.watch': 'Urmărește',
    'btn.buy': 'Cumpără Acces',
    'btn.addList': 'Lista Mea',
    'home.trending': 'În Tendințe',
    'home.new': 'Noutăți',
    'home.recommended': 'Recomandate',
    'movie.cast': 'Distribuție',
    'movie.reviews': 'Recenzii',
    'movie.trailers': 'Trailere',
    'wallet.balance': 'Sold Portofel',
    'wallet.add': 'Adaugă Fonduri'
  },
  ru: {
    'nav.home': 'Главная',
    'nav.movies': 'Фильмы',
    'nav.series': 'Сериалы',
    'nav.search': 'Поиск',
    'auth.login': 'Войти',
    'auth.register': 'Регистрация',
    'auth.email': 'Электронная почта',
    'auth.password': 'Пароль',
    'btn.watch': 'Смотреть',
    'btn.buy': 'Купить доступ',
    'btn.addList': 'Мой список',
    'home.trending': 'В тренде',
    'home.new': 'Новинки',
    'home.recommended': 'Рекомендуем',
    'movie.cast': 'В ролях',
    'movie.reviews': 'Отзывы',
    'movie.trailers': 'Трейлеры',
    'wallet.balance': 'Баланс кошелька',
    'wallet.add': 'Пополнить'
  }
};
