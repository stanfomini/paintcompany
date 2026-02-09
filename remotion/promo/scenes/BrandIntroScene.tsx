import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, SPRING_CONFIGS } from '../brand';
import { PromoVideoProps } from '../types';

export const BrandIntroScene: React.FC<{ data: PromoVideoProps }> = ({ data }) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Logo icon scale
  const iconScale = spring({ frame, fps, config: SPRING_CONFIGS.bouncy });
  // Company name
  const nameOpacity = interpolate(frame, [15, 35], [0, 1], { extrapolateRight: 'clamp' });
  const nameSlide = interpolate(frame, [15, 35], [30, 0], { extrapolateRight: 'clamp' });
  // Tagline
  const taglineOpacity = interpolate(frame, [40, 60], [0, 1], { extrapolateRight: 'clamp' });
  const taglineSlide = interpolate(frame, [40, 60], [20, 0], { extrapolateRight: 'clamp' });
  // City badge
  const badgeScale = spring({ frame: frame - 65, fps, config: SPRING_CONFIGS.snappy });
  // Scene fade out
  const fadeOut = interpolate(frame, [durationInFrames - 15, durationInFrames], [1, 0], {
    extrapolateLeft: 'clamp',
  });

  return (
    <AbsoluteFill
      style={{
        background: `linear-gradient(135deg, ${BRAND.navy} 0%, #0F1A2E 50%, ${BRAND.navy} 100%)`,
        opacity: fadeOut,
      }}
    >
      {/* Gradient accent circles */}
      <div
        style={{
          position: 'absolute',
          top: -200,
          right: -200,
          width: 600,
          height: 600,
          borderRadius: '50%',
          background: `radial-gradient(circle, ${BRAND.blue}30, transparent 70%)`,
        }}
      />
      <div
        style={{
          position: 'absolute',
          bottom: -300,
          left: -200,
          width: 800,
          height: 800,
          borderRadius: '50%',
          background: `radial-gradient(circle, ${BRAND.sky}15, transparent 70%)`,
        }}
      />

      <AbsoluteFill style={{ justifyContent: 'center', alignItems: 'center' }}>
        {/* Logo icon */}
        <div
          style={{
            transform: `scale(${iconScale})`,
            marginBottom: 30,
          }}
        >
          <div
            style={{
              width: 100,
              height: 100,
              borderRadius: 24,
              background: `linear-gradient(135deg, ${BRAND.blue}, ${BRAND.sky})`,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              boxShadow: `0 10px 40px ${BRAND.blue}60`,
            }}
          >
            {/* Paint brush icon */}
            <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke={BRAND.white} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <path d="M18.37 2.63 14 7l-1.59-1.59a2 2 0 0 0-2.82 0L8 7l9 9 1.59-1.59a2 2 0 0 0 0-2.82L17 10l4.37-4.37a2.12 2.12 0 1 0-3-3Z" />
              <path d="M9 8c-2 3-4 3.5-7 4l8 10c2-1 6-5 6-7" />
              <path d="M14.5 17.5 4.5 15" />
            </svg>
          </div>
        </div>

        {/* Company name */}
        <div
          style={{
            opacity: nameOpacity,
            transform: `translateY(${nameSlide}px)`,
            textAlign: 'center',
          }}
        >
          <h1
            style={{
              fontSize: 72,
              fontWeight: 800,
              color: BRAND.white,
              margin: 0,
              fontFamily: FONT.system,
              letterSpacing: -1,
              textTransform: 'uppercase',
            }}
          >
            {data.companyName}
          </h1>
        </div>

        {/* Tagline */}
        <div
          style={{
            opacity: taglineOpacity,
            transform: `translateY(${taglineSlide}px)`,
            marginTop: 16,
          }}
        >
          <p
            style={{
              fontSize: 32,
              fontWeight: 300,
              color: BRAND.sky,
              margin: 0,
              fontFamily: FONT.system,
              letterSpacing: 4,
              textTransform: 'uppercase',
            }}
          >
            {data.tagline}
          </p>
        </div>

        {/* City badge */}
        <div
          style={{
            transform: `scale(${Math.max(0, badgeScale)})`,
            marginTop: 50,
          }}
        >
          <div
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: 10,
              backgroundColor: 'rgba(255,255,255,0.1)',
              borderRadius: 30,
              padding: '12px 28px',
              border: '1px solid rgba(255,255,255,0.15)',
            }}
          >
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke={BRAND.sky} strokeWidth="2">
              <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
              <circle cx="12" cy="10" r="3" />
            </svg>
            <span
              style={{
                fontSize: 20,
                fontWeight: 500,
                color: BRAND.white,
                fontFamily: FONT.system,
              }}
            >
              {data.city}, {data.state}
            </span>
          </div>
        </div>
      </AbsoluteFill>
    </AbsoluteFill>
  );
};
