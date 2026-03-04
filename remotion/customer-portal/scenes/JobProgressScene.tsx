import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, TEXT, SPRING_CONFIGS } from '../brand';
import { CustomerPortalProps } from '../types';

const stages = [
  {
    label: 'Estimate Accepted',
    detail: 'You signed & approved the proposal',
    fillFrame: 50,
    color: BRAND.blue,
    icon: '✅',
  },
  {
    label: 'Colors & Scheduling',
    detail: 'Colors confirmed, date locked in',
    fillFrame: 90,
    color: BRAND.purple,
    icon: '🎨',
  },
  {
    label: 'Crew Assigned',
    detail: 'Your painting team is ready to go',
    fillFrame: 130,
    color: BRAND.teal,
    icon: '👷',
  },
  {
    label: 'In Progress',
    detail: 'Painting underway — updates in real time',
    fillFrame: 170,
    color: BRAND.amber,
    icon: '🖌️',
  },
  {
    label: 'Completed!',
    detail: 'Walk-through done. Your home looks amazing!',
    fillFrame: 215,
    color: BRAND.green,
    icon: '🏆',
  },
];

export const JobProgressScene: React.FC<{ data: CustomerPortalProps }> = ({ data }) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Title
  const titleOpacity = interpolate(frame, [0, 18], [0, 1], { extrapolateRight: 'clamp' });
  const titleSlide = interpolate(frame, [0, 18], [-28, 0], { extrapolateRight: 'clamp' });

  // Panel
  const panelOpacity = interpolate(frame, [22, 38], [0, 1], { extrapolateRight: 'clamp' });
  const panelScale = spring({ frame: frame - 22, fps, config: SPRING_CONFIGS.gentle });

  // Stage appearances (staggered)
  const stageScales = stages.map((_, i) =>
    spring({ frame: frame - (35 + i * 10), fps, config: SPRING_CONFIGS.snappy })
  );

  // Fill animations
  const stageFills = stages.map((stage) => {
    const fillBounce = spring({ frame: frame - stage.fillFrame, fps, config: SPRING_CONFIGS.bouncy });
    return {
      isFilled: frame >= stage.fillFrame,
      fillScale: fillBounce,
    };
  });

  // Progress line fill
  const lastFilledIndex = stages.reduce((last, stage, i) => {
    return frame >= stage.fillFrame ? i : last;
  }, -1);

  const progressPct = lastFilledIndex >= 0
    ? interpolate(
        frame,
        [stages[lastFilledIndex].fillFrame, stages[lastFilledIndex].fillFrame + 25],
        [lastFilledIndex / (stages.length - 1), Math.min((lastFilledIndex + 0.6) / (stages.length - 1), 1)],
        { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' }
      )
    : 0;

  // Celebration
  const celebFrame = 240;
  const celebScale = spring({ frame: frame - celebFrame, fps, config: SPRING_CONFIGS.bouncy });
  const celebOpacity = interpolate(frame, [celebFrame, celebFrame + 10], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // Phone notification
  const notifFrame = 225;
  const notifScale = spring({ frame: frame - notifFrame, fps, config: SPRING_CONFIGS.bouncy });
  const notifOpacity = interpolate(frame, [notifFrame, notifFrame + 10], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // Fade
  const fadeOut = interpolate(frame, [durationInFrames - 15, durationInFrames], [1, 0], {
    extrapolateLeft: 'clamp',
  });

  return (
    <AbsoluteFill style={{ backgroundColor: '#FFF8F0', opacity: fadeOut }}>
      {/* Top gradient */}
      <div
        style={{
          position: 'absolute',
          top: 0,
          left: 0,
          right: 0,
          height: 6,
          background: `linear-gradient(90deg, ${BRAND.amber}, ${BRAND.green}, ${BRAND.blue})`,
        }}
      />

      {/* Title */}
      <div
        style={{
          position: 'absolute',
          top: 24,
          left: 0,
          right: 0,
          textAlign: 'center',
          opacity: titleOpacity,
          transform: `translateY(${titleSlide}px)`,
        }}
      >
        <div
          style={{
            display: 'inline-flex',
            alignItems: 'center',
            gap: 10,
            backgroundColor: `${BRAND.amber}18`,
            border: `1px solid ${BRAND.amber}35`,
            borderRadius: 40,
            padding: '6px 20px',
            marginBottom: 8,
          }}
        >
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke={BRAND.amber} strokeWidth="2.5" strokeLinecap="round">
            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12" />
          </svg>
          <span style={{ fontSize: TEXT.xs, fontWeight: 700, color: BRAND.amber, fontFamily: FONT.system, letterSpacing: 1, textTransform: 'uppercase' }}>
            Job Progress Tracker
          </span>
        </div>
        <h2
          style={{
            fontSize: TEXT.heading - 8,
            fontWeight: 800,
            color: BRAND.navy,
            margin: 0,
            fontFamily: FONT.system,
          }}
        >
          Never Wonder, "What's Next?"
        </h2>
        <p style={{ fontSize: TEXT.small, color: BRAND.gray, marginTop: 6, fontFamily: FONT.system }}>
          Track every stage — from contract to completion
        </p>
      </div>

      {/* Main panel */}
      <div
        style={{
          position: 'absolute',
          top: 158,
          left: 200,
          right: 200,
          bottom: 30,
          opacity: panelOpacity,
          transform: `scale(${Math.max(0.9, panelScale)})`,
        }}
      >
        <div
          style={{
            width: '100%',
            height: '100%',
            borderRadius: 20,
            overflow: 'hidden',
            boxShadow: '0 30px 80px rgba(0,0,0,0.18)',
            backgroundColor: BRAND.white,
            padding: '28px 36px',
          }}
        >
          {/* Header */}
          <div
            style={{
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'space-between',
              marginBottom: 28,
              paddingBottom: 18,
              borderBottom: `2px solid ${BRAND.lightGray}`,
            }}
          >
            <div>
              <h3 style={{ fontSize: 22, fontWeight: 700, color: BRAND.dark, margin: 0, fontFamily: FONT.system }}>
                {data.address}
              </h3>
              <p style={{ fontSize: 14, color: BRAND.gray, margin: '4px 0 0', fontFamily: FONT.system }}>
                Interior Painting — 5 Rooms
              </p>
            </div>
            <div
              style={{
                padding: '8px 16px',
                borderRadius: 10,
                backgroundColor: `${BRAND.amber}15`,
                border: `2px solid ${BRAND.amber}30`,
              }}
            >
              <span style={{ fontSize: 13, fontWeight: 700, color: BRAND.amber, fontFamily: FONT.system }}>
                {lastFilledIndex >= 4 ? '🎉 Complete!' : `Stage ${Math.max(0, lastFilledIndex + 1)} of 5`}
              </span>
            </div>
          </div>

          {/* Progress timeline */}
          <div style={{ position: 'relative', paddingLeft: 68 }}>
            {/* Track line */}
            <div
              style={{
                position: 'absolute',
                left: 22,
                top: 24,
                bottom: 24,
                width: 6,
                backgroundColor: '#E5E7EB',
                borderRadius: 3,
              }}
            >
              {/* Fill */}
              <div
                style={{
                  position: 'absolute',
                  top: 0,
                  left: 0,
                  right: 0,
                  height: `${Math.min(progressPct * 100, 100)}%`,
                  background: `linear-gradient(180deg, ${BRAND.blue}, ${BRAND.teal}, ${BRAND.green})`,
                  borderRadius: 3,
                  boxShadow: `0 0 12px ${BRAND.green}60`,
                }}
              />
            </div>

            {/* Stages */}
            {stages.map((stage, i) => {
              const { isFilled, fillScale } = stageFills[i];
              const stageColor = isFilled ? stage.color : BRAND.gray;

              return (
                <div
                  key={stage.label}
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 18,
                    marginBottom: i < stages.length - 1 ? 22 : 0,
                    transform: `scale(${Math.max(0, stageScales[i])})`,
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
                      backgroundColor: isFilled ? stage.color : BRAND.white,
                      border: `3px solid ${isFilled ? stage.color : '#D1D5DB'}`,
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      transform: isFilled ? `scale(${Math.max(0, fillScale)})` : 'scale(1)',
                      boxShadow: isFilled ? `0 4px 20px ${stage.color}45` : '0 2px 8px rgba(0,0,0,0.06)',
                      zIndex: 2,
                    }}
                  >
                    {isFilled ? (
                      <span style={{ fontSize: 20 }}>{stage.icon}</span>
                    ) : (
                      <div style={{ width: 16, height: 16, borderRadius: '50%', backgroundColor: '#E5E7EB' }} />
                    )}
                  </div>

                  {/* Label */}
                  <div style={{ paddingLeft: 16 }}>
                    <div
                      style={{
                        fontSize: 20,
                        fontWeight: isFilled ? 700 : 500,
                        color: isFilled ? BRAND.dark : '#9CA3AF',
                        fontFamily: FONT.system,
                      }}
                    >
                      {stage.label}
                    </div>
                    <div
                      style={{
                        fontSize: 14,
                        color: isFilled ? BRAND.gray : '#D1D5DB',
                        fontFamily: FONT.system,
                        marginTop: 2,
                      }}
                    >
                      {stage.detail}
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      </div>

      {/* Phone notification */}
      {frame >= notifFrame && (
        <div
          style={{
            position: 'absolute',
            bottom: 60,
            right: 80,
            opacity: notifOpacity,
            transform: `scale(${Math.max(0, notifScale)})`,
            transformOrigin: 'bottom right',
          }}
        >
          <div
            style={{
              backgroundColor: BRAND.white,
              borderRadius: 18,
              padding: '16px 20px',
              boxShadow: `0 16px 50px rgba(0,0,0,0.20), 0 0 0 2px ${BRAND.green}40`,
              display: 'flex',
              alignItems: 'center',
              gap: 14,
              maxWidth: 310,
            }}
          >
            <div
              style={{
                width: 48,
                height: 48,
                borderRadius: 12,
                background: `linear-gradient(135deg, ${BRAND.green}, #059669)`,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                flexShrink: 0,
                fontSize: 24,
              }}
            >
              🏆
            </div>
            <div>
              <div style={{ fontSize: 15, fontWeight: 700, color: BRAND.dark, fontFamily: FONT.system }}>
                Job Complete!
              </div>
              <div style={{ fontSize: 12, color: BRAND.gray, fontFamily: FONT.system, marginTop: 2 }}>
                Your home is looking perfect. Rate us!
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Celebration banner */}
      {frame >= celebFrame && (
        <div
          style={{
            position: 'absolute',
            bottom: 60,
            left: 80,
            opacity: celebOpacity,
            transform: `scale(${Math.max(0, celebScale)})`,
            transformOrigin: 'bottom left',
          }}
        >
          <div
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: 12,
              backgroundColor: BRAND.white,
              borderRadius: 16,
              padding: '14px 24px',
              boxShadow: `0 10px 40px ${BRAND.green}25`,
              border: `2px solid ${BRAND.green}30`,
            }}
          >
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke={BRAND.green} strokeWidth="2.5" strokeLinecap="round">
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
