import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, TEXT, SPRING_CONFIGS } from '../brand';
import { CustomerPortalProps } from '../types';

const features = [
  {
    icon: (
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round">
        <rect x="3" y="3" width="18" height="18" rx="3" />
        <path d="M3 9h18M9 21V9" />
      </svg>
    ),
    label: 'Customer Dashboard',
    color: BRAND.blue,
  },
  {
    icon: (
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
        <polyline points="14 2 14 8 20 8" />
        <line x1="16" y1="13" x2="8" y2="13" />
        <line x1="16" y1="17" x2="8" y2="17" />
        <polyline points="10 9 9 9 8 9" />
      </svg>
    ),
    label: 'Instant Estimates',
    color: BRAND.green,
  },
  {
    icon: (
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round">
        <polyline points="22 12 18 12 15 21 9 3 6 12 2 12" />
      </svg>
    ),
    label: 'Live Job Tracking',
    color: BRAND.amber,
  },
];

export const HeroScene: React.FC<{ data: CustomerPortalProps }> = ({ data }) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Background glow pulse
  const glowPulse = Math.sin(frame * 0.05) * 0.1 + 0.9;

  // Main title
  const titleScale = spring({ frame: frame - 5, fps, config: SPRING_CONFIGS.bouncy });
  const titleOpacity = interpolate(frame, [5, 25], [0, 1], { extrapolateRight: 'clamp' });

  // Subtitle
  const subtitleOpacity = interpolate(frame, [30, 50], [0, 1], { extrapolateRight: 'clamp' });
  const subtitleSlide = interpolate(frame, [30, 50], [20, 0], { extrapolateRight: 'clamp' });

  // Feature pills stagger
  const featureOpacities = features.map((_, i) =>
    interpolate(frame, [55 + i * 14, 75 + i * 14], [0, 1], { extrapolateRight: 'clamp' })
  );
  const featureScales = features.map((_, i) =>
    spring({ frame: frame - (55 + i * 14), fps, config: SPRING_CONFIGS.snappy })
  );

  // Fade out
  const fadeOut = interpolate(frame, [durationInFrames - 15, durationInFrames], [1, 0], {
    extrapolateLeft: 'clamp',
  });

  return (
    <AbsoluteFill
      style={{
        background: `linear-gradient(135deg, #0A0F1E 0%, #0F1A2E 40%, ${BRAND.navy} 100%)`,
        opacity: fadeOut,
      }}
    >
      {/* Animated grid lines */}
      <div
        style={{
          position: 'absolute',
          inset: 0,
          backgroundImage: `
            linear-gradient(${BRAND.blue}08 1px, transparent 1px),
            linear-gradient(90deg, ${BRAND.blue}08 1px, transparent 1px)
          `,
          backgroundSize: '80px 80px',
          transform: `translateY(${frame * 0.3}px)`,
        }}
      />

      {/* Center glow */}
      <div
        style={{
          position: 'absolute',
          top: '50%',
          left: '50%',
          transform: 'translate(-50%, -50%)',
          width: 900,
          height: 500,
          borderRadius: '50%',
          background: `radial-gradient(ellipse, ${BRAND.blue}22 0%, ${BRAND.purple}11 40%, transparent 70%)`,
          opacity: glowPulse,
        }}
      />

      {/* Top accent line */}
      <div
        style={{
          position: 'absolute',
          top: 0,
          left: 0,
          right: 0,
          height: 4,
          background: `linear-gradient(90deg, transparent, ${BRAND.blue}, ${BRAND.sky}, ${BRAND.green}, transparent)`,
        }}
      />

      <AbsoluteFill style={{ justifyContent: 'center', alignItems: 'center' }}>
        {/* Company name tag */}
        <div
          style={{
            opacity: subtitleOpacity,
            transform: `translateY(${-subtitleSlide - 60}px)`,
            marginBottom: 16,
          }}
        >
          <div
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: 10,
              backgroundColor: `${BRAND.blue}20`,
              border: `1px solid ${BRAND.blue}40`,
              borderRadius: 40,
              padding: '8px 24px',
            }}
          >
            <div style={{ width: 8, height: 8, borderRadius: '50%', backgroundColor: BRAND.green }} />
            <span style={{ fontSize: TEXT.small, fontWeight: 600, color: BRAND.sky, fontFamily: FONT.system, letterSpacing: 2, textTransform: 'uppercase' }}>
              {data.companyName}
            </span>
          </div>
        </div>

        {/* Main hero title */}
        <div
          style={{
            opacity: titleOpacity,
            transform: `scale(${Math.max(0, titleScale)})`,
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
              letterSpacing: -3,
              lineHeight: 1.05,
            }}
          >
            Your Project.
            <br />
            <span
              style={{
                background: `linear-gradient(135deg, ${BRAND.sky}, ${BRAND.blue})`,
                WebkitBackgroundClip: 'text',
                WebkitTextFillColor: 'transparent',
              }}
            >
              Always With You.
            </span>
          </h1>
        </div>

        {/* Subtitle */}
        <div
          style={{
            opacity: subtitleOpacity,
            transform: `translateY(${subtitleSlide}px)`,
            marginTop: 20,
            textAlign: 'center',
          }}
        >
          <p
            style={{
              fontSize: TEXT.label,
              fontWeight: 400,
              color: `${BRAND.white}80`,
              margin: 0,
              fontFamily: FONT.system,
            }}
          >
            See your estimate, track your job, and stay connected — all in one place.
          </p>
        </div>

        {/* Feature pills */}
        <div
          style={{
            display: 'flex',
            gap: 20,
            marginTop: 48,
          }}
        >
          {features.map((feat, i) => (
            <div
              key={feat.label}
              style={{
                opacity: featureOpacities[i],
                transform: `scale(${Math.max(0, featureScales[i])})`,
                display: 'flex',
                alignItems: 'center',
                gap: 12,
                backgroundColor: `${feat.color}18`,
                border: `2px solid ${feat.color}40`,
                borderRadius: 16,
                padding: '14px 24px',
              }}
            >
              <div style={{ color: feat.color }}>{feat.icon}</div>
              <span style={{ fontSize: TEXT.label, fontWeight: 600, color: BRAND.white, fontFamily: FONT.system }}>
                {feat.label}
              </span>
            </div>
          ))}
        </div>
      </AbsoluteFill>
    </AbsoluteFill>
  );
};
