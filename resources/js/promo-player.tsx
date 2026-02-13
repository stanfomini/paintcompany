import React, { useRef, useState, useCallback, useEffect } from 'react';
import { createRoot } from 'react-dom/client';
import { Player, PlayerRef } from '@remotion/player';
import { PromoVideo } from '../../remotion/promo/PromoVideo';
import { defaultPromoProps, PromoVideoProps } from '../../remotion/promo/types';
import { TOTAL_FRAMES, FPS } from '../../remotion/promo/brand';

const SpeakerIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5" />
    <path d="M15.54 8.46a5 5 0 0 1 0 7.07" />
    <path d="M19.07 4.93a10 10 0 0 1 0 14.14" />
  </svg>
);

const MutedIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5" />
    <line x1="23" y1="9" x2="17" y2="15" />
    <line x1="17" y1="9" x2="23" y2="15" />
  </svg>
);

const PromoPlayerApp: React.FC = () => {
  const container = document.getElementById('promo-player-root');
  const dataAttr = container?.getAttribute('data-props');
  const playerRef = useRef<PlayerRef>(null);
  const [isMuted, setIsMuted] = useState(true);
  const [showPulse, setShowPulse] = useState(true);

  let props: PromoVideoProps = defaultPromoProps;
  if (dataAttr) {
    try {
      props = { ...defaultPromoProps, ...JSON.parse(dataAttr) };
    } catch {
      // Use defaults
    }
  }

  const toggleMute = useCallback(() => {
    const player = playerRef.current;
    if (!player) return;

    if (isMuted) {
      player.unmute();
      setIsMuted(false);
      setShowPulse(false);
    } else {
      player.mute();
      setIsMuted(true);
    }
  }, [isMuted]);

  // Fade out the pulse hint after 6 seconds
  useEffect(() => {
    const timer = setTimeout(() => setShowPulse(false), 6000);
    return () => clearTimeout(timer);
  }, []);

  return (
    <div style={{ width: '100%', position: 'relative' }}>
      <Player
        ref={playerRef}
        component={PromoVideo}
        inputProps={props}
        durationInFrames={TOTAL_FRAMES}
        fps={FPS}
        compositionWidth={1920}
        compositionHeight={1080}
        style={{
          width: '100%',
          aspectRatio: '16 / 9',
          borderRadius: 16,
        }}
        controls
        autoPlay
        loop
        initiallyMuted
      />

      {/* Audio toggle overlay - top right */}
      <button
        onClick={toggleMute}
        style={{
          position: 'absolute',
          top: 16,
          right: 16,
          display: 'flex',
          alignItems: 'center',
          gap: 8,
          padding: isMuted ? '10px 18px' : '10px 14px',
          borderRadius: 50,
          border: 'none',
          cursor: 'pointer',
          backgroundColor: isMuted ? 'rgba(255,255,255,0.95)' : 'rgba(255,255,255,0.85)',
          color: '#1B2D4F',
          fontSize: 14,
          fontWeight: 600,
          fontFamily: 'system-ui, -apple-system, sans-serif',
          boxShadow: '0 4px 20px rgba(0,0,0,0.25)',
          transition: 'all 0.2s ease',
          zIndex: 50,
          animation: showPulse && isMuted ? 'audioHint 2s ease-in-out infinite' : 'none',
        }}
        aria-label={isMuted ? 'Turn on sound' : 'Mute sound'}
      >
        {isMuted ? <MutedIcon /> : <SpeakerIcon />}
        {isMuted && <span>Turn on sound</span>}
      </button>

      {/* Inline keyframes for the pulse animation */}
      <style>{`
        @keyframes audioHint {
          0%, 100% { transform: scale(1); box-shadow: 0 4px 20px rgba(0,0,0,0.25); }
          50% { transform: scale(1.05); box-shadow: 0 4px 24px rgba(37,99,235,0.4); }
        }
      `}</style>
    </div>
  );
};

// Mount
const container = document.getElementById('promo-player-root');
if (container) {
  const root = createRoot(container);
  root.render(<PromoPlayerApp />);
}
