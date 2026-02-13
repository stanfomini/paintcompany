import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, TEXT, SPRING_CONFIGS, STAGGER_OFFSET } from '../brand';
import { PromoVideoProps } from '../types';
import { MockupFrame } from '../components/MockupFrame';

interface ProgressStage {
  label: string;
  detail: string;
  icon: React.ReactNode;
  fillFrame: number;
}

const stages: ProgressStage[] = [
  {
    label: 'Estimate Accepted',
    detail: 'You chose your package',
    fillFrame: 55,
    icon: (
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round">
        <path d="M20 6 9 17l-5-5" />
      </svg>
    ),
  },
  {
    label: 'Colors & Scheduling',
    detail: 'Confirm your colors and date',
    fillFrame: 90,
    icon: (
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <circle cx="13.5" cy="6.5" r="2.5" />
        <circle cx="17.5" cy="10.5" r="2.5" />
        <circle cx="8.5" cy="7.5" r="2.5" />
        <circle cx="6.5" cy="12.5" r="2.5" />
      </svg>
    ),
  },
  {
    label: 'Crew Assigned',
    detail: 'Your team is ready',
    fillFrame: 125,
    icon: (
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
        <circle cx="9" cy="7" r="4" />
        <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
        <path d="M16 3.13a4 4 0 0 1 0 7.75" />
      </svg>
    ),
  },
  {
    label: 'In Progress',
    detail: 'Painting underway',
    fillFrame: 155,
    icon: (
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <path d="M18.37 2.63 14 7l-1.59-1.59a2 2 0 0 0-2.82 0L8 7l9 9 1.59-1.59a2 2 0 0 0 0-2.82L17 10l4.37-4.37a2.12 2.12 0 1 0-3-3Z" />
        <path d="M9 8c-2 3-4 3.5-7 4l8 10c2-1 6-5 6-7" />
      </svg>
    ),
  },
  {
    label: 'Completed',
    detail: 'Walk-through & done!',
    fillFrame: 190,
    icon: (
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
        <path d="M22 4 12 14.01l-3-3" />
      </svg>
    ),
  },
];

