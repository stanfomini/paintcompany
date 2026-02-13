import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, TEXT, SPRING_CONFIGS, STAGGER_OFFSET } from '../brand';
import { PromoVideoProps } from '../types';

interface PainPoint {
  text: string;
  icon: React.ReactNode;
  delay: number;
}

const XIcon = () => (
  <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke={BRAND.warmRed} strokeWidth="3" strokeLinecap="round">
    <path d="M18 6 6 18" />
    <path d="m6 6 12 12" />
  </svg>
);

const painPoints: PainPoint[] = [
  { text: 'Missed phone calls', icon: <XIcon />, delay: 30 },
  { text: 'Color confusion', icon: <XIcon />, delay: 48 },
  { text: 'Poor communication', icon: <XIcon />, delay: 66 },
  { text: 'Inaccurate estimates', icon: <XIcon />, delay: 84 },
  { text: 'Missed project scope', icon: <XIcon />, delay: 102 },
  { text: 'No clear plan', icon: <XIcon />, delay: 120 },
];

export const PainPointsScene: React.FC<{ data: PromoVideoProps }> = () => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Title animation
  const titleOpacity = interpolate(frame, [0, 20], [0, 1], { extrapolateRight: 'clamp' });
  const titleSlide = interpolate(frame, [0, 20], [-40, 0], { extrapolateRight: 'clamp' });

  // Subtitle streaks in after pain points
  const resultFrame = 145;
  const resultOpacity = interpolate(frame, [resultFrame, resultFrame + 20], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });
  const resultSlide = interpolate(frame, [resultFrame, resultFrame + 20], [30, 0], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // Background pulse - subtle red tint grows
  const bgPulse = interpolate(frame, [0, 140], [0, 0.15], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // Scene fade
  const fadeOut = interpolate(frame, [durationInFrames - 15, durationInFrames], [1, 0], {
    extrapolateLeft: 'clamp',
  });

  return (
    <AbsoluteFill
      style={{
        background: `linear-gradient(135deg, ${BRAND.dark} 0%, #1a1a2e 50%, ${BRAND.dark} 100%)`,
        opacity: fadeOut,
      }}
    >
      {/* Red overlay pulse */}
      <div
        style={{
          position: 'absolute',
          inset: 0,
          backgroundColor: BRAND.warmRed,
          opacity: bgPulse,
          pointerEvents: 'none',
        }}
      />

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
        <h2
          style={{
            fontSize: TEXT.heading,
            fontWeight: 800,
            color: BRAND.white,
            margin: 0,
            fontFamily: FONT.system,
            letterSpacing: -1,
          }}
        >
          Sound familiar?
        </h2>
      </div>

      {/* Pain points grid - 2 columns for big readable text */}
      <div
        style={{
          position: 'absolute',
          top: 250,
          left: 140,
          right: 140,
          display: 'grid',
          gridTemplateColumns: '1fr 1fr',
          gap: '36px 80px',
        }}
      >
        {painPoints.map((point, i) => {
          const pointScale = spring({
            frame: frame - point.delay,
            fps,
            config: SPRING_CONFIGS.punch,
          });
          const pointOpacity = interpolate(frame, [point.delay, point.delay + 10], [0, 1], {
            extrapolateLeft: 'clamp',
            extrapolateRight: 'clamp',
          });
          // Shake effect on appear
          const shake = frame >= point.delay && frame < point.delay + 8
            ? Math.sin((frame - point.delay) * 2.5) * 4
            : 0;

          return (
            <div
              key={i}
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: 20,
                opacity: pointOpacity,
                transform: `scale(${Math.max(0, pointScale)}) translateX(${shake}px)`,
                transformOrigin: 'left center',
              }}
            >
              <div
                style={{
                  width: 60,
                  height: 60,
                  borderRadius: '50%',
                  backgroundColor: `${BRAND.warmRed}25`,
                  border: `2px solid ${BRAND.warmRed}50`,
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  flexShrink: 0,
                }}
              >
                {point.icon}
              </div>
              <span
                style={{
                  fontSize: TEXT.subheading,
                  fontWeight: 700,
                  color: BRAND.softRed,
                  fontFamily: FONT.system,
                }}
              >
                {point.text}
              </span>
            </div>
          );
        })}
      </div>

      {/* Result text */}
      <div
        style={{
          position: 'absolute',
          bottom: 100,
          left: 0,
          right: 0,
          textAlign: 'center',
          opacity: resultOpacity,
          transform: `translateY(${resultSlide}px)`,
        }}
      >
        <p
          style={{
            fontSize: TEXT.subheading,
            fontWeight: 600,
            color: `${BRAND.white}cc`,
            margin: 0,
            fontFamily: FONT.system,
            lineHeight: 1.5,
            maxWidth: 900,
            marginLeft: 'auto',
            marginRight: 'auto',
          }}
        >
          The result? <span style={{ color: BRAND.softRed }}>Stress, confusion, and frustration</span>
          <br />
          before a single wall gets painted.
        </p>
      </div>
    </AbsoluteFill>
  );
};
