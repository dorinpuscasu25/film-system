import { Review } from '../types';
import { PencilIcon, Trash2Icon } from 'lucide-react';
import { StarRating } from './StarRating';

interface ReviewCardProps {
  review: Review;
  onDelete?: () => void;
  onEdit?: () => void;
  isDeleting?: boolean;
  deleteLabel?: string;
  editLabel?: string;
}

export function ReviewCard({
  review,
  onDelete,
  onEdit,
  isDeleting = false,
  deleteLabel = 'Delete review',
  editLabel = 'Edit review',
}: ReviewCardProps) {
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
        <div className="flex items-center gap-3">
          <StarRating rating={review.rating} size="sm" />
          {onEdit ? (
            <button
              type="button"
              onClick={onEdit}
              aria-label={editLabel}
              title={editLabel}
              className="rounded-full p-2 text-gray-300 transition hover:bg-white/10 hover:text-white"
            >
              <PencilIcon className="h-4 w-4" />
            </button>
          ) : null}
          {onDelete ? (
            <button
              type="button"
              onClick={onDelete}
              disabled={isDeleting}
              aria-label={deleteLabel}
              title={deleteLabel}
              className="rounded-full p-2 text-red-400 transition hover:bg-red-500/15 hover:text-red-300 disabled:opacity-50"
            >
              <Trash2Icon className="h-4 w-4" />
            </button>
          ) : null}
        </div>
      </div>

      <p className="text-sm leading-relaxed text-gray-300">{review.comment}</p>
    </div>
  );
}