export const JobProgressScene: React.FC<{ data: PromoVideoProps }> = ({ data }) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Title
  const titleOpacity = interpolate(frame, [0, 20], [0, 1], { extrapolateRight: 'clamp' });
  const titleSlide = interpolate(frame, [0, 20], [-30, 0], { extrapolateRight: 'clamp' });

  // Browser mockup
  const mockupScale = spring({ frame: frame - 25, fps, config: SPRING_CONFIGS.gentle });
  const mockupOpacity = interpolate(frame, [25, 45], [0, 1], { extrapolateRight: 'clamp' });

  // Completion celebration
  const celebFrame = 210;
  const celebScale = spring({ frame: frame - celebFrame, fps, config: SPRING_CONFIGS.bouncy });

  // Fade
  const fadeOut = interpolate(frame, [durationInFrames - 15, durationInFrames], [1, 0], {
    extrapolateLeft: 'clamp',
  });

  return (
    <AbsoluteFill style={{ backgroundColor: BRAND.lightGray, opacity: fadeOut }}>
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
        <h2
          style={{
            fontSize: TEXT.heading - 4,
            fontWeight: 800,
            color: BRAND.navy,
            margin: 0,
            fontFamily: FONT.system,
          }}
        >
          Your Job Dashboard
        </h2>
        <p style={{ fontSize: TEXT.label, color: BRAND.gray, marginTop: 6, fontFamily: FONT.system }}>
          Track every stage from your personal portal
        </p>
      </div>

      {/* Browser mockup with progress */}
      <div
        style={{
          position: 'absolute',
          top: 140,
          left: 120,
          right: 120,
          bottom: 30,
          opacity: mockupOpacity,
          transform: `scale(${Math.max(0.85, mockupScale)})`,
        }}
      >
        <MockupFrame type="browser" url={`${data.website}/my-project`}>
          <div style={{ padding: '30px 50px' }}>
            {/* Dashboard header */}
            <div
              style={{
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                marginBottom: 30,
                paddingBottom: 16,
                borderBottom: '2px solid #E5E7EB',
              }}
            >
              <div>
                <h3 style={{ fontSize: 26, fontWeight: 700, color: BRAND.dark, margin: 0, fontFamily: FONT.system }}>
                  123 Maple Street
                </h3>
                <p style={{ fontSize: 17, color: BRAND.gray, margin: '4px 0 0', fontFamily: FONT.system }}>
                  Interior Painting - 5 Rooms
                </p>
              </div>
              <div
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: 8,
                  backgroundColor: `${BRAND.green}15`,
                  borderRadius: 8,
                  padding: '8px 16px',
                }}
              >
                <div style={{ width: 8, height: 8, borderRadius: '50%', backgroundColor: BRAND.green }} />
                <span style={{ fontSize: 14, fontWeight: 600, color: BRAND.green, fontFamily: FONT.system }}>
                  Active
                </span>
              </div>
            </div>

            {/* Vertical progress timeline */}
            <div style={{ position: 'relative', paddingLeft: 50 }}>
              {/* Connecting line */}
              <div
                style={{
                  position: 'absolute',
                  left: 22,
                  top: 24,
                  bottom: 24,
                  width: 4,
                  backgroundColor: '#E5E7EB',
                  borderRadius: 2,
                }}
              >
                {/* Animated fill */}
                {(() => {
                  let lastFilled = -1;
                  for (let i = 0; i < stages.length; i++) {
                    if (frame >= stages[i].fillFrame) lastFilled = i;
                  }
                  if (lastFilled < 0) return null;
                  const fillPct = interpolate(
                    frame,
                    [stages[lastFilled].fillFrame, stages[lastFilled].fillFrame + 20],
                    [lastFilled / (stages.length - 1), (lastFilled + 0.5) / (stages.length - 1)],
                    { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' }
                  );
                  return (
                    <div
                      style={{
                        position: 'absolute',
                        top: 0,
                        left: 0,
                        width: '100%',
                        height: `${Math.min(fillPct * 100, 100)}%`,
                        background: `linear-gradient(180deg, ${BRAND.blue}, ${BRAND.green})`,
                        borderRadius: 2,
                      }}
                    />
                  );
                })()}
              </div>

              {/* Stages */}
              {stages.map((stage, i) => {
                const stageAppear = spring({
                  frame: frame - (35 + i * 8),
                  fps,
                  config: SPRING_CONFIGS.snappy,
                });
                const isFilled = frame >= stage.fillFrame;
                const fillBounce = spring({
                  frame: frame - stage.fillFrame,
                  fps,
                  config: SPRING_CONFIGS.bouncy,
                });

                return (
                  <div
                    key={stage.label}
                    style={{
                      display: 'flex',
                      alignItems: 'center',
                      gap: 20,
                      marginBottom: i < stages.length - 1 ? 20 : 0,
                      transform: `scale(${Math.max(0, stageAppear)})`,
                      transformOrigin: 'left center',
                    }}
                  >
                    {/* Circle */}
                    <div
                      style={{
                        position: 'absolute',
                        left: 0,
                        width: 48,
                        height: 48,
                        borderRadius: '50%',
                        backgroundColor: isFilled ? BRAND.green : BRAND.white,
                        border: `3px solid ${isFilled ? BRAND.green : '#D1D5DB'}`,
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        color: isFilled ? BRAND.white : BRAND.gray,
                        transform: isFilled ? `scale(${Math.max(0, fillBounce)})` : 'none',
                        boxShadow: isFilled ? `0 4px 16px ${BRAND.green}40` : '0 2px 6px rgba(0,0,0,0.05)',
                        zIndex: 2,
                      }}
                    >
                      {isFilled ? (
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke={BRAND.white} strokeWidth="3">
                          <polyline points="20 6 9 17 4 12" />
                        </svg>
                      ) : (
                        stage.icon
                      )}
                    </div>

                    {/* Label */}
                    <div style={{ paddingLeft: 10 }}>
                      <span
                        style={{
                          fontSize: 22,
                          fontWeight: isFilled ? 700 : 500,
                          color: isFilled ? BRAND.dark : BRAND.gray,
                          fontFamily: FONT.system,
                        }}
                      >
                        {stage.label}
                      </span>
                      <p
                        style={{
                          fontSize: 16,
                          color: isFilled ? BRAND.gray : '#D1D5DB',
                          margin: '3px 0 0',
                          fontFamily: FONT.system,
                        }}
                      >
                        {stage.detail}
                      </p>
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        </MockupFrame>
      </div>

      {/* Celebration */}
      {frame >= celebFrame && (
        <div
          style={{
            position: 'absolute',
            bottom: 50,
            left: 0,
            right: 0,
            textAlign: 'center',
            transform: `scale(${Math.max(0, celebScale)})`,
          }}
        >
          <div
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: 14,
              backgroundColor: BRAND.white,
              borderRadius: 16,
              padding: '16px 36px',
              boxShadow: `0 8px 30px ${BRAND.green}25`,
              border: `2px solid ${BRAND.green}30`,
            }}
          >
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke={BRAND.green} strokeWidth="2.5" strokeLinecap="round">
              <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
              <path d="M22 4 12 14.01l-3-3" />
            </svg>
            <span style={{ fontSize: TEXT.label, fontWeight: 700, color: BRAND.dark, fontFamily: FONT.system }}>
              Every step visible to you, in real time
            </span>
          </div>
        </div>
      )}
    </AbsoluteFill>
  );
};
