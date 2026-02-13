import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, TEXT, SPRING_CONFIGS } from '../brand';
import { PromoVideoProps } from '../types';
import { FloatingParticles } from '../components/FloatingParticles';

export const CTAScene: React.FC<{ data: PromoVideoProps }> = ({ data }) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Big company name
  const nameScale = spring({ frame: frame - 5, fps, config: SPRING_CONFIGS.bouncy });
  const nameOpacity = interpolate(frame, [5, 25], [0, 1], { extrapolateRight: 'clamp' });

  // City tagline
  const cityOpacity = interpolate(frame, [25, 45], [0, 1], { extrapolateRight: 'clamp' });
  const citySlide = interpolate(frame, [25, 45], [20, 0], { extrapolateRight: 'clamp' });

  // Divider
  const dividerWidth = interpolate(frame, [40, 65], [0, 400], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // Website
  const websiteFrame = 65;
  const websiteScale = spring({ frame: frame - websiteFrame, fps, config: SPRING_CONFIGS.snappy });
  const websiteOpacity = interpolate(frame, [websiteFrame, websiteFrame + 15], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // CTA button
  const ctaFrame = 100;
  const ctaScale = spring({ frame: frame - ctaFrame, fps, config: SPRING_CONFIGS.bouncy });
  const ctaPulse = frame >= ctaFrame + 20 ? Math.sin((frame - ctaFrame) * 0.08) * 0.03 + 1 : Math.max(0, ctaScale);

  // Bottom tagline
  const taglineFrame = 130;
  const taglineOpacity = interpolate(frame, [taglineFrame, taglineFrame + 15], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // No full fade out - loops back
  const fadeIn = interpolate(frame, [0, 10], [0, 1], { extrapolateRight: 'clamp' });

  return (
    <AbsoluteFill
      style={{
        background: `linear-gradient(135deg, ${BRAND.navy} 0%, #0F1A2E 40%, ${BRAND.navy} 100%)`,
        opacity: fadeIn,
      }}
    >
      <FloatingParticles count={20} color={`${BRAND.sky}10`} seed={77} />

      {/* Accent glow */}
      <div
        style={{
          position: 'absolute',
          top: '50%',
          left: '50%',
          transform: 'translate(-50%, -50%)',
          width: 700,
          height: 700,
          borderRadius: '50%',
          background: `radial-gradient(circle, ${BRAND.blue}18, transparent 70%)`,
        }}
      />

      <AbsoluteFill style={{ justifyContent: 'center', alignItems: 'center' }}>
        {/* Paint brush icon */}
        <div
          style={{
            opacity: nameOpacity,
            transform: `scale(${Math.max(0, nameScale)})`,
            marginBottom: 24,
          }}
        >
          <div
            style={{
              width: 90,
              height: 90,
              borderRadius: 22,
              background: `linear-gradient(135deg, ${BRAND.blue}, ${BRAND.sky})`,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              boxShadow: `0 10px 40px ${BRAND.blue}50`,
            }}
          >
            <svg width="50" height="50" viewBox="0 0 24 24" fill="none" stroke={BRAND.white} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
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
          }}
        >
          <h1
            style={{
              fontSize: TEXT.hero + 10,
              fontWeight: 800,
              color: BRAND.white,
              margin: 0,
              fontFamily: FONT.system,
              letterSpacing: -2,
              lineHeight: 1,
            }}
          >
            {data.companyName}
          </h1>
        </div>

        {/* City */}
        <div
          style={{
            opacity: cityOpacity,
            transform: `translateY(${citySlide}px)`,
            marginTop: 8,
          }}
        >
          <span
            style={{
              fontSize: TEXT.subheading + 4,
              fontWeight: 300,
              color: BRAND.sky,
              fontFamily: FONT.system,
              letterSpacing: 6,
              textTransform: 'uppercase',
            }}
          >
            {data.tagline}
          </span>
        </div>

        {/* Divider */}
        <div
          style={{
            width: dividerWidth,
            height: 3,
            background: `linear-gradient(90deg, transparent, ${BRAND.sky}, transparent)`,
            borderRadius: 2,
            marginTop: 30,
            marginBottom: 30,
          }}
        />

        {/* Website */}
        <div
          style={{
            opacity: websiteOpacity,
            transform: `scale(${Math.max(0, websiteScale)})`,
            display: 'flex',
            alignItems: 'center',
            gap: 14,
            marginBottom: 30,
          }}
        >
          <div
            style={{
              width: 44,
              height: 44,
              borderRadius: '50%',
              backgroundColor: `${BRAND.blue}25`,
              border: `2px solid ${BRAND.blue}40`,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
            }}
          >
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke={BRAND.blue} strokeWidth="2" strokeLinecap="round">
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
        <div
          style={{
            transform: `scale(${ctaPulse})`,
          }}
        >
          <div
            style={{
              background: `linear-gradient(135deg, ${BRAND.green}, #059669)`,
              borderRadius: 16,
              padding: '22px 60px',
              boxShadow: `0 8px 30px ${BRAND.green}50`,
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
              GET YOUR FREE ESTIMATE
            </span>
          </div>
        </div>

        {/* Bottom tagline */}
        <div
          style={{
            opacity: taglineOpacity,
            marginTop: 35,
          }}
        >
          <p
            style={{
              fontSize: TEXT.label,
              fontWeight: 400,
              color: `${BRAND.white}60`,
              margin: 0,
              fontFamily: FONT.system,
              fontStyle: 'italic',
            }}
          >
            Make it easy. We already did.
          </p>
        </div>
      </AbsoluteFill>
    </AbsoluteFill>
  );
};
