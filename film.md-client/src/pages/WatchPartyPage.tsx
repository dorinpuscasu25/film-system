import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useParams } from 'react-router-dom';
import { PremiereCountdown } from '../components/PremiereCountdown';

interface PartyState {
  id: number;
  title: string;
  room_code: string;
  status: string;
  scheduled_start_at: string | null;
  actual_start_at: string | null;
  current_position_seconds: number;
  chat_enabled: boolean;
  content: { id: number | null; slug: string | null; title: string | null };
}

interface ChatMessage {
  id: number;
  display_name: string;
  body: string;
  sent_at: string | null;
}

const API_BASE = (import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api/v1') as string;

export function WatchPartyPage() {
  const { t } = useTranslation();
  const { roomCode } = useParams<{ roomCode: string }>();
  const [party, setParty] = useState<PartyState | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [displayName, setDisplayName] = useState('');
  const [joined, setJoined] = useState(false);
  const [chat, setChat] = useState<ChatMessage[]>([]);
  const [chatInput, setChatInput] = useState('');
  const lastIdRef = useRef(0);
  const authToken =
    (typeof window !== 'undefined' ? window.localStorage.getItem('storefront_token') : null) ?? null;

  async function fetchParty() {
    if (!roomCode) return;
    try {
      const res = await fetch(`${API_BASE}/public/watch-parties/${roomCode}`);
      if (!res.ok) {
        setError('Watch party not found');
        return;
      }
      const data = (await res.json()) as PartyState;
      setParty(data);
    } catch {
      setError('Network error');
    }
  }

  async function fetchChat() {
    if (!roomCode) return;
    try {
      const res = await fetch(
        `${API_BASE}/public/watch-parties/${roomCode}/chat?after_id=${lastIdRef.current}`,
      );
      if (!res.ok) return;
      const data = (await res.json()) as { items: ChatMessage[] };
      if (data.items.length > 0) {
        setChat((prev) => [...prev, ...data.items]);
        lastIdRef.current = data.items[data.items.length - 1].id;
      }
    } catch {
      /* ignore */
    }
  }

  useEffect(() => {
    void fetchParty();
    const partyTimer = window.setInterval(fetchParty, 5000);
    const chatTimer = window.setInterval(fetchChat, 3000);
    return () => {
      window.clearInterval(partyTimer);
      window.clearInterval(chatTimer);
    };
  }, [roomCode]);

  async function join() {
    if (!roomCode || !displayName) return;
    const res = await fetch(`${API_BASE}/storefront/watch-parties/${roomCode}/join`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        ...(authToken ? { Authorization: `Bearer ${authToken}` } : {}),
      },
      body: JSON.stringify({ display_name: displayName }),
    });
    if (res.ok) {
      setJoined(true);
    }
  }

  async function sendChat() {
    if (!roomCode || !chatInput || !displayName) return;
    await fetch(`${API_BASE}/storefront/watch-parties/${roomCode}/chat`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        ...(authToken ? { Authorization: `Bearer ${authToken}` } : {}),
      },
      body: JSON.stringify({ display_name: displayName, body: chatInput }),
    });
    setChatInput('');
    void fetchChat();
  }

  if (error) return <div className="p-12 text-center text-white/70">{error}</div>;
  if (!party) return <div className="p-12 text-center text-white/70">{t('common.loading')}…</div>;

  return (
    <div className="min-h-screen bg-black text-white p-6">
      <div className="max-w-6xl mx-auto grid grid-cols-1 lg:grid-cols-[2fr_1fr] gap-6">
        <main className="space-y-4">
          <div>
            <div className="text-xs uppercase tracking-wide text-white/50">
              {t('watch_party.title')}
            </div>
            <h1 className="text-3xl font-bold">{party.title}</h1>
            <div className="text-white/60">{party.content.title}</div>
          </div>

          {party.status === 'scheduled' && party.scheduled_start_at && (
            <PremiereCountdown startsAt={party.scheduled_start_at} title={t('watch_party.starts_in')} />
          )}

          {party.status === 'live' && (
            <div className="aspect-video bg-zinc-900 rounded-xl flex items-center justify-center">
              <div className="text-center">
                <div className="text-2xl font-bold">▶ {t('watch_party.title')}</div>
                <div className="text-sm text-white/60 mt-2">
                  {t('watch_party.live_position', { seconds: party.current_position_seconds })}
                </div>
              </div>
            </div>
          )}

          {party.status === 'ended' && (
            <div className="text-center py-12 text-white/50">{t('watch_party.ended')}</div>
          )}

          {!joined && party.status !== 'ended' && (
            <div className="rounded-lg border border-white/10 bg-white/5 p-4 space-y-3">
              <input
                placeholder={t('watch_party.your_name')}
                value={displayName}
                onChange={(e) => setDisplayName(e.target.value)}
                className="w-full bg-white/5 border border-white/15 rounded px-3 py-2"
              />
              <button
                onClick={() => void join()}
                disabled={!displayName}
                className="w-full bg-white text-black font-semibold rounded px-4 py-2 disabled:opacity-50"
              >
                {t('watch_party.join')}
              </button>
            </div>
          )}
        </main>

        <aside className="bg-zinc-900 rounded-xl p-4 flex flex-col h-[600px]">
          <div className="text-sm font-medium text-white/70 mb-3">Chat</div>
          <div className="flex-1 overflow-y-auto space-y-2 mb-3">
            {chat.map((m) => (
              <div key={m.id} className="text-sm">
                <span className="text-white/50">{m.display_name}: </span>
                <span>{m.body}</span>
              </div>
            ))}
          </div>
          {joined && party.chat_enabled && (
            <div className="flex gap-2">
              <input
                value={chatInput}
                onChange={(e) => setChatInput(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') void sendChat();
                }}
                placeholder={t('watch_party.chat_placeholder')}
                className="flex-1 bg-white/5 border border-white/15 rounded px-3 py-2 text-sm"
              />
              <button onClick={() => void sendChat()} className="bg-white text-black px-4 py-2 rounded">
                {t('watch_party.send')}
              </button>
            </div>
          )}
        </aside>
      </div>
    </div>
  );
}
