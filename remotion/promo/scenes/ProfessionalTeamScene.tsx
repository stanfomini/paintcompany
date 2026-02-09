import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, SPRING_CONFIGS, STAGGER_OFFSET } from '../brand';
import { PromoVideoProps } from '../types';
import { FloatingParticles } from '../components/FloatingParticles';

interface TrustBadge {
  title: string;
  subtitle: string;
  icon: React.ReactNode;
  color: string;
}

const badges: TrustBadge[] = [
  {
    title: 'Licensed & Insured',
    subtitle: 'Fully bonded for your protection',
    color: BRAND.blue,
    icon: (
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
        <path d="m9 12 2 2 4-4" />
      </svg>
    ),
  },
  {
    title: '5-Star Rated',
    subtitle: 'Trusted by Denver homeowners',
    color: BRAND.amber,
    icon: (
      <svg width="48" height="48" viewBox="0 0 24 24" fill="currentColor" stroke="none">
        <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
      </svg>
    ),
  },
  {
    title: 'Satisfaction Guaranteed',
    subtitle: "We're not done until you love it",
    color: BRAND.green,
    icon: (
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3H14z" />
        <path d="M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3" />
      </svg>
    ),
  },
];

export const ProfessionalTeamScene: React.FC<{ data: PromoVideoProps }> = ({ data }) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Title
  const titleOpacity = interpolate(frame, [0, 20], [0, 1], { extrapolateRight: 'clamp' });
  const titleSlide = interpolate(frame, [0, 20], [-30, 0], { extrapolateRight: 'clamp' });

  // Badges stagger
  const badgeStart = 40;

  // Divider line
  const dividerWidth = interpolate(frame, [30, 50], [0, 400], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // Bottom tagline
  const taglineFrame = 140;
  const taglineOpacity = interpolate(frame, [taglineFrame, taglineFrame + 20], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });
  const taglineSlide = interpolate(frame, [taglineFrame, taglineFrame + 20], [20, 0], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // Fade
  const fadeOut = interpolate(frame, [durationInFrames - 15, durationInFrames], [1, 0], {
    extrapolateLeft: 'clamp',
  });

  return (
    <AbsoluteFill
      style={{
        background: `linear-gradient(180deg, ${BRAND.navy} 0%, #0F1A2E 100%)`,
        opacity: fadeOut,
      }}
    >
      <FloatingParticles count={18} color="rgba(255,255,255,0.05)" seed={33} />

      {/* Title */}
      <div
        style={{
          position: 'absolute',
          top: 80,
          left: 0,
          right: 0,
          textAlign: 'center',
          opacity: titleOpacity,
          transform: `translateY(${titleSlide}px)`,
        }}
      >
        <h2 style={{ fontSize: 52, fontWeight: 700, color: BRAND.white, margin: 0, fontFamily: FONT.system }}>
          Why Choose Us?
        </h2>
      </div>

      {/* Divider */}
      <div
        style={{
          position: 'absolute',
          top: 170,
          left: '50%',
          transform: 'translateX(-50%)',
          width: dividerWidth,
          height: 3,
          background: `linear-gradient(90deg, transparent, ${BRAND.sky}, transparent)`,
          borderRadius: 2,
        }}
      />

      {/* Trust badges */}
      <div
        style={{
          position: 'absolute',
          top: 240,
          left: 0,
          right: 0,
          display: 'flex',
          justifyContent: 'center',
          gap: 50,
        }}
      >
        {badges.map((badge, i) => {
          const cardScale = spring({
            frame: frame - (badgeStart + i * STAGGER_OFFSET),
            fps,
            config: SPRING_CONFIGS.snappy,
          });
          const cardSlide = interpolate(
            frame,
            [badgeStart + i * STAGGER_OFFSET, badgeStart + i * STAGGER_OFFSET + 25],
            [40, 0],
            { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' }
          );

          return (
            <div
              key={badge.title}
              style={{
                width: 320,
                transform: `scale(${Math.max(0, cardScale)}) translateY(${cardSlide}px)`,
                textAlign: 'center',
              }}
            >
              {/* Icon circle */}
              <div
                style={{
                  width: 100,
                  height: 100,
                  borderRadius: '50%',
                  backgroundColor: `${badge.color}20`,
                  border: `2px solid ${badge.color}40`,
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  margin: '0 auto 20px',
                  color: badge.color,
                }}
              >
                {badge.icon}
              </div>

              {/* Title */}
              <h3
                style={{
                  fontSize: 28,
                  fontWeight: 700,
                  color: BRAND.white,
                  margin: '0 0 8px',
                  fontFamily: FONT.system,
                }}
              >
                {badge.title}
              </h3>

              {/* Subtitle */}
              <p
                style={{
                  fontSize: 18,
                  color: 'rgba(255,255,255,0.6)',
                  margin: 0,
                  fontFamily: FONT.system,
                  lineHeight: 1.4,
                }}
              >
                {badge.subtitle}
              </p>
            </div>
          );
        })}
      </div>

      {/* Bottom tagline */}
      <div
        style={{
          position: 'absolute',
          bottom: 100,
          left: 0,
          right: 0,
          textAlign: 'center',
          opacity: taglineOpacity,
          transform: `translateY(${taglineSlide}px)`,
        }}
      >
        <p
          style={{
            fontSize: 26,
            fontWeight: 400,
            color: BRAND.sky,
            margin: 0,
            fontFamily: FONT.system,
            fontStyle: 'italic',
          }}
        >
          "Trusted by hundreds of homeowners across the Front Range"
        </p>
      </div>
    </AbsoluteFill>
  );
};
