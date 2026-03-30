import React from 'react';
interface BadgeProps {
  variant: 'new' | 'trending' | 'hd' | 'price';
  text: string;
  className?: string;
}
export function Badge({ variant, text, className = '' }: BadgeProps) {
  const baseClasses =
  'px-2 py-0.5 text-xs font-bold rounded uppercase tracking-wider';
  const variants = {
    new: 'bg-accentGreen text-background',
    trending: 'bg-gradient-to-r from-accent to-orange-500 text-white',
    hd: 'bg-accentCyan text-background',
    price: 'bg-surfaceHover text-accentGreen border border-accentGreen/30'
  };
  return (
    <span className={`${baseClasses} ${variants[variant]} ${className}`}>
      {text}
    </span>);

}