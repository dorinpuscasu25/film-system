import React from 'react';
import { motion } from 'framer-motion';
import { Edit2Icon } from 'lucide-react';
import { UserProfile } from '../types';
interface ProfileAvatarProps {
  profile: UserProfile;
  size?: 'sm' | 'md' | 'lg' | 'xl';
  onClick?: () => void;
  editable?: boolean;
}
export function ProfileAvatar({
  profile,
  size = 'md',
  onClick,
  editable = false
}: ProfileAvatarProps) {
  const sizeClasses = {
    sm: 'w-8 h-8 text-sm',
    md: 'w-12 h-12 text-lg',
    lg: 'w-24 h-24 text-3xl',
    xl: 'w-32 h-32 text-5xl'
  };
  return (
    <motion.div
      className="relative flex flex-col items-center group cursor-pointer"
      onClick={onClick}
      whileHover={{
        scale: 1.05
      }}
      whileTap={{
        scale: 0.95
      }}>
      
      <div
        className={`
        ${sizeClasses[size]} 
        rounded-full flex items-center justify-center font-bold text-white shadow-lg
        bg-gradient-to-br ${profile.color}
        border-2 border-transparent group-hover:border-white transition-all duration-300
        overflow-hidden relative
      `}>
        
        {profile.avatarUrl.length === 1 ?
        profile.avatarUrl :

        <img
          src={profile.avatarUrl}
          alt={profile.name}
          className="w-full h-full object-cover" />

        }

        {editable &&
        <div className="absolute inset-0 bg-black/50 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
            <Edit2Icon className="w-1/3 h-1/3 text-white" />
          </div>
        }
      </div>

      {size === 'xl' &&
      <span className="mt-4 text-xl font-medium text-gray-300 group-hover:text-white transition-colors">
          {profile.name}
        </span>
      }
    </motion.div>);

}