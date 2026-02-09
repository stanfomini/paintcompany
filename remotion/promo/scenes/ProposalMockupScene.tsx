import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, SPRING_CONFIGS, STAGGER_OFFSET } from '../brand';
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
    features: ['Walls + Ceiling + Trim', '2 Coats', 'Designer Paint'],
  },
];

export const ProposalMockupScene: React.FC<{ data: PromoVideoProps }> = ({ data }) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Scene title
  const titleOpacity = interpolate(frame, [0, 20], [0, 1], { extrapolateRight: 'clamp' });
  const titleSlide = interpolate(frame, [0, 20], [-30, 0], { extrapolateRight: 'clamp' });

  // Browser mockup
  const browserScale = spring({ frame: frame - 25, fps, config: SPRING_CONFIGS.gentle });
  const browserOpacity = interpolate(frame, [25, 45], [0, 1], { extrapolateRight: 'clamp' });

  // Package cards stagger
  const card0Scale = spring({ frame: frame - 60, fps, config: SPRING_CONFIGS.snappy });
  const card1Scale = spring({ frame: frame - 60 - STAGGER_OFFSET, fps, config: SPRING_CONFIGS.snappy });
  const card2Scale = spring({ frame: frame - 60 - STAGGER_OFFSET * 2, fps, config: SPRING_CONFIGS.snappy });
  const cardScales = [card0Scale, card1Scale, card2Scale];

  // "Better" card gets selected highlight
  const selectFrame = 160;
  const selectedGlow = interpolate(frame, [selectFrame, selectFrame + 15], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // Checkmark appears
  const checkScale = spring({ frame: frame - selectFrame - 10, fps, config: SPRING_CONFIGS.bouncy });

  // Signature animation
  const signatureFrame = 210;
  const sigOpacity = interpolate(frame, [signatureFrame, signatureFrame + 15], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });
  const sigWidth = interpolate(frame, [signatureFrame + 10, signatureFrame + 50], [0, 200], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // "Accepted" badge
  const acceptedFrame = 260;
  const acceptedScale = spring({ frame: frame - acceptedFrame, fps, config: SPRING_CONFIGS.bouncy });

  // Scene fade
  const fadeOut = interpolate(frame, [durationInFrames - 15, durationInFrames], [1, 0], {
    extrapolateLeft: 'clamp',
  });

  // Cursor waypoints
  const cursorWaypoints = [
    { x: 960, y: 200, frame: 130 },
    { x: 710, y: 440, frame: selectFrame, click: true },
    { x: 710, y: 700, frame: signatureFrame },
    { x: 750, y: 700, frame: signatureFrame + 50 },
  ];

  return (
    <AbsoluteFill style={{ backgroundColor: BRAND.lightGray, opacity: fadeOut }}>
      {/* Title */}
      <div
        style={{
          position: 'absolute',
          top: 50,
          left: 0,
          right: 0,
          textAlign: 'center',
          opacity: titleOpacity,
          transform: `translateY(${titleSlide}px)`,
        }}
      >
        <h2
          style={{
            fontSize: 48,
            fontWeight: 700,
            color: BRAND.navy,
            margin: 0,
            fontFamily: FONT.system,
          }}
        >
          Virtual Estimates & Proposals
        </h2>
        <p style={{ fontSize: 24, color: BRAND.gray, marginTop: 8, fontFamily: FONT.system }}>
          Customers choose their package and sign online
        </p>
      </div>

      {/* Browser mockup */}
      <div
        style={{
          position: 'absolute',
          top: 150,
          left: 160,
          right: 160,
          bottom: 40,
          opacity: browserOpacity,
          transform: `scale(${Math.max(0.8, browserScale)})`,
        }}
      >
        <MockupFrame type="browser" url={`${data.website}/proposal/abc123`}>
          <div style={{ padding: 40, height: '100%' }}>
            {/* Proposal header */}
            <div style={{ textAlign: 'center', marginBottom: 30 }}>
              <h3 style={{ fontSize: 28, fontWeight: 700, color: BRAND.navy, margin: 0, fontFamily: FONT.system }}>
                Your Painting Proposal
              </h3>
              <p style={{ fontSize: 16, color: BRAND.gray, marginTop: 6, fontFamily: FONT.system }}>
                123 Main Street, {data.city}
              </p>
            </div>

            {/* Package cards */}
            <div style={{ display: 'flex', gap: 24, justifyContent: 'center', marginBottom: 30 }}>
              {packages.map((pkg, i) => {
                const isSelected = i === 1 && frame >= selectFrame;
                return (
                  <div
                    key={pkg.name}
                    style={{
                      width: 260,
                      borderRadius: 16,
                      overflow: 'hidden',
                      transform: `scale(${Math.max(0, cardScales[i])})`,
                      boxShadow: isSelected
                        ? `0 0 0 3px ${pkg.color}, 0 8px 30px ${pkg.color}40`
                        : '0 4px 20px rgba(0,0,0,0.08)',
                      backgroundColor: BRAND.white,
                      transition: 'box-shadow 0.3s',
                    }}
                  >
                    {/* Card header */}
                    <div
                      style={{
                        backgroundColor: pkg.color,
                        padding: '16px 0',
                        textAlign: 'center',
                      }}
                    >
                      <span style={{ fontSize: 22, fontWeight: 700, color: BRAND.white, fontFamily: FONT.system }}>
                        {pkg.name}
                      </span>
                    </div>
                    {/* Price */}
                    <div style={{ textAlign: 'center', padding: '16px 0 10px' }}>
                      <span style={{ fontSize: 36, fontWeight: 800, color: BRAND.dark, fontFamily: FONT.system }}>
                        ${pkg.price.toLocaleString()}
                      </span>
                    </div>
                    {/* Features */}
                    <div style={{ padding: '0 20px 20px' }}>
                      {pkg.features.map((f) => (
                        <div
                          key={f}
                          style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: 8,
                            marginBottom: 8,
                          }}
                        >
                          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke={BRAND.green} strokeWidth="3">
                            <polyline points="20 6 9 17 4 12" />
                          </svg>
                          <span style={{ fontSize: 14, color: BRAND.gray, fontFamily: FONT.system }}>{f}</span>
                        </div>
                      ))}
                    </div>
                    {/* Selected checkmark */}
                    {isSelected && (
                      <div style={{ textAlign: 'center', paddingBottom: 16 }}>
                        <div
                          style={{
                            display: 'inline-flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            width: 36,
                            height: 36,
                            borderRadius: '50%',
                            backgroundColor: pkg.color,
                            transform: `scale(${Math.max(0, checkScale)})`,
                          }}
                        >
                          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke={BRAND.white} strokeWidth="3">
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
                  padding: '16px 24px',
                  maxWidth: 400,
                  margin: '0 auto',
                  position: 'relative',
                }}
              >
                <span style={{ fontSize: 12, color: BRAND.gray, fontFamily: FONT.system }}>Sign here</span>
                {/* Signature line */}
                <div
                  style={{
                    marginTop: 8,
                    height: 40,
                    overflow: 'hidden',
                  }}
                >
                  <svg width={sigWidth} height="40" viewBox="0 0 200 40">
                    <path
                      d="M5 30 Q 30 5, 55 25 T 105 20 T 155 25 T 195 15"
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
                    border: `4px solid ${BRAND.green}`,
                    borderRadius: 16,
                    padding: '16px 40px',
                    backgroundColor: 'rgba(255,255,255,0.95)',
                  }}
                >
                  <span
                    style={{
                      fontSize: 42,
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

      {/* Animated cursor */}
      <AnimatedCursor waypoints={cursorWaypoints} />
    </AbsoluteFill>
  );
};
