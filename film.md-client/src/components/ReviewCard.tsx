import React from 'react';
import { Review } from '../types';
import { StarRating } from './StarRating';
interface ReviewCardProps {
  review: Review;
}
export function ReviewCard({ review }: ReviewCardProps) {
  return (
    <div className="glass-panel p-6 rounded-xl flex flex-col space-y-4">
      <div className="flex items-center justify-between">
        <div className="flex items-center space-x-3">
          <div className="w-10 h-10 rounded-full bg-gradient-to-br from-surfaceHover to-gray-600 flex items-center justify-center font-bold text-white">
            {review.userAvatar}
          </div>
          <div>
            <h4 className="font-medium text-white">{review.userName}</h4>
            <p className="text-xs text-gray-400">
              {new Date(review.date).toLocaleDateString()}
            </p>
          </div>
        </div>
        <StarRating rating={review.rating} size="sm" />
      </div>

      <p className="text-gray-300 text-sm leading-relaxed">{review.comment}</p>
    </div>);

}
