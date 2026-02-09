import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, SPRING_CONFIGS, STAGGER_OFFSET } from '../brand';
import { PromoVideoProps } from '../types';
import { FloatingParticles } from '../components/FloatingParticles';

interface ProgressStage {
  label: string;
  icon: React.ReactNode;
  fillFrame: number;
}

const stages: ProgressStage[] = [
  {
    label: 'Estimate Accepted',
    icon: (
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <path d="M20 6 9 17l-5-5" />
      </svg>
    ),
    fillFrame: 60,
  },
  {
    label: 'Colors & Scheduling',
    icon: (
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <circle cx="12" cy="12" r="10" />
        <path d="M12 6v6l4 2" />
      </svg>
    ),
    fillFrame: 100,
  },
  {
    label: 'Job Prep',
    icon: (
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <rect x="3" y="3" width="7" height="9" />
        <rect x="14" y="3" width="7" height="5" />
        <rect x="14" y="12" width="7" height="9" />
        <rect x="3" y="16" width="7" height="5" />
      </svg>
    ),
    fillFrame: 140,
  },
  {
    label: 'In Progress',
    icon: (
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <path d="M18.37 2.63 14 7l-1.59-1.59a2 2 0 0 0-2.82 0L8 7l9 9 1.59-1.59a2 2 0 0 0 0-2.82L17 10l4.37-4.37a2.12 2.12 0 1 0-3-3Z" />
        <path d="M9 8c-2 3-4 3.5-7 4l8 10c2-1 6-5 6-7" />
      </svg>
    ),
    fillFrame: 185,
  },
  {
    label: 'Completed',
    icon: (
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <path d="m3 9 9 9 9-9" />
        <path d="M12 18V3" />
        <rect x="3" y="21" width="18" height="2" rx="1" />
      </svg>
    ),
    fillFrame: 230,
  },
];

