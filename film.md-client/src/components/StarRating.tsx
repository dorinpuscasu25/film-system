import React, { useState } from 'react';
import { StarIcon } from 'lucide-react';
import { motion } from 'framer-motion';
interface StarRatingProps {
  rating: number;
  maxStars?: number;
  interactive?: boolean;
  onRate?: (rating: number) => void;
  size?: 'sm' | 'md' | 'lg';
}
export function StarRating({
  rating,
  maxStars = 5,
  interactive = false,
  onRate,
  size = 'md'
}: StarRatingProps) {
  const [hoverRating, setHoverRating] = useState(0);
  const sizeClasses = {
    sm: 'w-3 h-3',
    md: 'w-5 h-5',
    lg: 'w-8 h-8'
  };
  const displayRating =
  interactive && hoverRating > 0 ? hoverRating : Math.round(rating);
  return (
    <div className="flex items-center space-x-1">
      {[...Array(maxStars)].map((_, i) => {
        const starValue = i + 1;
        const isFilled = starValue <= displayRating;
        return (
          <motion.button
            key={i}
            type="button"
            disabled={!interactive}
            whileHover={
            interactive ?
            {
              scale: 1.2
            } :
            {}
            }
            whileTap={
            interactive ?
            {
              scale: 0.9
            } :
            {}
            }
            onMouseEnter={() => interactive && setHoverRating(starValue)}
            onMouseLeave={() => interactive && setHoverRating(0)}
            onClick={() => interactive && onRate?.(starValue)}
            className={`${interactive ? 'cursor-pointer' : 'cursor-default'} focus:outline-none`}>
            
            <StarIcon
              className={`${sizeClasses[size]} ${isFilled ? 'fill-accentGold text-accentGold' : 'fill-transparent text-surfaceHover'} transition-colors duration-200`} />
            
          </motion.button>);

      })}
      {!interactive &&
      <span className="ml-2 text-sm font-medium text-gray-300">
          {rating.toFixed(1)}
        </span>
      }
    </div>);

}