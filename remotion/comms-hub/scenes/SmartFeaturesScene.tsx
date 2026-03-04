import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, TEXT, SPRING_CONFIGS } from '../brand';
import { FloatingParticles } from '../components/FloatingParticles';

interface Feature {
  title: string;
  description: string;
  icon: React.ReactNode;
  color: string;
  delay: number;
}

const features: Feature[] = [
  {
    title: 'Auto-Translation',
    description: 'Messages detected and translated in real-time. English-Spanish bidirectional.',
    color: BRAND.sky,
    delay: 30,
    icon: (
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <path d="m5 8 6 6M4 14l6-6 2-3M2 5h12M7 2h1" />
        <path d="m22 22-5-10-5 10M14 18h6" />
      </svg>
    ),
  },
  {
    title: 'Smart Notifications',
    description: 'Unread badges across all channels. Real-time WebSocket + 30s polling fallback.',
    color: BRAND.red,
    delay: 55,
    icon: (
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
        <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
      </svg>
    ),
  },
  {
    title: 'Notification Routing',
    description: 'Automatically routes alerts to the right person based on deal assignment and role.',
    color: BRAND.purple,
    delay: 80,
    icon: (
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <circle cx="12" cy="12" r="3" />
        <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83" />
      </svg>
    ),
  },
  {
    title: 'CRM Auto-Linking',
    description: 'Emails auto-linked to customers, deals, and properties by address matching.',
    color: BRAND.green,
    delay: 105,
    icon: (
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
      </svg>
    ),
  },
  {
    title: 'Audio Messages',
    description: 'Record and send voice notes with automatic transcription metadata.',
    color: BRAND.amber,
    delay: 130,
    icon: (
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z" />
        <path d="M19 10v2a7 7 0 0 1-14 0v-2" />
        <line x1="12" y1="19" x2="12" y2="22" />
      </svg>
    ),
  },
  {
    title: 'Email Signatures',
    description: 'Per-account HTML signatures auto-appended to every outbound email.',
    color: BRAND.indigo,
    delay: 155,
    icon: (
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <path d="m16 3 5 5-11 11H5v-5z" />
        <path d="M14 5l5 5" />
      </svg>
    ),
  },
];

export const SmartFeaturesScene: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  const titleOpacity = interpolate(frame, [0, 20], [0, 1], { extrapolateRight: 'clamp' });
  const titleSlide = interpolate(frame, [0, 20], [-30, 0], { extrapolateRight: 'clamp' });

  const fadeOut = interpolate(frame, [durationInFrames - 15, durationInFrames], [1, 0], { extrapolateLeft: 'clamp' });

  return (
    <AbsoluteFill style={{ backgroundColor: BRAND.white, opacity: fadeOut }}>
      <FloatingParticles count={15} color={`${BRAND.purple}08`} seed={66} />

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
        <h2 style={{ fontSize: TEXT.heading - 8, fontWeight: 800, color: BRAND.navy, margin: 0, fontFamily: FONT.system }}>
          Smart Features
        </h2>
        <p style={{ fontSize: TEXT.label, color: BRAND.gray, marginTop: 6, fontFamily: FONT.system }}>
          Intelligence built into every interaction
        </p>
      </div>

      {/* Feature grid - 3x2 */}
      <div
        style={{
          position: 'absolute',
          top: 160,
          left: 100,
          right: 100,
          display: 'grid',
          gridTemplateColumns: '1fr 1fr 1fr',
          gap: '28px',
        }}
      >
        {features.map((feature) => {
          const cardScale = spring({ frame: frame - feature.delay, fps, config: SPRING_CONFIGS.snappy });
          const cardOpacity = interpolate(frame, [feature.delay, feature.delay + 10], [0, 1], {
            extrapolateLeft: 'clamp',
            extrapolateRight: 'clamp',
          });

          return (
            <div
              key={feature.title}
              style={{
                opacity: cardOpacity,
                transform: `scale(${Math.max(0, cardScale)})`,
                transformOrigin: 'center center',
              }}
            >
              <div
                style={{
                  backgroundColor: BRAND.lightGray,
                  borderRadius: 20,
                  padding: '28px 24px',
                  border: `1px solid ${feature.color}15`,
                  height: '100%',
                  display: 'flex',
                  flexDirection: 'column',
                  gap: 14,
                }}
              >
                <div
                  style={{
                    width: 60,
                    height: 60,
                    borderRadius: 16,
                    backgroundColor: `${feature.color}12`,
                    border: `2px solid ${feature.color}25`,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    color: feature.color,
                  }}
                >
                  {feature.icon}
                </div>
                <h3 style={{ fontSize: TEXT.label, fontWeight: 700, color: BRAND.dark, margin: 0, fontFamily: FONT.system }}>
                  {feature.title}
                </h3>
                <p style={{ fontSize: TEXT.small - 2, color: BRAND.gray, margin: 0, fontFamily: FONT.system, lineHeight: 1.5 }}>
                  {feature.description}
                </p>
              </div>
            </div>
          );
        })}
      </div>
    </AbsoluteFill>
  );
};
