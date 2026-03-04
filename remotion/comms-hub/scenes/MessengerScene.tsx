import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, TEXT, SPRING_CONFIGS } from '../brand';
import { FloatingParticles } from '../components/FloatingParticles';

interface FlowStep {
  label: string;
  sublabel: string;
  icon: React.ReactNode;
  color: string;
  delay: number;
}

const flow: FlowStep[] = [
  {
    label: 'Customer messages on Facebook',
    sublabel: 'Via Messenger',
    color: BRAND.blue,
    delay: 30,
    icon: (
      <svg width="28" height="28" viewBox="0 0 24 24" fill={BRAND.blue}>
        <path d="M12 2C6.36 2 2 6.13 2 11.7c0 2.91 1.2 5.42 3.15 7.2.16.15.26.36.27.58l.06 1.82c.02.58.63.96 1.16.72l2.03-.9c.17-.08.37-.1.55-.06.9.25 1.85.38 2.78.38 5.64 0 10-4.13 10-9.7S17.64 2 12 2z" />
      </svg>
    ),
  },
  {
    label: 'Bot auto-responds instantly',
    sublabel: 'FAQ, scheduling, quotes',
    color: BRAND.purple,
    delay: 60,
    icon: (
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke={BRAND.purple} strokeWidth="2" strokeLinecap="round">
        <rect x="3" y="11" width="18" height="10" rx="2" />
        <circle cx="9" cy="16" r="1.5" fill={BRAND.purple} />
        <circle cx="15" cy="16" r="1.5" fill={BRAND.purple} />
        <path d="M8 11V7a4 4 0 0 1 8 0v4" />
      </svg>
    ),
  },
  {
    label: 'You reply from the Hub',
    sublabel: 'Human takeover triggered',
    color: BRAND.green,
    delay: 90,
    icon: (
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke={BRAND.green} strokeWidth="2" strokeLinecap="round">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
        <path d="M8 10h8M8 14h4" />
      </svg>
    ),
  },
  {
    label: 'Customer gets reply on Facebook',
    sublabel: 'Seamless bidirectional sync',
    color: BRAND.sky,
    delay: 120,
    icon: (
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke={BRAND.sky} strokeWidth="2" strokeLinecap="round">
        <path d="M22 2 11 13M22 2l-7 20-4-9-9-4z" />
      </svg>
    ),
  },
];