export const JobProgressScene: React.FC<{ data: PromoVideoProps }> = ({ data }) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Title
  const titleOpacity = interpolate(frame, [0, 20], [0, 1], { extrapolateRight: 'clamp' });
  const titleSlide = interpolate(frame, [0, 20], [-30, 0], { extrapolateRight: 'clamp' });

  // Subtitle
  const subtitleOpacity = interpolate(frame, [15, 35], [0, 1], { extrapolateRight: 'clamp' });

  // Progress bar container
  const barScale = spring({ frame: frame - 35, fps, config: SPRING_CONFIGS.gentle });

  // Completion celebration
  const celebFrame = 250;
  const celebScale = spring({ frame: frame - celebFrame, fps, config: SPRING_CONFIGS.bouncy });
  const celebOpacity = interpolate(frame, [celebFrame, celebFrame + 10], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // Fade
  const fadeOut = interpolate(frame, [durationInFrames - 15, durationInFrames], [1, 0], {
    extrapolateLeft: 'clamp',
  });

  return (
    <AbsoluteFill style={{ backgroundColor: BRAND.lightGray, opacity: fadeOut }}>
      <FloatingParticles count={12} color={`${BRAND.green}15`} seed={55} />

      {/* Title */}
      <div
        style={{
          position: 'absolute',
          top: 60,
          left: 0,
          right: 0,
          textAlign: 'center',
          opacity: titleOpacity,
          transform: `translateY(${titleSlide}px)`,
        }}
      >
        <h2 style={{ fontSize: 48, fontWeight: 700, color: BRAND.navy, margin: 0, fontFamily: FONT.system }}>
          Track Every Stage
        </h2>
        <p
          style={{
            fontSize: 24,
            color: BRAND.gray,
            marginTop: 8,
            fontFamily: FONT.system,
            opacity: subtitleOpacity,
          }}
        >
          Real-time job progress visible to you and your customer
        </p>
      </div>

      {/* Progress bar */}
      <div
        style={{
          position: 'absolute',
          top: 250,
          left: 120,
          right: 120,
          transform: `scale(${Math.max(0.8, barScale)})`,
        }}
      >
        {/* Connecting line */}
        <div
          style={{
            position: 'absolute',
            top: 35,
            left: 60,
            right: 60,
            height: 6,
            backgroundColor: '#E5E7EB',
            borderRadius: 3,
          }}
        >
          {/* Fill */}
          {stages.map((stage, i) => {
            if (frame < stage.fillFrame) {
              return null;
            }
            const fillWidth = interpolate(
              frame,
              [stage.fillFrame, stage.fillFrame + 20],
              [(i / stages.length) * 100, ((i + 1) / stages.length) * 100],
              { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' }
            );
            return i === 0 ? null : undefined; // Handled below
          })}
          {/* Single animated fill bar */}
          {(() => {
            // Find the latest completed stage
            let latestFilled = -1;
            let latestProgress = 0;
            for (let i = 0; i < stages.length; i++) {
              if (frame >= stages[i].fillFrame) {
                latestFilled = i;
                if (i < stages.length - 1) {
                  latestProgress = interpolate(
                    frame,
                    [stages[i].fillFrame, stages[i].fillFrame + 25],
                    [(i / (stages.length - 1)) * 100, ((i + 1) / (stages.length - 1)) * 100],
                    { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' }
                  );
                } else {
                  latestProgress = 100;
                }
              }
            }
            if (latestFilled < 0) {
              return null;
            }
            return (
              <div
                style={{
                  position: 'absolute',
                  top: 0,
                  left: 0,
                  height: '100%',
                  width: `${latestProgress}%`,
                  background: `linear-gradient(90deg, ${BRAND.blue}, ${BRAND.green})`,
                  borderRadius: 3,
                }}
              />
            );
          })()}
        </div>

        {/* Stage circles */}
        <div style={{ display: 'flex', justifyContent: 'space-between', position: 'relative' }}>
          {stages.map((stage, i) => {
            const stageScale = spring({
              frame: frame - (40 + i * STAGGER_OFFSET),
              fps,
              config: SPRING_CONFIGS.snappy,
            });
            const isFilled = frame >= stage.fillFrame;
            const fillScale = spring({
              frame: frame - stage.fillFrame,
              fps,
              config: SPRING_CONFIGS.bouncy,
            });

            return (
              <div
                key={stage.label}
                style={{
                  display: 'flex',
                  flexDirection: 'column',
                  alignItems: 'center',
                  width: 140,
                  transform: `scale(${Math.max(0, stageScale)})`,
                }}
              >
                {/* Circle */}
                <div
                  style={{
                    width: 70,
                    height: 70,
                    borderRadius: '50%',
                    backgroundColor: isFilled ? BRAND.green : BRAND.white,
                    border: `3px solid ${isFilled ? BRAND.green : '#D1D5DB'}`,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    color: isFilled ? BRAND.white : BRAND.gray,
                    transform: isFilled ? `scale(${Math.max(0, fillScale)})` : 'none',
                    boxShadow: isFilled ? `0 4px 16px ${BRAND.green}40` : '0 2px 8px rgba(0,0,0,0.05)',
                  }}
                >
                  {isFilled ? (
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke={BRAND.white} strokeWidth="3">
                      <polyline points="20 6 9 17 4 12" />
                    </svg>
                  ) : (
                    stage.icon
                  )}
                </div>
                {/* Label */}
                <span
                  style={{
                    marginTop: 12,
                    fontSize: 16,
                    fontWeight: isFilled ? 700 : 500,
                    color: isFilled ? BRAND.green : BRAND.gray,
                    textAlign: 'center',
                    fontFamily: FONT.system,
                  }}
                >
                  {stage.label}
                </span>
              </div>
            );
          })}
        </div>
      </div>

      {/* Completion celebration */}
      {frame >= celebFrame && (
        <div
          style={{
            position: 'absolute',
            bottom: 150,
            left: 0,
            right: 0,
            textAlign: 'center',
            opacity: celebOpacity,
            transform: `scale(${Math.max(0, celebScale)})`,
          }}
        >
          <div
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: 16,
              backgroundColor: BRAND.white,
              borderRadius: 20,
              padding: '20px 40px',
              boxShadow: `0 8px 30px ${BRAND.green}30`,
              border: `2px solid ${BRAND.green}30`,
            }}
          >
            <div
              style={{
                width: 50,
                height: 50,
                borderRadius: '50%',
                backgroundColor: BRAND.green,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
              }}
            >
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke={BRAND.white} strokeWidth="3">
                <polyline points="20 6 9 17 4 12" />
              </svg>
            </div>
            <div style={{ textAlign: 'left' }}>
              <span style={{ fontSize: 28, fontWeight: 700, color: BRAND.dark, fontFamily: FONT.system }}>
                Job Complete!
              </span>
              <p style={{ fontSize: 16, color: BRAND.gray, margin: '4px 0 0', fontFamily: FONT.system }}>
                Customer notified automatically
              </p>
            </div>
          </div>
        </div>
      )}
    </AbsoluteFill>
  );
};
