import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, TEXT, SPRING_CONFIGS } from '../brand';
import { CustomerPortalProps } from '../types';

const highlights = [
  { icon: '📱', text: 'Customer Portal' },
  { icon: '📋', text: 'Instant Estimates' },
  { icon: '📈', text: 'Live Tracking' },
];

// Seeded random for particles
const seededRandom = (seed: number): number => {
  const x = Math.sin(seed * 9301 + 49297) * 233280;
  return x - Math.floor(x);
};

export const CTAScene: React.FC<{ data: CustomerPortalProps }> = ({ data }) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  const fadeIn = interpolate(frame, [0, 15], [0, 1], { extrapolateRight: 'clamp' });

  // Glow pulse
  const glowPulse = Math.sin(frame * 0.06) * 0.15 + 0.85;

  // Logo
  const logoScale = spring({ frame: frame - 8, fps, config: SPRING_CONFIGS.bouncy });
  const logoOpacity = interpolate(frame, [8, 24], [0, 1], { extrapolateRight: 'clamp' });

  // Company name
  const nameOpacity = interpolate(frame, [20, 38], [0, 1], { extrapolateRight: 'clamp' });
  const nameScale = spring({ frame: frame - 20, fps, config: SPRING_CONFIGS.gentle });

  // Divider
  const dividerWidth = interpolate(frame, [42, 65], [0, 500], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // Highlights
  const highlightScales = highlights.map((_, i) =>
    spring({ frame: frame - (68 + i * 14), fps, config: SPRING_CONFIGS.snappy })
  );
  const highlightOpacities = highlights.map((_, i) =>
    interpolate(frame, [68 + i * 14, 82 + i * 14], [0, 1], { extrapolateRight: 'clamp' })
  );

  // CTA button
  const ctaFrame = 120;
  const ctaScale = spring({ frame: frame - ctaFrame, fps, config: SPRING_CONFIGS.bouncy });
  const ctaPulse = frame >= ctaFrame + 20
    ? Math.sin((frame - ctaFrame) * 0.09) * 0.025 + 1
    : Math.max(0, ctaScale);

  // Website
  const webFrame = 100;
  const webOpacity = interpolate(frame, [webFrame, webFrame + 18], [0, 1], { extrapolateRight: 'clamp' });
  const webScale = spring({ frame: frame - webFrame, fps, config: SPRING_CONFIGS.snappy });

  // Bottom tagline
  const taglineOpacity = interpolate(frame, [148, 165], [0, 1], { extrapolateRight: 'clamp' });

  // Floating particles
  const particles = Array.from({ length: 22 }, (_, i) => ({
    x: seededRandom(i * 7) * 100,
    y: seededRandom(i * 13) * 100,
    size: 3 + seededRandom(i * 19) * 10,
    speed: 0.25 + seededRandom(i * 23) * 0.6,
    delay: seededRandom(i * 31) * 50,
  }));

  return (
    <AbsoluteFill
      style={{
        background: `linear-gradient(135deg, #08101F 0%, #0D1A32 40%, ${BRAND.navy} 100%)`,
        opacity: fadeIn,
      }}
    >
      {/* Grid background */}
      <div
        style={{
          position: 'absolute',
          inset: 0,
          backgroundImage: `
            linear-gradient(${BRAND.blue}06 1px, transparent 1px),
            linear-gradient(90deg, ${BRAND.blue}06 1px, transparent 1px)
          `,
          backgroundSize: '70px 70px',
        }}
      />

      {/* Particles */}
      {particles.map((p, i) => {
        const yOffset = interpolate(frame, [p.delay, p.delay + 280], [0, -75 * p.speed], {
          extrapolateLeft: 'clamp',
          extrapolateRight: 'clamp',
        });
        const xWobble = Math.sin((frame + p.delay) * 0.035) * 12;
        return (
          <div
            key={i}
            style={{
              position: 'absolute',
              left: `${p.x}%`,
              top: `${p.y}%`,
              width: p.size,
              height: p.size,
              borderRadius: '50%',
              backgroundColor: i % 3 === 0 ? `${BRAND.sky}20` : i % 3 === 1 ? `${BRAND.green}15` : `${BRAND.purple}15`,
              transform: `translate(${xWobble}px, ${yOffset}px)`,
            }}
          />
        );
      })}

      {/* Center glow */}
      <div
        style={{
          position: 'absolute',
          top: '50%',
          left: '50%',
          transform: 'translate(-50%, -50%)',
          width: 800,
          height: 600,
          borderRadius: '50%',
          background: `radial-gradient(ellipse, ${BRAND.blue}20 0%, ${BRAND.purple}10 45%, transparent 70%)`,
          opacity: glowPulse,
        }}
      />

      {/* Top accent */}
      <div
        style={{
          position: 'absolute',
          top: 0,
          left: 0,
          right: 0,
          height: 4,
          background: `linear-gradient(90deg, ${BRAND.blue}, ${BRAND.sky}, ${BRAND.green}, ${BRAND.amber})`,
        }}
      />

      <AbsoluteFill style={{ justifyContent: 'center', alignItems: 'center' }}>
        {/* Logo icon */}
        <div
          style={{
            opacity: logoOpacity,
            transform: `scale(${Math.max(0, logoScale)})`,
            marginBottom: 20,
          }}
        >
          <div
            style={{
              width: 96,
              height: 96,
              borderRadius: 24,
              background: `linear-gradient(135deg, ${BRAND.blue}, ${BRAND.sky})`,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              boxShadow: `0 12px 50px ${BRAND.blue}50`,
            }}
          >
            <svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke={BRAND.white} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <path d="M18.37 2.63 14 7l-1.59-1.59a2 2 0 0 0-2.82 0L8 7l9 9 1.59-1.59a2 2 0 0 0 0-2.82L17 10l4.37-4.37a2.12 2.12 0 1 0-3-3Z" />
              <path d="M9 8c-2 3-4 3.5-7 4l8 10c2-1 6-5 6-7" />
              <path d="M14.5 17.5 4.5 15" />
            </svg>
          </div>
        </div>

        {/* Company name */}
        <div
          style={{
            opacity: nameOpacity,
            transform: `scale(${Math.max(0, nameScale)})`,
            textAlign: 'center',
            marginBottom: 8,
          }}
        >
          <h1
            style={{
              fontSize: TEXT.hero + 5,
              fontWeight: 800,
              color: BRAND.white,
              margin: 0,
              fontFamily: FONT.system,
              letterSpacing: -2,
              lineHeight: 1.05,
            }}
          >
            {data.companyName}
          </h1>
          <p
            style={{
              fontSize: TEXT.subheading,
              fontWeight: 300,
              color: BRAND.sky,
              margin: '6px 0 0',
              fontFamily: FONT.system,
              letterSpacing: 6,
              textTransform: 'uppercase',
            }}
          >
            {data.city}
          </p>
        </div>

        {/* Divider */}
        <div
          style={{
            width: dividerWidth,
            height: 3,
            background: `linear-gradient(90deg, transparent, ${BRAND.sky}, ${BRAND.green}, transparent)`,
            borderRadius: 2,
            marginTop: 24,
            marginBottom: 28,
          }}
        />

        {/* Feature highlights */}
        <div style={{ display: 'flex', gap: 16, marginBottom: 32 }}>
          {highlights.map((h, i) => (
            <div
              key={h.text}
              style={{
                opacity: highlightOpacities[i],
                transform: `scale(${Math.max(0, highlightScales[i])})`,
                display: 'flex',
                alignItems: 'center',
                gap: 8,
                backgroundColor: `${BRAND.white}10`,
                border: `1px solid ${BRAND.white}18`,
                borderRadius: 12,
                padding: '10px 18px',
              }}
            >
              <span style={{ fontSize: 22 }}>{h.icon}</span>
              <span style={{ fontSize: TEXT.label, fontWeight: 600, color: BRAND.white, fontFamily: FONT.system }}>
                {h.text}
              </span>
            </div>
          ))}
        </div>

        {/* Website */}
        <div
          style={{
            opacity: webOpacity,
            transform: `scale(${Math.max(0, webScale)})`,
            display: 'flex',
            alignItems: 'center',
            gap: 12,
            marginBottom: 28,
          }}
        >
          <div
            style={{
              width: 40,
              height: 40,
              borderRadius: '50%',
              backgroundColor: `${BRAND.blue}25`,
              border: `2px solid ${BRAND.blue}40`,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
            }}
          >
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke={BRAND.sky} strokeWidth="2" strokeLinecap="round">
              <circle cx="12" cy="12" r="10" />
              <line x1="2" y1="12" x2="22" y2="12" />
              <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" />
            </svg>
          </div>
          <span style={{ fontSize: TEXT.body, fontWeight: 600, color: BRAND.sky, fontFamily: FONT.system }}>
            {data.website}
          </span>
        </div>

        {/* CTA button */}
        <div style={{ transform: `scale(${ctaPulse})` }}>
          <div
            style={{
              background: `linear-gradient(135deg, ${BRAND.green}, #059669)`,
              borderRadius: 18,
              padding: '22px 64px',
              boxShadow: `0 10px 40px ${BRAND.green}55`,
            }}
          >
            <span
              style={{
                fontSize: TEXT.body + 2,
                fontWeight: 700,
                color: BRAND.white,
                fontFamily: FONT.system,
                letterSpacing: 1,
              }}
            >
              GET YOUR FREE ESTIMATE →
            </span>
          </div>
        </div>

        {/* Tagline */}
        <div style={{ opacity: taglineOpacity, marginTop: 30 }}>
          <p
            style={{
              fontSize: TEXT.label,
              fontWeight: 400,
              color: `${BRAND.white}55`,
              margin: 0,
              fontFamily: FONT.system,
              fontStyle: 'italic',
            }}
          >
            Your home. Our promise. Made easy.
          </p>
        </div>
      </AbsoluteFill>
    </AbsoluteFill>
  );
};
