import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

interface Props {
  startsAt: string; // ISO 8601
  title?: string;
  onLive?: () => void;
}

interface Remaining {
  days: number;
  hours: number;
  minutes: number;
  seconds: number;
  total: number;
}

function compute(target: Date): Remaining {
  const now = Date.now();
  const total = Math.max(0, target.getTime() - now);
  const seconds = Math.floor((total / 1000) % 60);
  const minutes = Math.floor((total / (1000 * 60)) % 60);
  const hours = Math.floor((total / (1000 * 60 * 60)) % 24);
  const days = Math.floor(total / (1000 * 60 * 60 * 24));
  return { days, hours, minutes, seconds, total };
}

export function PremiereCountdown({ startsAt, title, onLive }: Props) {
  const { t } = useTranslation();
  const target = new Date(startsAt);
  const [remaining, setRemaining] = useState<Remaining>(() => compute(target));

  useEffect(() => {
    const id = window.setInterval(() => {
      const next = compute(target);
      setRemaining(next);
      if (next.total === 0) {
        window.clearInterval(id);
        onLive?.();
      }
    }, 1000);
    return () => window.clearInterval(id);
  }, [startsAt]);

  if (remaining.total === 0) {
    return (
      <div className="px-3 py-1 rounded-full bg-emerald-500/20 text-emerald-300 text-xs font-medium">
        ● {t('premiere.live_now')}
      </div>
    );
  }

  return (
    <div className="rounded-lg border border-white/10 bg-black/40 p-4 backdrop-blur">
      {title && <div className="text-xs text-white/60 mb-2">{title}</div>}
      <div className="text-xs uppercase tracking-wide text-white/50 mb-2">
        {t('premiere.countdown_to')}
      </div>
      <div className="grid grid-cols-4 gap-3">
        {[
          { v: remaining.days, l: t('premiere.days') },
          { v: remaining.hours, l: t('premiere.hours') },
          { v: remaining.minutes, l: t('premiere.minutes') },
          { v: remaining.seconds, l: t('premiere.seconds') },
        ].map((part, idx) => (
          <div key={idx} className="text-center">
            <div className="text-3xl font-bold tabular-nums">
              {String(part.v).padStart(2, '0')}
            </div>
            <div className="text-[10px] uppercase tracking-wider text-white/50">{part.l}</div>
          </div>
        ))}
      </div>
    </div>
  );
}
