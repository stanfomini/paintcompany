import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, TEXT, SPRING_CONFIGS } from '../brand';
import { PromoVideoProps } from '../types';

export const SolutionRevealScene: React.FC<{ data: PromoVideoProps }> = ({ data }) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Background transition from dark to bright
  const bgProgress = interpolate(frame, [0, 40], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // "What if" text
  const whatIfOpacity = interpolate(frame, [5, 25], [0, 1], { extrapolateRight: 'clamp' });
  const whatIfSlide = interpolate(frame, [5, 25], [40, 0], { extrapolateRight: 'clamp' });

  // Big reveal text
  const revealScale = spring({ frame: frame - 35, fps, config: SPRING_CONFIGS.bouncy });
  const revealOpacity = interpolate(frame, [35, 50], [0, 1], { extrapolateRight: 'clamp' });

  // Tagline
  const taglineOpacity = interpolate(frame, [65, 85], [0, 1], { extrapolateRight: 'clamp' });
  const taglineSlide = interpolate(frame, [65, 85], [20, 0], { extrapolateRight: 'clamp' });

  // Scene fade
  const fadeOut = interpolate(frame, [durationInFrames - 12, durationInFrames], [1, 0], {
    extrapolateLeft: 'clamp',
  });

  // Background color blend
  const bgColor = `linear-gradient(135deg,
    rgba(27, 45, 79, ${1 - bgProgress * 0.3}) 0%,
    rgba(37, 99, 235, ${bgProgress * 0.4}) 50%,
    rgba(16, 185, 129, ${bgProgress * 0.2}) 100%)`;

  return (
    <AbsoluteFill
      style={{
        background: bgColor,
        opacity: fadeOut,
      }}
    >
      {/* Radial glow */}
      <div
        style={{
          position: 'absolute',
          top: '50%',
          left: '50%',
          transform: 'translate(-50%, -50%)',
          width: 800,
          height: 800,
          borderRadius: '50%',
          background: `radial-gradient(circle, ${BRAND.blue}30, transparent 70%)`,
          opacity: bgProgress,
        }}
      />

      <AbsoluteFill style={{ justifyContent: 'center', alignItems: 'center' }}>
        {/* "What if..." */}
        <div
          style={{
            opacity: whatIfOpacity,
            transform: `translateY(${whatIfSlide}px)`,
            marginBottom: 20,
          }}
        >
          <p
            style={{
              fontSize: TEXT.subheading,
              fontWeight: 400,
              color: `${BRAND.white}99`,
              margin: 0,
              fontFamily: FONT.system,
              fontStyle: 'italic',
            }}
          >
            What if it didn't have to be this way?
          </p>
        </div>

        {/* Big reveal */}
        <div
          style={{
            opacity: revealOpacity,
            transform: `scale(${Math.max(0, revealScale)})`,
            textAlign: 'center',
          }}
        >
          <h1
            style={{
              fontSize: TEXT.hero,
              fontWeight: 800,
              color: BRAND.white,
              margin: 0,
              fontFamily: FONT.system,
              letterSpacing: -2,
              lineHeight: 1.1,
            }}
          >
            We Built A
            <br />
            <span style={{ color: BRAND.sky }}>Better Way</span>
          </h1>
        </div>

        {/* Tagline */}
        <div
          style={{
            opacity: taglineOpacity,
            transform: `translateY(${taglineSlide}px)`,
            marginTop: 30,
          }}
        >
          <div
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: 12,
              backgroundColor: `${BRAND.white}15`,
              borderRadius: 40,
              padding: '14px 36px',
              border: `1px solid ${BRAND.white}20`,
            }}
          >
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke={BRAND.green} strokeWidth="2.5" strokeLinecap="round">
              <path d="M20 6 9 17l-5-5" />
            </svg>
            <span
              style={{
                fontSize: TEXT.label,
                fontWeight: 600,
                color: BRAND.white,
                fontFamily: FONT.system,
              }}
            >
              {data.companyName} {data.tagline}
            </span>
          </div>
        </div>
      </AbsoluteFill>
    </AbsoluteFill>
  );
};
