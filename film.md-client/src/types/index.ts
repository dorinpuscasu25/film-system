export interface CastMember {
  id: string;
  name: string;
  role: string;
  avatarUrl: string;
}

export interface CrewMember {
  id: string;
  name: string;
  job: string;
  avatarUrl: string;
}

export interface VideoAsset {
  id: string;
  type: 'trailer' | 'teaser' | 'clip' | 'extra' | 'behind_scenes' | 'interview';
  title: string;
  videoUrl: string;
  thumbnailUrl?: string;
  durationSeconds?: number;
  isPrimary?: boolean;
}

export interface Episode {
  id: string;
  episodeNumber: number;
  title: string;
  description?: string;
  runtimeMinutes?: number;
  thumbnailUrl?: string;
  backdropUrl?: string;
  videoUrl?: string;
  trailerUrl?: string;
}

export interface Season {
  id: string;
  seasonNumber: number;
  title?: string;
  description?: string;
  posterUrl?: string;
  episodes: Episode[];
}

export interface Offer {
  id: string;
  name: string;
  accessType: 'free' | 'rental' | 'lifetime';
  quality: string;
  price: number;
  currency: string;
  rentalDays?: number;
  playbackUrl?: string;
}

export interface PremiereEventSummary {
  id: string;
  title: string;
  startsAt: string;
  endsAt?: string;
}

export interface Movie {
  id: string;
  title: string;
  originalTitle: string;
  year: number;
  genres: string[];
  country: string;
  rating: number;
  platformRating: number;
  price: number;
  accessDuration: number;
  posterUrl: string;
  backdropUrl: string;
  heroDesktopUrl?: string;
  heroMobileUrl?: string;
  shortDescription?: string;
  tagline?: string;
  description: string;
  cast: CastMember[];
  crew?: CrewMember[];
  videos?: VideoAsset[];
  trailerUrl: string;
  isNew: boolean;
  isTrending: boolean;
  isFeatured?: boolean;
  isFree?: boolean;
  type: 'movie' | 'series';
  seasons?: number;
  episodes?: number;
  seasonsData?: Season[];
  offers?: Offer[];
  premiereEvent?: PremiereEventSummary;
}

export interface UserProfile {
  id: string;
  name: string;
  avatarUrl: string;
  avatarLabel?: string;
  isKids: boolean;
  color: string;
  isDefault?: boolean;
  favoriteSlugs?: string[];
}

export interface User {
  id: string;
  email: string;
  name: string;
  profiles: UserProfile[];
  activeProfileId?: string;
  preferredLocale?: string;
}

export interface WalletTransaction {
  id: string;
  type: 'topup' | 'purchase' | 'welcome_bonus' | 'refund' | 'adjustment';
  amount: number;
  balanceAfter?: number;
  currency?: string;
  description?: string;
  date: string;
  movieTitle?: string;
}

export interface Review {
  id: string;
  userId: string;
  userName: string;
  userAvatar: string;
  rating: number;
  comment: string;
  date: string;
  likes: number;
}

export interface Purchase {
  id: string;
  movieId: string;
  movieTitle: string;
  purchaseDate: string;
  expiresAt: string | null;
  price: number;
  currency?: string;
  quality?: string;
  accessType?: 'free' | 'rental' | 'lifetime';
  isActive?: boolean;
  posterUrl?: string;
  backdropUrl?: string;
  contentType?: 'movie' | 'series';
}

export interface Language {
  code: 'en' | 'ro' | 'ru';
  name: string;
  flag: string;
}
