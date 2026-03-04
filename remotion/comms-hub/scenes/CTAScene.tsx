import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, TEXT, SPRING_CONFIGS } from '../brand';
import { FloatingParticles } from '../components/FloatingParticles';
import { CommsHubProps } from '../types';

export const CTAScene: React.FC<{ data: CommsHubProps }> = ({ data }) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Logo
  const logoScale = spring({ frame: frame - 5, fps, config: SPRING_CONFIGS.bouncy });

  // Headline
  const headlineOpacity = interpolate(frame, [20, 40], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });
  const headlineSlide = interpolate(frame, [20, 40], [40, 0], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });

  // Tagline
  const taglineOpacity = interpolate(frame, [45, 65], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });

  // Channel icons
  const iconsOpacity = interpolate(frame, [60, 80], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });

  // CTA button
  const buttonScale = spring({ frame: frame - 80, fps, config: SPRING_CONFIGS.bouncy });
  const buttonPulse = 1 + Math.sin(frame * 0.1) * 0.03;

  // Website
  const websiteOpacity = interpolate(frame, [100, 115], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });

  return (
    <AbsoluteFill
      style={{
        background: `radial-gradient(ellipse at center, ${BRAND.navy} 0%, ${BRAND.dark} 70%)`,
      }}
    >
      <FloatingParticles count={30} color={`${BRAND.blue}12`} seed={111} />

      {/* Centered content */}
      <div
        style={{
          position: 'absolute',
          top: 0,
          left: 0,
          right: 0,
          bottom: 0,
          display: 'flex',
          flexDirection: 'column',
          alignItems: 'center',
          justifyContent: 'center',
        }}
      >
        {/* Logo mark */}
        <div
          style={{
            width: 80,
            height: 80,
            borderRadius: 20,
            background: `linear-gradient(135deg, ${BRAND.blue}, ${BRAND.sky})`,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            transform: `scale(${Math.max(0, logoScale)})`,
            marginBottom: 30,
            boxShadow: `0 0 60px ${BRAND.blue}40`,
          }}
        >
          <span style={{ color: BRAND.white, fontWeight: 800, fontSize: 36, fontFamily: FONT.system }}>A</span>
        </div>

        {/* Headline */}
        <div
          style={{
            opacity: headlineOpacity,
            transform: `translateY(${headlineSlide}px)`,
            textAlign: 'center',
          }}
        >
          <h2
            style={{
              fontSize: TEXT.hero - 4,
              fontWeight: 800,
              color: BRAND.white,
              margin: 0,
              fontFamily: FONT.system,
              letterSpacing: -2,
              lineHeight: 1.15,
            }}
          >
            Every Conversation.
            <br />
            <span
              style={{
                background: `linear-gradient(135deg, ${BRAND.sky}, ${BRAND.green})`,
                WebkitBackgroundClip: 'text',
                WebkitTextFillColor: 'transparent',
              }}
            >
              One Place.
            </span>
          </h2>
        </div>

        {/* Channel icons row */}
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 16,
            marginTop: 35,
            opacity: iconsOpacity,
          }}
        >
          {[
            { label: 'Chat', color: BRAND.gray },
            { label: 'Email', color: BRAND.amber },
            { label: 'Messenger', color: BRAND.blue },
            { label: 'Deals', color: BRAND.purple },
          ].map((ch, i) => {
            const chipScale = spring({ frame: frame - (60 + i * 8), fps, config: SPRING_CONFIGS.snappy });
            return (
              <div
                key={ch.label}
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: 8,
                  backgroundColor: `${ch.color}20`,
                  border: `1px solid ${ch.color}40`,
                  borderRadius: 10,
                  padding: '8px 16px',
                  transform: `scale(${Math.max(0, chipScale)})`,
                }}
              >
                <div
                  style={{
                    width: 8,
                    height: 8,
                    borderRadius: '50%',
                    backgroundColor: ch.color,
                  }}
                />
                <span style={{ fontSize: 15, fontWeight: 600, color: ch.color, fontFamily: FONT.system }}>
                  {ch.label}
                </span>
              </div>
            );
          })}
        </div>

        {/* Tagline */}
        <div style={{ opacity: taglineOpacity, marginTop: 25 }}>
          <p style={{ fontSize: TEXT.body, color: `${BRAND.white}99`, margin: 0, fontFamily: FONT.system }}>
            Stop losing customers to scattered communications.
          </p>
        </div>

        {/* CTA Button */}
        <div
          style={{
            marginTop: 40,
            transform: `scale(${Math.max(0, buttonScale) * buttonPulse})`,
          }}
        >
          <div
            style={{
              background: `linear-gradient(135deg, ${BRAND.blue}, ${BRAND.sky})`,
              borderRadius: 14,
              padding: '18px 48px',
              boxShadow: `0 8px 40px ${BRAND.blue}50`,
            }}
          >
            <span style={{ fontSize: TEXT.body, fontWeight: 700, color: BRAND.white, fontFamily: FONT.system }}>
              See It In Action
            </span>
          </div>
        </div>

        {/* Website */}
        <div style={{ opacity: websiteOpacity, marginTop: 25 }}>
          <span style={{ fontSize: TEXT.label, color: `${BRAND.white}66`, fontFamily: FONT.system }}>
            {data.website}
          </span>
        </div>
      </div>

      {/* Company name bottom */}
      <div
        style={{
          position: 'absolute',
          bottom: 35,
          left: 0,
          right: 0,
          textAlign: 'center',
        }}
      >
        <span style={{ fontSize: TEXT.small, color: `${BRAND.white}44`, fontFamily: FONT.system, fontWeight: 600, letterSpacing: 2 }}>
          {data.companyName.toUpperCase()} COMMUNICATIONS HUB
        </span>
      </div>
    </AbsoluteFill>
  );
};