export const MessengerScene: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  const titleOpacity = interpolate(frame, [0, 20], [0, 1], { extrapolateRight: 'clamp' });
  const titleSlide = interpolate(frame, [0, 20], [-30, 0], { extrapolateRight: 'clamp' });

  const fadeOut = interpolate(frame, [durationInFrames - 15, durationInFrames], [1, 0], { extrapolateLeft: 'clamp' });

  // Connecting arrow progress
  const arrowProgress = (stepIdx: number): number => {
    const startFrame = flow[stepIdx].delay + 20;
    return interpolate(frame, [startFrame, startFrame + 15], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });
  };

  return (
    <AbsoluteFill
      style={{
        background: `linear-gradient(180deg, ${BRAND.white} 0%, ${BRAND.lightGray} 100%)`,
        opacity: fadeOut,
      }}
    >
      <FloatingParticles count={10} color={`${BRAND.blue}08`} seed={44} />

      {/* Title */}
      <div
        style={{
          position: 'absolute',
          top: 40,
          left: 0,
          right: 0,
          textAlign: 'center',
          opacity: titleOpacity,
          transform: `translateY(${titleSlide}px)`,
        }}
      >
        <h2 style={{ fontSize: TEXT.heading - 8, fontWeight: 800, color: BRAND.navy, margin: 0, fontFamily: FONT.system }}>
          Facebook Messenger
        </h2>
        <p style={{ fontSize: TEXT.label, color: BRAND.gray, marginTop: 6, fontFamily: FONT.system }}>
          Bidirectional sync with automated bot handoff
        </p>
      </div>

      {/* Flow diagram */}
      <div
        style={{
          position: 'absolute',
          top: 180,
          left: 120,
          right: 120,
          display: 'flex',
          flexDirection: 'column',
          gap: 0,
        }}
      >
        {flow.map((step, i) => {
          const stepScale = spring({ frame: frame - step.delay, fps, config: SPRING_CONFIGS.snappy });
          const stepOpacity = interpolate(frame, [step.delay, step.delay + 10], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });

          return (
            <React.Fragment key={step.label}>
              {/* Step card */}
              <div
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: 24,
                  opacity: stepOpacity,
                  transform: `scale(${Math.max(0, stepScale)}) translateX(${(1 - Math.max(0, stepScale)) * 30}px)`,
                  transformOrigin: 'left center',
                }}
              >
                {/* Step number */}
                <div
                  style={{
                    width: 48,
                    height: 48,
                    borderRadius: '50%',
                    backgroundColor: step.color,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    color: BRAND.white,
                    fontWeight: 800,
                    fontSize: 20,
                    fontFamily: FONT.system,
                    flexShrink: 0,
                    boxShadow: `0 4px 20px ${step.color}40`,
                  }}
                >
                  {i + 1}
                </div>

                {/* Card */}
                <div
                  style={{
                    flex: 1,
                    display: 'flex',
                    alignItems: 'center',
                    gap: 20,
                    backgroundColor: BRAND.white,
                    borderRadius: 16,
                    padding: '20px 28px',
                    border: `1px solid ${step.color}25`,
                    boxShadow: `0 4px 20px ${step.color}10`,
                  }}
                >
                  <div
                    style={{
                      width: 56,
                      height: 56,
                      borderRadius: 14,
                      backgroundColor: `${step.color}12`,
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      flexShrink: 0,
                    }}
                  >
                    {step.icon}
                  </div>
                  <div>
                    <div style={{ fontSize: TEXT.label, fontWeight: 700, color: BRAND.dark, fontFamily: FONT.system }}>
                      {step.label}
                    </div>
                    <div style={{ fontSize: TEXT.small, color: BRAND.gray, marginTop: 3, fontFamily: FONT.system }}>
                      {step.sublabel}
                    </div>
                  </div>
                </div>
              </div>

              {/* Connecting arrow */}
              {i < flow.length - 1 && (
                <div
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    paddingLeft: 22,
                    height: 40,
                  }}
                >
                  <div
                    style={{
                      width: 4,
                      height: 40 * arrowProgress(i),
                      backgroundColor: `${flow[i + 1].color}40`,
                      borderRadius: 2,
                    }}
                  />
                </div>
              )}
            </React.Fragment>
          );
        })}
      </div>

      {/* Lead auto-creation badge */}
      {(() => {
        const badgeDelay = 150;
        const badgeOpacity = interpolate(frame, [badgeDelay, badgeDelay + 15], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });
        const badgeSlide = interpolate(frame, [badgeDelay, badgeDelay + 15], [20, 0], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });
        return (
          <div
            style={{
              position: 'absolute',
              bottom: 60,
              left: 0,
              right: 0,
              display: 'flex',
              justifyContent: 'center',
              opacity: badgeOpacity,
              transform: `translateY(${badgeSlide}px)`,
            }}
          >
            <div
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: 12,
                backgroundColor: `${BRAND.green}10`,
                border: `1px solid ${BRAND.green}30`,
                borderRadius: 14,
                padding: '12px 24px',
              }}
            >
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke={BRAND.green} strokeWidth="2" strokeLinecap="round">
                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                <circle cx="9" cy="7" r="4" />
                <path d="M19 8v6M22 11h-6" />
              </svg>
              <span style={{ fontSize: 17, fontWeight: 700, color: BRAND.green, fontFamily: FONT.system }}>
                Auto-creates CRM leads from Messenger conversations
              </span>
            </div>
          </div>
        );
      })()}
    </AbsoluteFill>
  );
};
