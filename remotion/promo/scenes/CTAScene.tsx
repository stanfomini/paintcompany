import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, SPRING_CONFIGS } from '../brand';
import { PromoVideoProps } from '../types';
import { FloatingParticles } from '../components/FloatingParticles';
import { CountUpNumber } from '../components/CountUpNumber';

export const CTAScene: React.FC<{ data: PromoVideoProps }> = ({ data }) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Headline
  const headlineScale = spring({ frame: frame - 10, fps, config: SPRING_CONFIGS.gentle });
  const headlineOpacity = interpolate(frame, [10, 30], [0, 1], { extrapolateRight: 'clamp' });

  // Subheadline
  const subOpacity = interpolate(frame, [30, 50], [0, 1], { extrapolateRight: 'clamp' });
  const subSlide = interpolate(frame, [30, 50], [20, 0], { extrapolateRight: 'clamp' });

  // Divider
  const dividerWidth = interpolate(frame, [45, 70], [0, 300], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // Contact info
  const phoneFrame = 80;
  const phoneScale = spring({ frame: frame - phoneFrame, fps, config: SPRING_CONFIGS.snappy });
  const phoneOpacity = interpolate(frame, [phoneFrame, phoneFrame + 15], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  const websiteFrame = 110;
  const websiteScale = spring({ frame: frame - websiteFrame, fps, config: SPRING_CONFIGS.snappy });
  const websiteOpacity = interpolate(frame, [websiteFrame, websiteFrame + 15], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // QR code
  const qrFrame = 150;
  const qrScale = spring({ frame: frame - qrFrame, fps, config: SPRING_CONFIGS.bouncy });
  const qrOpacity = interpolate(frame, [qrFrame, qrFrame + 15], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // Stats row
  const statsFrame = 200;
  const stat1Scale = spring({ frame: frame - statsFrame, fps, config: SPRING_CONFIGS.snappy });
  const stat2Scale = spring({ frame: frame - statsFrame - 20, fps, config: SPRING_CONFIGS.snappy });
  const stat3Scale = spring({ frame: frame - statsFrame - 40, fps, config: SPRING_CONFIGS.snappy });
  const statScales = [stat1Scale, stat2Scale, stat3Scale];

  // CTA button pulse
  const ctaFrame = 300;
  const ctaScale = spring({ frame: frame - ctaFrame, fps, config: SPRING_CONFIGS.bouncy });
  const ctaPulse = frame >= ctaFrame ? Math.sin((frame - ctaFrame) * 0.08) * 0.03 + 1 : 0;

  // Company logo at bottom
  const logoFrame = 350;
  const logoOpacity = interpolate(frame, [logoFrame, logoFrame + 20], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // Overall - no full fade out since it loops
  const fadeIn = interpolate(frame, [0, 15], [0, 1], { extrapolateRight: 'clamp' });

  const stats = [
    { label: 'Homes Painted', value: 500 },
    { label: 'Happy Customers', value: 480 },
    { label: 'Years Experience', value: 15 },
  ];

  return (
    <AbsoluteFill
      style={{
        background: `linear-gradient(135deg, ${BRAND.navy} 0%, #0F1A2E 40%, ${BRAND.navy} 100%)`,
        opacity: fadeIn,
      }}
    >
      <FloatingParticles count={25} color={`${BRAND.sky}12`} seed={99} />

      {/* Accent circles */}
      <div
        style={{
          position: 'absolute',
          top: -150,
          left: -150,
          width: 500,
          height: 500,
          borderRadius: '50%',
          background: `radial-gradient(circle, ${BRAND.blue}20, transparent 70%)`,
        }}
      />
      <div
        style={{
          position: 'absolute',
          bottom: -200,
          right: -200,
          width: 600,
          height: 600,
          borderRadius: '50%',
          background: `radial-gradient(circle, ${BRAND.green}15, transparent 70%)`,
        }}
      />

      <AbsoluteFill style={{ justifyContent: 'center', alignItems: 'center' }}>
        {/* Main headline */}
        <div
          style={{
            opacity: headlineOpacity,
            transform: `scale(${Math.max(0, headlineScale)})`,
            textAlign: 'center',
            marginBottom: 10,
          }}
        >
          <h1
            style={{
              fontSize: 64,
              fontWeight: 800,
              color: BRAND.white,
              margin: 0,
              fontFamily: FONT.system,
              letterSpacing: -1,
              lineHeight: 1.2,
            }}
          >
            GET YOUR FREE
            <br />
            <span style={{ color: BRAND.sky }}>ESTIMATE TODAY</span>
          </h1>
        </div>

        {/* Subheadline */}
        <div
          style={{
            opacity: subOpacity,
            transform: `translateY(${subSlide}px)`,
            textAlign: 'center',
            marginBottom: 20,
          }}
        >
          <p style={{ fontSize: 26, color: 'rgba(255,255,255,0.7)', margin: 0, fontFamily: FONT.system }}>
            Professional painting services for your home
          </p>
        </div>

        {/* Divider */}
        <div
          style={{
            width: dividerWidth,
            height: 3,
            background: `linear-gradient(90deg, transparent, ${BRAND.sky}, transparent)`,
            borderRadius: 2,
            marginBottom: 35,
          }}
        />

        {/* Contact info */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 60, marginBottom: 30 }}>
          {/* Phone */}
          <div
            style={{
              opacity: phoneOpacity,
              transform: `scale(${Math.max(0, phoneScale)})`,
              display: 'flex',
              alignItems: 'center',
              gap: 14,
            }}
          >
            <div
              style={{
                width: 50,
                height: 50,
                borderRadius: '50%',
                backgroundColor: `${BRAND.green}20`,
                border: `2px solid ${BRAND.green}40`,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
              }}
            >
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke={BRAND.green} strokeWidth="2" strokeLinecap="round">
                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" />
              </svg>
            </div>
            <span style={{ fontSize: 28, fontWeight: 700, color: BRAND.white, fontFamily: FONT.system }}>
              {data.phone}
            </span>
          </div>

          {/* Website */}
          <div
            style={{
              opacity: websiteOpacity,
              transform: `scale(${Math.max(0, websiteScale)})`,
              display: 'flex',
              alignItems: 'center',
              gap: 14,
            }}
          >
            <div
              style={{
                width: 50,
                height: 50,
                borderRadius: '50%',
                backgroundColor: `${BRAND.blue}20`,
                border: `2px solid ${BRAND.blue}40`,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
              }}
            >
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke={BRAND.blue} strokeWidth="2" strokeLinecap="round">
                <circle cx="12" cy="12" r="10" />
                <line x1="2" y1="12" x2="22" y2="12" />
                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" />
              </svg>
            </div>
            <span style={{ fontSize: 24, fontWeight: 600, color: BRAND.sky, fontFamily: FONT.system }}>
              {data.website}
            </span>
          </div>
        </div>

        {/* QR Code */}
        <div
          style={{
            opacity: qrOpacity,
            transform: `scale(${Math.max(0, qrScale)})`,
            marginBottom: 35,
          }}
        >
          <div
            style={{
              width: 140,
              height: 140,
              backgroundColor: BRAND.white,
              borderRadius: 16,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              flexDirection: 'column',
              boxShadow: '0 8px 30px rgba(0,0,0,0.3)',
            }}
          >
            {/* QR code placeholder pattern */}
            <svg width="90" height="90" viewBox="0 0 24 24" fill="none" stroke={BRAND.navy} strokeWidth="1.5">
              <rect x="2" y="2" width="7" height="7" rx="1" />
              <rect x="15" y="2" width="7" height="7" rx="1" />
              <rect x="2" y="15" width="7" height="7" rx="1" />
              <rect x="4" y="4" width="3" height="3" />
              <rect x="17" y="4" width="3" height="3" />
              <rect x="4" y="17" width="3" height="3" />
              <rect x="15" y="15" width="4" height="4" rx="0.5" />
              <rect x="11" y="11" width="2" height="2" />
              <rect x="11" y="2" width="2" height="4" />
              <rect x="2" y="11" width="4" height="2" />
            </svg>
            <span style={{ fontSize: 11, color: BRAND.gray, marginTop: 4, fontWeight: 600, fontFamily: FONT.system }}>
              Scan for Quote
            </span>
          </div>
        </div>

        {/* Stats row */}
        <div style={{ display: 'flex', gap: 60, marginBottom: 40 }}>
          {stats.map((stat, i) => (
            <div
              key={stat.label}
              style={{
                textAlign: 'center',
                transform: `scale(${Math.max(0, statScales[i])})`,
              }}
            >
              <CountUpNumber
                value={stat.value}
                startFrame={statsFrame + i * 20}
                duration={40}
                suffix="+"
                style={{
                  fontSize: 42,
                  fontWeight: 800,
                  color: BRAND.sky,
                  fontFamily: FONT.system,
                }}
              />
              <p style={{ fontSize: 16, color: 'rgba(255,255,255,0.5)', margin: '4px 0 0', fontFamily: FONT.system }}>
                {stat.label}
              </p>
            </div>
          ))}
        </div>

        {/* CTA button */}
        {frame >= ctaFrame && (
          <div
            style={{
              transform: `scale(${Math.max(0, ctaScale) * ctaPulse})`,
            }}
          >
            <div
              style={{
                background: `linear-gradient(135deg, ${BRAND.green}, #059669)`,
                borderRadius: 16,
                padding: '20px 60px',
                boxShadow: `0 8px 30px ${BRAND.green}50`,
              }}
            >
              <span
                style={{
                  fontSize: 28,
                  fontWeight: 700,
                  color: BRAND.white,
                  fontFamily: FONT.system,
                  letterSpacing: 1,
                }}
              >
                SCHEDULE YOUR FREE ESTIMATE
              </span>
            </div>
          </div>
        )}
      </AbsoluteFill>

      {/* Company branding footer */}
      <div
        style={{
          position: 'absolute',
          bottom: 30,
          left: 0,
          right: 0,
          display: 'flex',
          justifyContent: 'center',
          opacity: logoOpacity,
        }}
      >
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <div
            style={{
              width: 40,
              height: 40,
              borderRadius: 10,
              background: `linear-gradient(135deg, ${BRAND.blue}, ${BRAND.sky})`,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
            }}
          >
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke={BRAND.white} strokeWidth="2">
              <path d="M18.37 2.63 14 7l-1.59-1.59a2 2 0 0 0-2.82 0L8 7l9 9 1.59-1.59a2 2 0 0 0 0-2.82L17 10l4.37-4.37a2.12 2.12 0 1 0-3-3Z" />
              <path d="M9 8c-2 3-4 3.5-7 4l8 10c2-1 6-5 6-7" />
            </svg>
          </div>
          <span style={{ fontSize: 20, fontWeight: 600, color: 'rgba(255,255,255,0.5)', fontFamily: FONT.system }}>
            {data.companyName}
          </span>
        </div>
      </div>
    </AbsoluteFill>
  );
};
