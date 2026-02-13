import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, TEXT, SPRING_CONFIGS, STAGGER_OFFSET } from '../brand';
import { PromoVideoProps } from '../types';
import { AnimatedCursor } from '../components/AnimatedCursor';
import { MockupFrame } from '../components/MockupFrame';

interface PackageCard {
  name: string;
  price: number;
  color: string;
  features: string[];
}

const packages: PackageCard[] = [
  {
    name: 'Good',
    price: 3200,
    color: BRAND.green,
    features: ['Walls Only', '1 Coat', 'Standard Paint'],
  },
  {
    name: 'Better',
    price: 4800,
    color: BRAND.blue,
    features: ['Walls + Ceiling', '2 Coats', 'Premium Paint'],
  },
  {
    name: 'Best',
    price: 6400,
    color: BRAND.amber,
    features: ['Full Room', '2 Coats', 'Designer Paint'],
  },
];

export const EstimateExperienceScene: React.FC<{ data: PromoVideoProps }> = ({ data }) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Title
  const titleOpacity = interpolate(frame, [0, 20], [0, 1], { extrapolateRight: 'clamp' });
  const titleSlide = interpolate(frame, [0, 20], [-30, 0], { extrapolateRight: 'clamp' });

  // Browser
  const browserScale = spring({ frame: frame - 25, fps, config: SPRING_CONFIGS.gentle });
  const browserOpacity = interpolate(frame, [25, 45], [0, 1], { extrapolateRight: 'clamp' });

  // Cards stagger
  const card0Scale = spring({ frame: frame - 55, fps, config: SPRING_CONFIGS.snappy });
  const card1Scale = spring({ frame: frame - 55 - STAGGER_OFFSET, fps, config: SPRING_CONFIGS.snappy });
  const card2Scale = spring({ frame: frame - 55 - STAGGER_OFFSET * 2, fps, config: SPRING_CONFIGS.snappy });
  const cardScales = [card0Scale, card1Scale, card2Scale];

  // Select "Better" card
  const selectFrame = 130;
  const selectedGlow = interpolate(frame, [selectFrame, selectFrame + 15], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });
  const checkScale = spring({ frame: frame - selectFrame - 8, fps, config: SPRING_CONFIGS.bouncy });

  // Signature
  const signatureFrame = 170;
  const sigOpacity = interpolate(frame, [signatureFrame, signatureFrame + 12], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });
  const sigWidth = interpolate(frame, [signatureFrame + 8, signatureFrame + 40], [0, 200], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // Accepted badge
  const acceptedFrame = 200;
  const acceptedScale = spring({ frame: frame - acceptedFrame, fps, config: SPRING_CONFIGS.bouncy });

  // Scene fade
  const fadeOut = interpolate(frame, [durationInFrames - 15, durationInFrames], [1, 0], {
    extrapolateLeft: 'clamp',
  });

  // Cursor
  const cursorWaypoints = [
    { x: 960, y: 200, frame: 110 },
    { x: 730, y: 430, frame: selectFrame, click: true },
    { x: 730, y: 680, frame: signatureFrame },
    { x: 770, y: 680, frame: signatureFrame + 40 },
  ];

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
          Your Proposal, Your Choice
        </h2>
        <p style={{ fontSize: TEXT.label, color: BRAND.gray, marginTop: 8, fontFamily: FONT.system }}>
          Pick a package and sign right from your phone
        </p>
      </div>

      {/* Browser mockup */}
      <div
        style={{
          position: 'absolute',
          top: 140,
          left: 140,
          right: 140,
          bottom: 30,
          opacity: browserOpacity,
          transform: `scale(${Math.max(0.85, browserScale)})`,
        }}
      >
        <MockupFrame type="browser" url={`${data.website}/proposal`}>
          <div style={{ padding: '30px 40px', height: '100%' }}>
            {/* Proposal header */}
            <div style={{ textAlign: 'center', marginBottom: 24 }}>
              <h3 style={{ fontSize: 30, fontWeight: 700, color: BRAND.navy, margin: 0, fontFamily: FONT.system }}>
                Your Painting Proposal
              </h3>
              <p style={{ fontSize: 18, color: BRAND.gray, marginTop: 6, fontFamily: FONT.system }}>
                123 Maple Street, {data.city}
              </p>
            </div>

            {/* Package cards */}
            <div style={{ display: 'flex', gap: 20, justifyContent: 'center', marginBottom: 24 }}>
              {packages.map((pkg, i) => {
                const isSelected = i === 1 && frame >= selectFrame;
                return (
                  <div
                    key={pkg.name}
                    style={{
                      width: 240,
                      borderRadius: 14,
                      overflow: 'hidden',
                      transform: `scale(${Math.max(0, cardScales[i])})`,
                      boxShadow: isSelected
                        ? `0 0 0 3px ${pkg.color}, 0 8px 30px ${pkg.color}40`
                        : '0 4px 16px rgba(0,0,0,0.06)',
                      backgroundColor: BRAND.white,
                    }}
                  >
                    <div
                      style={{
                        backgroundColor: pkg.color,
                        padding: '14px 0',
                        textAlign: 'center',
                      }}
                    >
                      <span style={{ fontSize: 22, fontWeight: 700, color: BRAND.white, fontFamily: FONT.system }}>
                        {pkg.name}
                      </span>
                    </div>
                    <div style={{ textAlign: 'center', padding: '16px 0 10px' }}>
                      <span style={{ fontSize: 36, fontWeight: 800, color: BRAND.dark, fontFamily: FONT.system }}>
                        ${pkg.price.toLocaleString()}
                      </span>
                    </div>
                    <div style={{ padding: '0 18px 18px' }}>
                      {pkg.features.map((f) => (
                        <div key={f} style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8 }}>
                          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke={BRAND.green} strokeWidth="3">
                            <polyline points="20 6 9 17 4 12" />
                          </svg>
                          <span style={{ fontSize: 16, color: BRAND.gray, fontFamily: FONT.system }}>{f}</span>
                        </div>
                      ))}
                    </div>
                    {isSelected && (
                      <div style={{ textAlign: 'center', paddingBottom: 14 }}>
                        <div
                          style={{
                            display: 'inline-flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            width: 32,
                            height: 32,
                            borderRadius: '50%',
                            backgroundColor: pkg.color,
                            transform: `scale(${Math.max(0, checkScale)})`,
                          }}
                        >
                          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke={BRAND.white} strokeWidth="3">
                            <polyline points="20 6 9 17 4 12" />
                          </svg>
                        </div>
                      </div>
                    )}
                  </div>
                );
              })}
            </div>

            {/* Signature area */}
            <div style={{ opacity: sigOpacity }}>
              <div
                style={{
                  border: '2px dashed #D1D5DB',
                  borderRadius: 12,
                  padding: '14px 24px',
                  maxWidth: 380,
                  margin: '0 auto',
                }}
              >
                <span style={{ fontSize: 12, color: BRAND.gray, fontFamily: FONT.system }}>Sign here</span>
                <div style={{ marginTop: 6, height: 36, overflow: 'hidden' }}>
                  <svg width={sigWidth} height="36" viewBox="0 0 200 36">
                    <path
                      d="M5 28 Q 30 5, 55 22 T 105 18 T 155 22 T 195 12"
                      fill="none"
                      stroke={BRAND.navy}
                      strokeWidth="2.5"
                      strokeLinecap="round"
                    />
                  </svg>
                </div>
              </div>
            </div>

            {/* Accepted badge */}
            {frame >= acceptedFrame && (
              <div
                style={{
                  position: 'absolute',
                  top: '50%',
                  left: '50%',
                  transform: `translate(-50%, -50%) scale(${Math.max(0, acceptedScale)}) rotate(-12deg)`,
                }}
              >
                <div
                  style={{
                    border: `5px solid ${BRAND.green}`,
                    borderRadius: 16,
                    padding: '18px 44px',
                    backgroundColor: 'rgba(255,255,255,0.96)',
                  }}
                >
                  <span
                    style={{
                      fontSize: 48,
                      fontWeight: 800,
                      color: BRAND.green,
                      fontFamily: FONT.system,
                      letterSpacing: 4,
                    }}
                  >
                    ACCEPTED
                  </span>
                </div>
              </div>
            )}
          </div>
        </MockupFrame>
      </div>

      <AnimatedCursor waypoints={cursorWaypoints} />
    </AbsoluteFill>
  );
};
