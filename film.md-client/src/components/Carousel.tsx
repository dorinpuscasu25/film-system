import React, { useRef } from 'react';
import { ChevronLeftIcon, ChevronRightIcon } from 'lucide-react';
import { Movie } from '../types';
import { MovieCard } from './MovieCard';
interface CarouselProps {
  title: string;
  subtitle?: string;
  movies: Movie[];
  onSeeAll?: () => void;
}
export function Carousel({ title, subtitle, movies, onSeeAll }: CarouselProps) {
  const scrollRef = useRef<HTMLDivElement>(null);
  const scroll = (direction: 'left' | 'right') => {
    if (scrollRef.current) {
      const { scrollLeft, clientWidth } = scrollRef.current;
      const scrollTo =
      direction === 'left' ?
      scrollLeft - clientWidth * 0.8 :
      scrollLeft + clientWidth * 0.8;
      scrollRef.current.scrollTo({
        left: scrollTo,
        behavior: 'smooth'
      });
    }
  };
  if (!movies || movies.length === 0) return null;
  return (
    <div className="relative py-4 group">
      <div className="flex items-end justify-between mb-4 px-4 md:px-12">
        <div className="space-y-1">
          <h2 className="text-xl md:text-2xl font-bold text-white">{title}</h2>
          {subtitle ? <p className="text-sm text-gray-400">{subtitle}</p> : null}
        </div>
        {onSeeAll &&
        <button
          onClick={onSeeAll}
          className="text-sm font-medium text-accentCyan hover:text-white transition-colors">
          
            See All
          </button>
        }
      </div>

      <div className="relative">
        {/* Left Arrow */}
        <button
          onClick={() => scroll('left')}
          className="absolute left-0 top-0 bottom-0 w-12 bg-gradient-to-r from-background to-transparent z-10 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity disabled:opacity-0">
          
          <ChevronLeftIcon className="w-8 h-8 text-white hover:scale-125 transition-transform" />
        </button>

        {/* Scroll Container */}
        <div
          ref={scrollRef}
          className="flex space-x-4 overflow-x-auto px-4 md:px-12 pb-8 pt-4 hide-scrollbar snap-x"
          style={{
            scrollbarWidth: 'none',
            msOverflowStyle: 'none'
          }}>
          
          {movies.map((movie) =>
          <div key={movie.id} className="snap-start">
              <MovieCard movie={movie} />
            </div>
          )}
        </div>

        {/* Right Arrow */}
        <button
          onClick={() => scroll('right')}
          className="absolute right-0 top-0 bottom-0 w-12 bg-gradient-to-l from-background to-transparent z-10 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
          
          <ChevronRightIcon className="w-8 h-8 text-white hover:scale-125 transition-transform" />
        </button>
      </div>
    </div>);

}
