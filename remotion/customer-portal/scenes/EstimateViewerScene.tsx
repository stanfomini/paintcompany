import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, TEXT, SPRING_CONFIGS, STAGGER } from '../brand';
import { CustomerPortalProps } from '../types';

const packages = [
  {
    name: 'Good',
    price: 3200,
    color: BRAND.green,
    features: ['Walls Only', '1 Coat', 'Standard Paint'],
    highlight: false,
  },
  {
    name: 'Better',
    price: 4800,
    color: BRAND.blue,
    features: ['Walls + Ceiling', '2 Coats', 'Premium Paint'],
    highlight: true,
  },
  {
    name: 'Best',
    price: 6400,
    color: BRAND.amber,
    features: ['Full Room', '2 Coats', 'Designer Paint'],
    highlight: false,
  },
];

export const EstimateViewerScene: React.FC<{ data: CustomerPortalProps }> = ({ data }) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Title
  const titleOpacity = interpolate(frame, [0, 18], [0, 1], { extrapolateRight: 'clamp' });
  const titleSlide = interpolate(frame, [0, 18], [-28, 0], { extrapolateRight: 'clamp' });

  // Browser
  const browserOpacity = interpolate(frame, [22, 38], [0, 1], { extrapolateRight: 'clamp' });
  const browserScale = spring({ frame: frame - 22, fps, config: SPRING_CONFIGS.gentle });

  // Package cards
  const card0Scale = spring({ frame: frame - 55, fps, config: SPRING_CONFIGS.snappy });
  const card1Scale = spring({ frame: frame - 55 - STAGGER, fps, config: SPRING_CONFIGS.snappy });
  const card2Scale = spring({ frame: frame - 55 - STAGGER * 2, fps, config: SPRING_CONFIGS.snappy });
  const cardScales = [card0Scale, card1Scale, card2Scale];

  // Select "Better" package
  const selectFrame = 130;
  const selectedGlow = interpolate(frame, [selectFrame, selectFrame + 20], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // Scope of work section
  const scopeOpacity = interpolate(frame, [100, 120], [0, 1], { extrapolateRight: 'clamp' });

  // Signature
  const sigFrame = 175;
  const sigOpacity = interpolate(frame, [sigFrame, sigFrame + 15], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });
  const sigWidth = interpolate(frame, [sigFrame + 10, sigFrame + 45], [0, 210], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // Accepted stamp
  const acceptedFrame = 220;
  const acceptedScale = spring({ frame: frame - acceptedFrame, fps, config: SPRING_CONFIGS.bouncy });
  const acceptedOpacity = interpolate(frame, [acceptedFrame, acceptedFrame + 8], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // Fade
  const fadeOut = interpolate(frame, [durationInFrames - 15, durationInFrames], [1, 0], {
    extrapolateLeft: 'clamp',
  });

  return (
    <AbsoluteFill style={{ backgroundColor: '#F8F6FF', opacity: fadeOut }}>
      {/* Gradient accent */}
      <div
        style={{
          position: 'absolute',
          top: 0,
          left: 0,
          right: 0,
          height: 6,
          background: `linear-gradient(90deg, ${BRAND.purple}, ${BRAND.blue}, ${BRAND.sky})`,
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
            backgroundColor: `${BRAND.purple}15`,
            border: `1px solid ${BRAND.purple}30`,
            borderRadius: 40,
            padding: '6px 20px',
            marginBottom: 8,
          }}
        >
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke={BRAND.purple} strokeWidth="2.5" strokeLinecap="round">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
            <polyline points="14 2 14 8 20 8" />
          </svg>
          <span style={{ fontSize: TEXT.xs, fontWeight: 700, color: BRAND.purple, fontFamily: FONT.system, letterSpacing: 1, textTransform: 'uppercase' }}>
            Your Proposal
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
          Choose Your Package &amp; Sign Online
        </h2>
      </div>

      {/* Browser mockup */}
      <div
        style={{
          position: 'absolute',
          top: 148,
          left: 80,
          right: 80,
          bottom: 30,
          opacity: browserOpacity,
          transform: `scale(${Math.max(0.85, browserScale)})`,
        }}
      >
        <div
          style={{
            width: '100%',
            height: '100%',
            borderRadius: 16,
            overflow: 'hidden',
            boxShadow: '0 30px 90px rgba(0,0,0,0.22)',
            display: 'flex',
            flexDirection: 'column',
            backgroundColor: BRAND.white,
          }}
        >
          {/* Browser chrome */}
          <div
            style={{
              height: 44,
              backgroundColor: '#E5E7EB',
              display: 'flex',
              alignItems: 'center',
              padding: '0 16px',
              gap: 8,
              flexShrink: 0,
            }}
          >
            <div style={{ display: 'flex', gap: 7 }}>
              <div style={{ width: 12, height: 12, borderRadius: '50%', backgroundColor: '#EF4444' }} />
              <div style={{ width: 12, height: 12, borderRadius: '50%', backgroundColor: '#F59E0B' }} />
              <div style={{ width: 12, height: 12, borderRadius: '50%', backgroundColor: '#10B981' }} />
            </div>
            <div
              style={{
                flex: 1,
                marginLeft: 12,
                backgroundColor: BRAND.white,
                borderRadius: 5,
                padding: '5px 12px',
                fontSize: 12,
                color: BRAND.gray,
                fontFamily: FONT.system,
              }}
            >
              {data.website}/proposal/abc123
            </div>
          </div>

          {/* Proposal content */}
          <div style={{ flex: 1, padding: '18px 30px', overflow: 'hidden' }}>
            {/* Header */}
            <div style={{ textAlign: 'center', marginBottom: 18 }}>
              <h3 style={{ fontSize: 26, fontWeight: 700, color: BRAND.navy, margin: 0, fontFamily: FONT.system }}>
                🏠 Your Painting Proposal
              </h3>
              <p style={{ fontSize: 15, color: BRAND.gray, margin: '4px 0 0', fontFamily: FONT.system }}>
                {data.address}, {data.city} — 5 Rooms Interior
              </p>
            </div>

            {/* Package cards */}
            <div style={{ display: 'flex', gap: 16, justifyContent: 'center', marginBottom: 16 }}>
              {packages.map((pkg, i) => {
                const isSelected = i === 1 && frame >= selectFrame;
                return (
                  <div
                    key={pkg.name}
                    style={{
                      width: 220,
                      borderRadius: 14,
                      overflow: 'hidden',
                      transform: `scale(${Math.max(0, cardScales[i])})`,
                      boxShadow: isSelected
                        ? `0 0 0 3px ${pkg.color}, 0 12px 40px ${pkg.color}35`
                        : '0 4px 16px rgba(0,0,0,0.07)',
                      backgroundColor: BRAND.white,
                      transition: 'box-shadow 0.3s',
                    }}
                  >
                    <div
                      style={{
                        background: isSelected
                          ? `linear-gradient(135deg, ${pkg.color}, ${pkg.color}CC)`
                          : pkg.color,
                        padding: '12px 0',
                        textAlign: 'center',
                        position: 'relative',
                      }}
                    >
                      {isSelected && (
                        <div
                          style={{
                            position: 'absolute',
                            top: 6,
                            right: 10,
                            backgroundColor: BRAND.white,
                            borderRadius: '50%',
                            width: 20,
                            height: 20,
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                          }}
                        >
                          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke={pkg.color} strokeWidth="3">
                            <polyline points="20 6 9 17 4 12" />
                          </svg>
                        </div>
                      )}
                      <span style={{ fontSize: 20, fontWeight: 700, color: BRAND.white, fontFamily: FONT.system }}>
                        {pkg.name}
                      </span>
                    </div>
                    <div style={{ textAlign: 'center', padding: '12px 0 8px' }}>
                      <span style={{ fontSize: 32, fontWeight: 800, color: BRAND.dark, fontFamily: FONT.system }}>
                        ${pkg.price.toLocaleString()}
                      </span>
                    </div>
                    <div style={{ padding: '0 16px 14px' }}>
                      {pkg.features.map((f) => (
                        <div key={f} style={{ display: 'flex', alignItems: 'center', gap: 7, marginBottom: 6 }}>
                          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke={BRAND.green} strokeWidth="3">
                            <polyline points="20 6 9 17 4 12" />
                          </svg>
                          <span style={{ fontSize: 13, color: BRAND.gray, fontFamily: FONT.system }}>{f}</span>
                        </div>
                      ))}
                    </div>
                  </div>
                );
              })}
            </div>

            {/* Scope of work */}
            <div style={{ opacity: scopeOpacity }}>
              <div
                style={{
                  backgroundColor: BRAND.lightGray,
                  borderRadius: 10,
                  padding: '10px 16px',
                  marginBottom: 12,
                  borderLeft: `3px solid ${BRAND.blue}`,
                }}
              >
                <div style={{ fontSize: 13, fontWeight: 700, color: BRAND.dark, fontFamily: FONT.system, marginBottom: 4 }}>
                  Scope of Work
                </div>
                <div style={{ fontSize: 12, color: BRAND.gray, fontFamily: FONT.system }}>
                  Full interior painting including walls, ceilings, trim, and doors. 2 coats premium paint.
                  Prep work included. Clean-up guaranteed. 1-year warranty.
                </div>
              </div>
            </div>

            {/* Signature */}
            <div style={{ opacity: sigOpacity }}>
              <div
                style={{
                  border: `2px dashed #D1D5DB`,
                  borderRadius: 10,
                  padding: '10px 20px',
                  maxWidth: 360,
                  margin: '0 auto',
                  backgroundColor: `${BRAND.navy}03`,
                }}
              >
                <div style={{ fontSize: 11, color: BRAND.gray, fontFamily: FONT.system, marginBottom: 4 }}>
                  ✍️ Customer Signature
                </div>
                <div style={{ height: 32, overflow: 'hidden' }}>
                  <svg width={sigWidth} height="32" viewBox="0 0 210 32">
                    <path
                      d="M5 26 Q 28 5, 52 20 T 100 16 T 152 22 T 205 10"
                      fill="none"
                      stroke={BRAND.navy}
                      strokeWidth="2.5"
                      strokeLinecap="round"
                    />
                  </svg>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* ACCEPTED stamp */}
      {frame >= acceptedFrame && (
        <div
          style={{
            position: 'absolute',
            top: '50%',
            left: '50%',
            transform: `translate(-50%, -50%) scale(${Math.max(0, acceptedScale)}) rotate(-10deg)`,
            opacity: acceptedOpacity,
            zIndex: 100,
          }}
        >
          <div
            style={{
              border: `6px solid ${BRAND.green}`,
              borderRadius: 18,
              padding: '16px 50px',
              backgroundColor: 'rgba(255,255,255,0.97)',
              boxShadow: `0 20px 60px ${BRAND.green}30`,
            }}
          >
            <span
              style={{
                fontSize: 52,
                fontWeight: 800,
                color: BRAND.green,
                fontFamily: FONT.system,
                letterSpacing: 5,
              }}
            >
              ✓ ACCEPTED
            </span>
          </div>
        </div>
      )}
    </AbsoluteFill>
  );
};
