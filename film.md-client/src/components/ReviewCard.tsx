import { Review } from '../types';
import { StarRating } from './StarRating';

interface ReviewCardProps {
  review: Review;
}

export function ReviewCard({ review }: ReviewCardProps) {
  return (
    <div className="glass-panel flex flex-col space-y-4 rounded-xl p-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center space-x-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-surfaceHover to-gray-600 font-bold text-white">
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

      <p className="text-sm leading-relaxed text-gray-300">{review.comment}</p>
    </div>
  );
}
