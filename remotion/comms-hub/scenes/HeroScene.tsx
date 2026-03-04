import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, TEXT, SPRING_CONFIGS } from '../brand';
import { CommsHubProps } from '../types';
import { FloatingParticles } from '../components/FloatingParticles';

export const HeroScene: React.FC<{ data: CommsHubProps }> = ({ data }) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Hub icon scales in
  const iconScale = spring({ frame: frame - 10, fps, config: SPRING_CONFIGS.bouncy });

  // Title slides up
  const titleOpacity = interpolate(frame, [25, 45], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });
  const titleSlide = interpolate(frame, [25, 45], [50, 0], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });

  // Tagline
  const tagOpacity = interpolate(frame, [55, 75], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });
  const tagSlide = interpolate(frame, [55, 75], [30, 0], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });

  // Company name badge
  const badgeOpacity = interpolate(frame, [0, 15], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });

  // Gradient ring rotation
  const ringRotation = frame * 1.2;

  // Fade out
  const fadeOut = interpolate(frame, [durationInFrames - 15, durationInFrames], [1, 0], { extrapolateLeft: 'clamp' });

  return (
    <AbsoluteFill
      style={{
        background: `linear-gradient(135deg, ${BRAND.dark} 0%, ${BRAND.navy} 40%, #1a2744 100%)`,
        opacity: fadeOut,
      }}
    >
      <FloatingParticles count={25} color={`${BRAND.blue}20`} seed={100} />

      {/* Company badge top-left */}
      <div
        style={{
          position: 'absolute',
          top: 40,
          left: 50,
          opacity: badgeOpacity,
          display: 'flex',
          alignItems: 'center',
          gap: 12,
        }}
      >
        <div
          style={{
            width: 40,
            height: 40,
            borderRadius: 10,
            backgroundColor: BRAND.blue,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <span style={{ color: BRAND.white, fontWeight: 800, fontSize: 18, fontFamily: FONT.system }}>A</span>
        </div>
        <span style={{ color: `${BRAND.white}99`, fontSize: TEXT.label, fontFamily: FONT.system, fontWeight: 600 }}>
          {data.companyName}
        </span>
      </div>

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
        {/* Hub icon with rotating gradient ring */}
        <div
          style={{
            position: 'relative',
            width: 160,
            height: 160,
            transform: `scale(${Math.max(0, iconScale)})`,
            marginBottom: 40,
          }}
        >
          {/* Rotating ring */}
          <div
            style={{
              position: 'absolute',
              inset: -8,
              borderRadius: '50%',
              background: `conic-gradient(from ${ringRotation}deg, ${BRAND.blue}, ${BRAND.sky}, ${BRAND.purple}, ${BRAND.amber}, ${BRAND.green}, ${BRAND.blue})`,
              opacity: 0.6,
            }}
          />
          {/* Inner circle */}
          <div
            style={{
              position: 'absolute',
              inset: 4,
              borderRadius: '50%',
              backgroundColor: BRAND.dark,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
            }}
          >
            {/* Hub icon - 4 converging arrows */}
            <svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke={BRAND.sky} strokeWidth="1.8" strokeLinecap="round">
              <circle cx="12" cy="12" r="3" fill={`${BRAND.sky}30`} />
              <path d="M12 2v4M12 18v4M2 12h4M18 12h4" />
              <path d="m4.93 4.93 2.83 2.83M16.24 16.24l2.83 2.83M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83" />
            </svg>
          </div>
        </div>

        {/* Title */}
        <div
          style={{
            opacity: titleOpacity,
            transform: `translateY(${titleSlide}px)`,
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
            Communications{' '}
            <span
              style={{
                background: `linear-gradient(135deg, ${BRAND.sky}, ${BRAND.blue})`,
                WebkitBackgroundClip: 'text',
                WebkitTextFillColor: 'transparent',
              }}
            >
              Hub
            </span>
          </h1>
        </div>

        {/* Tagline */}
        <div
          style={{
            opacity: tagOpacity,
            transform: `translateY(${tagSlide}px)`,
            marginTop: 20,
          }}
        >
          <p
            style={{
              fontSize: TEXT.subheading,
              color: `${BRAND.white}bb`,
              margin: 0,
              fontFamily: FONT.system,
              fontWeight: 500,
              letterSpacing: 0.5,
            }}
          >
            {data.tagline}
          </p>
        </div>
      </div>
    </AbsoluteFill>
  );
};
