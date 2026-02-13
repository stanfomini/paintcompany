import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, TEXT, SPRING_CONFIGS, STAGGER_OFFSET } from '../brand';
import { PromoVideoProps } from '../types';

interface ProcessStep {
  number: string;
  title: string;
  subtitle: string;
  icon: React.ReactNode;
  color: string;
  delay: number;
}

const steps: ProcessStep[] = [
  {
    number: '1',
    title: 'Check Your Email',
    subtitle: 'Even your junk folder',
    color: BRAND.blue,
    delay: 40,
    icon: (
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <rect x="2" y="4" width="20" height="16" rx="2" />
        <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" />
      </svg>
    ),
  },
  {
    number: '2',
    title: 'Schedule Your Estimate',
    subtitle: 'Virtual or in-person',
    color: BRAND.sky,
    delay: 70,
    icon: (
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <rect x="3" y="4" width="18" height="18" rx="2" />
        <path d="M16 2v4M8 2v4M3 10h18" />
        <path d="m9 16 2 2 4-4" />
      </svg>
    ),
  },
  {
    number: '3',
    title: 'Tell Us What You Want',
    subtitle: 'Photos, video, or just chat',
    color: BRAND.green,
    delay: 100,
    icon: (
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z" />
        <circle cx="12" cy="13" r="3" />
      </svg>
    ),
  },
  {
    number: '4',
    title: 'Get Your Proposal',
    subtitle: 'Precise, not guesswork',
    color: BRAND.amber,
    delay: 130,
    icon: (
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <path d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z" />
      </svg>
    ),
  },
];

export const ProcessOverviewScene: React.FC<{ data: PromoVideoProps }> = () => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Title
  const titleOpacity = interpolate(frame, [0, 20], [0, 1], { extrapolateRight: 'clamp' });
  const titleSlide = interpolate(frame, [0, 20], [-30, 0], { extrapolateRight: 'clamp' });

  // Subtitle
  const subtitleOpacity = interpolate(frame, [12, 30], [0, 1], { extrapolateRight: 'clamp' });

  // Bottom tagline
  const taglineFrame = 165;
  const taglineOpacity = interpolate(frame, [taglineFrame, taglineFrame + 20], [0, 1], {
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
        background: `linear-gradient(180deg, ${BRAND.white} 0%, ${BRAND.lightGray} 100%)`,
        opacity: fadeOut,
      }}
    >
      {/* Title */}
      <div
        style={{
          position: 'absolute',
          top: 70,
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
            color: BRAND.navy,
            margin: 0,
            fontFamily: FONT.system,
            letterSpacing: -1,
          }}
        >
          Here's How It Works
        </h2>
        <p
          style={{
            fontSize: TEXT.body,
            color: BRAND.gray,
            marginTop: 10,
            fontFamily: FONT.system,
            opacity: subtitleOpacity,
          }}
        >
          Simple. Fast. No surprises.
        </p>
      </div>

      {/* Steps - 2x2 grid for big readable cards */}
      <div
        style={{
          position: 'absolute',
          top: 230,
          left: 100,
          right: 100,
          display: 'grid',
          gridTemplateColumns: '1fr 1fr',
          gap: 30,
        }}
      >
        {steps.map((step) => {
          const stepScale = spring({
            frame: frame - step.delay,
            fps,
            config: SPRING_CONFIGS.snappy,
          });
          const stepSlide = interpolate(
            frame,
            [step.delay, step.delay + 20],
            [30, 0],
            { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' }
          );

          return (
            <div
              key={step.number}
              style={{
                transform: `scale(${Math.max(0, stepScale)}) translateY(${stepSlide}px)`,
                display: 'flex',
                alignItems: 'center',
                gap: 24,
                backgroundColor: BRAND.white,
                borderRadius: 20,
                padding: '28px 32px',
                boxShadow: '0 8px 30px rgba(0,0,0,0.08)',
                border: `1px solid ${step.color}20`,
              }}
            >
              {/* Step number + icon */}
              <div
                style={{
                  width: 72,
                  height: 72,
                  borderRadius: '50%',
                  backgroundColor: `${step.color}15`,
                  border: `3px solid ${step.color}40`,
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  color: step.color,
                  flexShrink: 0,
                  position: 'relative',
                }}
              >
                {step.icon}
                <div
                  style={{
                    position: 'absolute',
                    top: -8,
                    right: -8,
                    width: 28,
                    height: 28,
                    borderRadius: '50%',
                    backgroundColor: step.color,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                  }}
                >
                  <span style={{ fontSize: 14, fontWeight: 800, color: BRAND.white, fontFamily: FONT.system }}>
                    {step.number}
                  </span>
                </div>
              </div>

              {/* Text */}
              <div>
                <h3
                  style={{
                    fontSize: TEXT.body,
                    fontWeight: 700,
                    color: BRAND.dark,
                    margin: 0,
                    fontFamily: FONT.system,
                  }}
                >
                  {step.title}
                </h3>
                <p
                  style={{
                    fontSize: TEXT.label,
                    color: BRAND.gray,
                    margin: '6px 0 0',
                    fontFamily: FONT.system,
                  }}
                >
                  {step.subtitle}
                </p>
              </div>
            </div>
          );
        })}
      </div>

      {/* Bottom tagline */}
      <div
        style={{
          position: 'absolute',
          bottom: 60,
          left: 0,
          right: 0,
          textAlign: 'center',
          opacity: taglineOpacity,
        }}
      >
        <p
          style={{
            fontSize: TEXT.subheading,
            fontWeight: 700,
            color: BRAND.blue,
            margin: 0,
            fontFamily: FONT.system,
          }}
        >
          We don't guess at pricing. We use advanced tools.
        </p>
      </div>
    </AbsoluteFill>
  );
};
