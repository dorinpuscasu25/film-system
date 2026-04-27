import React from "react";

const SHIMMER = "animate-pulse bg-white/5";

function PosterSkeleton() {
  return (
    <div className="w-[160px] shrink-0 md:w-[200px]">
      <div className={`aspect-[2/3] w-full rounded-lg ${SHIMMER}`} />
      <div className={`mt-2 h-3 w-3/4 rounded ${SHIMMER}`} />
      <div className={`mt-1.5 h-2.5 w-1/2 rounded ${SHIMMER}`} />
    </div>
  );
}

function CarouselRowSkeleton() {
  return (
    <div className="px-4 md:px-12">
      <div className={`mb-4 h-6 w-48 rounded ${SHIMMER}`} />
      <div className="flex gap-3 overflow-hidden">
        {Array.from({ length: 6 }).map((_, i) => (
          <PosterSkeleton key={i} />
        ))}
      </div>
    </div>
  );
}

export function HomeSkeleton() {
  return (
    <div className="min-h-screen bg-background pb-20" aria-busy="true" aria-label="Loading">
      {/* Hero */}
      <div className="relative h-[85vh] w-full overflow-hidden">
        <div className={`absolute inset-0 ${SHIMMER}`} />
        <div className="absolute inset-0 bg-gradient-to-r from-background via-background/85 to-transparent" />
        <div className="absolute inset-0 bg-gradient-to-t from-background via-background/40 to-transparent" />

        <div className="absolute bottom-0 left-0 z-10 w-full px-4 pb-32 pt-32 md:px-12">
          <div className="max-w-2xl space-y-5">
            <div className="flex gap-3">
              <div className={`h-6 w-20 rounded-full ${SHIMMER}`} />
              <div className={`h-6 w-16 rounded-full ${SHIMMER}`} />
            </div>
            <div className={`h-12 w-3/4 rounded ${SHIMMER}`} />
            <div className={`h-12 w-1/2 rounded ${SHIMMER}`} />
            <div className="space-y-2">
              <div className={`h-4 w-full rounded ${SHIMMER}`} />
              <div className={`h-4 w-5/6 rounded ${SHIMMER}`} />
              <div className={`h-4 w-2/3 rounded ${SHIMMER}`} />
            </div>
            <div className="flex gap-4 pt-2">
              <div className={`h-12 w-40 rounded-lg ${SHIMMER}`} />
              <div className={`h-12 w-32 rounded-lg ${SHIMMER}`} />
            </div>
          </div>
        </div>
      </div>

      {/* Carousel rows */}
      <div className="relative z-10 mt-12 space-y-10">
        <CarouselRowSkeleton />
        <CarouselRowSkeleton />
        <CarouselRowSkeleton />
        <CarouselRowSkeleton />
      </div>
    </div>
  );
}
