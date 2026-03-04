import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, TEXT, SPRING_CONFIGS } from '../brand';

export const TheAnswerScene: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Central icon punches in
  const iconScale = spring({ frame: frame - 5, fps, config: SPRING_CONFIGS.punch });

  // Ring burst
  const ringScale = interpolate(frame, [5, 40], [0, 3.5], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });
  const ringOpacity = interpolate(frame, [5, 40], [0.6, 0], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });

  // Text reveals
  const textOpacity = interpolate(frame, [20, 40], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });
  const textScale = spring({ frame: frame - 20, fps, config: SPRING_CONFIGS.snappy });

  // Subtitle
  const subOpacity = interpolate(frame, [50, 70], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });
  const subSlide = interpolate(frame, [50, 70], [20, 0], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });

  // Glow pulse
  const glowPulse = 0.3 + Math.sin(frame * 0.08) * 0.15;

  const fadeOut = interpolate(frame, [durationInFrames - 15, durationInFrames], [1, 0], { extrapolateLeft: 'clamp' });

  return (
    <AbsoluteFill
      style={{
        background: `radial-gradient(ellipse at center, ${BRAND.navy} 0%, ${BRAND.dark} 70%)`,
        opacity: fadeOut,
      }}
    >
      {/* Glow behind icon */}
      <div
        style={{
          position: 'absolute',
          top: '50%',
          left: '50%',
          width: 400,
          height: 400,
          transform: 'translate(-50%, -60%)',
          borderRadius: '50%',
          background: `radial-gradient(circle, ${BRAND.blue}${Math.round(glowPulse * 255).toString(16).padStart(2, '0')} 0%, transparent 70%)`,
        }}
      />

      {/* Expanding ring */}
      <div
        style={{
          position: 'absolute',
          top: '50%',
          left: '50%',
          width: 120,
          height: 120,
          transform: `translate(-50%, -60%) scale(${ringScale})`,
          borderRadius: '50%',
          border: `3px solid ${BRAND.sky}`,
          opacity: ringOpacity,
        }}
      />

      {/* Center content */}
      <div
        style={{
          position: 'absolute',
          top: 0,
          left: 0,
          right: 0,
          bottom: 0,
          display: 'flex',
          flexDirection: 'column',
          alignItems: 'center',
          justifyContent: 'center',
        }}
      >
        {/* Check icon */}
        <div
          style={{
            width: 120,
            height: 120,
            borderRadius: '50%',
            background: `linear-gradient(135deg, ${BRAND.blue}, ${BRAND.sky})`,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            transform: `scale(${Math.max(0, iconScale)})`,
            marginBottom: 40,
            boxShadow: `0 0 60px ${BRAND.blue}40`,
          }}
        >
          <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke={BRAND.white} strokeWidth="3" strokeLinecap="round">
            <path d="M12 2v4M12 18v4M2 12h4M18 12h4" />
            <circle cx="12" cy="12" r="3" />
          </svg>
        </div>

        {/* Main text */}
        <div
          style={{
            opacity: textOpacity,
            transform: `scale(${Math.max(0, textScale)})`,
            textAlign: 'center',
          }}
        >
          <h2
            style={{
              fontSize: TEXT.hero,
              fontWeight: 800,
              color: BRAND.white,
              margin: 0,
              fontFamily: FONT.system,
              letterSpacing: -2,
            }}
          >
            One Unified{' '}
            <span
              style={{
                background: `linear-gradient(135deg, ${BRAND.sky}, ${BRAND.green})`,
                WebkitBackgroundClip: 'text',
                WebkitTextFillColor: 'transparent',
              }}
            >
              Inbox
            </span>
          </h2>
        </div>

        {/* Subtitle */}
        <div
          style={{
            opacity: subOpacity,
            transform: `translateY(${subSlide}px)`,
            marginTop: 16,
          }}
        >
          <p
            style={{
              fontSize: TEXT.subheading,
              color: `${BRAND.white}aa`,
              margin: 0,
              fontFamily: FONT.system,
              fontWeight: 500,
            }}
          >
            Chat. Email. Messenger. All in one place.
          </p>
        </div>
      </div>
    </AbsoluteFill>
  );
};
