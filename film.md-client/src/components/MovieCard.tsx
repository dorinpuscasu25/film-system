import React, { useMemo, useState } from 'react';
import { motion } from 'framer-motion';
import { PlayIcon, HeartIcon } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import { Movie } from '../types';
import { Badge } from './Badge';
import { StarRating } from './StarRating';
import { useWallet } from '../contexts/WalletContext';
interface MovieCardProps {
  movie: Movie;
}

function supportsInlineTrailerPreview(url?: string) {
  if (!url) {
    return false;
  }

  return /\.(mp4|webm|ogg)(\?.*)?$/i.test(url);
}

export function MovieCard({ movie }: MovieCardProps) {
  const navigate = useNavigate();
  const { isFavorite, toggleFavorite } = useWallet();
  const [isPreviewActive, setIsPreviewActive] = useState(false);
  const fav = isFavorite(movie.id);
  const previewUrl = useMemo(
    () => (supportsInlineTrailerPreview(movie.trailerUrl) ? movie.trailerUrl : ""),
    [movie.trailerUrl],
  );
  const lowestPrice =
  movie.offers && movie.offers.length > 0 ?
  Math.min(...movie.offers.map((offer) => offer.price)) :
  movie.price;
  return (
    <motion.div
      className="relative group cursor-pointer rounded-lg overflow-hidden flex-shrink-0 w-40 md:w-56 aspect-[2/3] bg-surface"
      whileHover={{
        scale: 1.05,
        zIndex: 10
      }}
      transition={{
        duration: 0.2
      }}
      onMouseEnter={() => setIsPreviewActive(true)}
      onMouseLeave={() => setIsPreviewActive(false)}
      onClick={() => navigate(`/movie/${movie.id}`)}>
      
      <img
        src={movie.posterUrl}
        alt={movie.title}
        className="w-full h-full object-cover"
        loading="lazy" />

      {previewUrl && isPreviewActive ? (
        <video
          className="absolute inset-0 hidden h-full w-full object-cover md:block"
          src={previewUrl}
          muted
          playsInline
          autoPlay
          loop
          preload="metadata"
        />
      ) : null}
      

      {/* Badges */}
      <div className="absolute top-2 left-2 flex flex-col space-y-1">
        {movie.isNew && <Badge variant="new" text="New" />}
        {movie.isTrending && <Badge variant="trending" text="Trending" />}
      </div>

      {/* Price + Favorite */}
      <div className="absolute top-2 right-2 flex flex-col items-end space-y-1">
        <Badge
          variant="price"
          text={lowestPrice === 0 ? 'Free' : `$${lowestPrice}`} />
        
        <button
          onClick={(e) => {
            e.stopPropagation();
            void toggleFavorite(movie.id);
          }}
          className="w-7 h-7 rounded-full bg-black/50 backdrop-blur-sm flex items-center justify-center transition-colors hover:bg-black/70">
          
          <HeartIcon
            className={`w-4 h-4 ${fav ? 'text-accent fill-current' : 'text-white/70'}`} />
          
        </button>
      </div>

      {/* Hover Overlay */}
      <div className="absolute inset-0 bg-gradient-to-t from-background via-background/80 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300 flex flex-col justify-end p-4">
        <div className="transform translate-y-4 group-hover:translate-y-0 transition-transform duration-300">
          <h3 className="text-white font-bold text-sm md:text-base line-clamp-1 mb-1">
            {movie.title}
          </h3>
          <div className="flex items-center space-x-2 text-xs text-gray-300 mb-2">
            <span>{movie.year}</span>
            <span>•</span>
            <span className="line-clamp-1">{movie.genres[0]}</span>
          </div>
          <div className="mb-3">
            <StarRating rating={movie.rating} size="sm" />
          </div>
          <button className="w-full bg-accent hover:bg-red-700 text-white py-1.5 rounded flex items-center justify-center space-x-1 transition-colors">
            <PlayIcon className="w-4 h-4 fill-current" />
            <span className="text-xs font-semibold uppercase tracking-wider">
              Details
            </span>
          </button>
        </div>
      </div>
    </motion.div>);

}
