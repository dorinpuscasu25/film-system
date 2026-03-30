import React from 'react';
import { ArrowLeftIcon } from 'lucide-react';
import { Movie } from '../types';
import { useWallet } from '../contexts/WalletContext';
interface VideoPlayerProps {
  movie: Movie;
  sourceUrl: string;
  episodeTitle?: string | null;
  onBack: () => void;
}
export function VideoPlayer({ movie, sourceUrl, episodeTitle, onBack }: VideoPlayerProps) {
  const { getTimeRemaining } = useWallet();
  const timeRemaining = getTimeRemaining(movie.id);

  return (
    <div className="relative flex h-screen w-full items-center justify-center overflow-hidden bg-black">
      <video
        key={sourceUrl}
        src={sourceUrl}
        className="h-full w-full bg-black object-contain"
        controls
        autoPlay
        playsInline
        poster={movie.backdropUrl || movie.posterUrl}
      />

      <div className="pointer-events-none absolute inset-x-0 top-0 z-20 bg-gradient-to-b from-black/90 via-black/50 to-transparent p-6">
        <div className="flex items-start justify-between gap-4">
          <div className="pointer-events-auto flex items-center space-x-4">
            <button
              onClick={onBack}
              className="rounded-full bg-black/50 p-2 text-white transition-colors hover:bg-black/70"
            >
              <ArrowLeftIcon className="h-6 w-6" />
            </button>
            <div>
              <h1 className="text-xl font-bold text-white">{movie.title}</h1>
              {episodeTitle ? <p className="text-sm text-gray-300">{episodeTitle}</p> : null}
            </div>
          </div>

          {timeRemaining ? (
            <div className="rounded-full border border-white/10 bg-black/50 px-3 py-1 text-sm text-gray-200 backdrop-blur-md">
              {timeRemaining === 'Lifetime' ? 'Lifetime access' : `Access ends in ${timeRemaining}`}
            </div>
          ) : null}
        </div>
      </div>
    </div>);

}
