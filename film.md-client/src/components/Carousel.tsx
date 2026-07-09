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
    <section className="relative py-5 group">
      <div className="mx-auto mb-4 flex max-w-[2200px] items-end justify-between px-4 sm:px-6 md:px-10 2xl:px-12">
        <div className="space-y-1">
          <h2 className="text-xl font-bold text-white md:text-2xl 2xl:text-3xl">{title}</h2>
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

      <div className="relative mx-auto max-w-[2200px] overflow-hidden">
        {/* Left Arrow */}
        <button
          onClick={() => scroll('left')}
          className="absolute left-0 top-0 bottom-0 z-10 hidden w-16 items-center justify-center bg-gradient-to-r from-background to-transparent opacity-0 transition-opacity group-hover:opacity-100 md:flex disabled:opacity-0">
          
          <ChevronLeftIcon className="w-8 h-8 text-white hover:scale-125 transition-transform" />
        </button>

        {/* Scroll Container */}
        <div
          ref={scrollRef}
          className="flex gap-4 overflow-x-auto px-4 pb-8 pt-4 hide-scrollbar snap-x sm:px-6 md:px-10 2xl:gap-5 2xl:px-12 min-[2200px]:gap-6"
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
          className="absolute right-0 top-0 bottom-0 z-10 hidden w-16 items-center justify-center bg-gradient-to-l from-background to-transparent opacity-0 transition-opacity group-hover:opacity-100 md:flex">
          
          <ChevronRightIcon className="w-8 h-8 text-white hover:scale-125 transition-transform" />
        </button>
      </div>
    </section>);

}
