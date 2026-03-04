import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, TEXT, SPRING_CONFIGS } from '../brand';

interface ScatteredApp {
  name: string;
  color: string;
  icon: React.ReactNode;
  x: number;
  y: number;
  delay: number;
}

const scatteredApps: ScatteredApp[] = [
  {
    name: 'Gmail',
    color: '#EA4335',
    x: 15,
    y: 25,
    delay: 30,
    icon: (
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <rect x="2" y="4" width="20" height="16" rx="2" />
        <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" />
      </svg>
    ),
  },
  {
    name: 'Phone',
    color: '#10B981',
    x: 72,
    y: 18,
    delay: 45,
    icon: (
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z" />
      </svg>
    ),
  },
  {
    name: 'Messenger',
    color: '#2563EB',
    x: 40,
    y: 60,
    delay: 55,
    icon: (
      <svg width="32" height="32" viewBox="0 0 24 24" fill="currentColor">
        <path d="M12 2C6.36 2 2 6.13 2 11.7c0 2.91 1.2 5.42 3.15 7.2.16.15.26.36.27.58l.06 1.82c.02.58.63.96 1.16.72l2.03-.9c.17-.08.37-.1.55-.06.9.25 1.85.38 2.78.38 5.64 0 10-4.13 10-9.7S17.64 2 12 2z" />
      </svg>
    ),
  },
  {
    name: 'Texts',
    color: '#8B5CF6',
    x: 80,
    y: 55,
    delay: 65,
    icon: (
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
      </svg>
    ),
  },
  {
    name: 'Voicemail',
    color: '#F97316',
    x: 25,
    y: 72,
    delay: 75,
    icon: (
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <circle cx="6" cy="12" r="4" />
        <circle cx="18" cy="12" r="4" />
        <line x1="6" y1="16" x2="18" y2="16" />
      </svg>
    ),
  },
  {
    name: 'Notes',
    color: '#F59E0B',
    x: 60,
    y: 35,
    delay: 85,
    icon: (
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z" />
        <path d="M14 2v6h6" />
      </svg>
    ),
  },
];

export const TheProblemScene: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  const titleOpacity = interpolate(frame, [0, 20], [0, 1], { extrapolateRight: 'clamp' });
  const titleSlide = interpolate(frame, [0, 20], [-40, 0], { extrapolateRight: 'clamp' });

  // Chaos intensifies - items start drifting apart
  const chaosIntensity = interpolate(frame, [90, 160], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });

  // Red overlay pulse
  const bgPulse = interpolate(frame, [100, 180], [0, 0.12], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });

  // Bottom text
  const bottomFrame = 140;
  const bottomOpacity = interpolate(frame, [bottomFrame, bottomFrame + 20], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });
  const bottomSlide = interpolate(frame, [bottomFrame, bottomFrame + 20], [25, 0], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });

  const fadeOut = interpolate(frame, [durationInFrames - 15, durationInFrames], [1, 0], { extrapolateLeft: 'clamp' });

  return (
    <AbsoluteFill
      style={{
        background: `linear-gradient(135deg, ${BRAND.dark} 0%, #1a1a2e 50%, ${BRAND.dark} 100%)`,
        opacity: fadeOut,
      }}
    >
      {/* Red overlay */}
      <div style={{ position: 'absolute', inset: 0, backgroundColor: BRAND.red, opacity: bgPulse, pointerEvents: 'none' }} />

      {/* Title */}
      <div
        style={{
          position: 'absolute',
          top: 60,
          left: 0,
          right: 0,
          textAlign: 'center',
          opacity: titleOpacity,
          transform: `translateY(${titleSlide}px)`,
        }}
      >
        <h2 style={{ fontSize: TEXT.heading, fontWeight: 800, color: BRAND.white, margin: 0, fontFamily: FONT.system }}>
          Your conversations are{' '}
          <span style={{ color: BRAND.red }}>everywhere</span>
        </h2>
      </div>

      {/* Scattered app cards */}
      {scatteredApps.map((app, i) => {
        const scale = spring({ frame: frame - app.delay, fps, config: SPRING_CONFIGS.punch });
        const opacity = interpolate(frame, [app.delay, app.delay + 10], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });

        // Drift apart as chaos increases
        const sr = (s: number) => Math.sin(s * 9301 + 49297) * 2 - 1;
        const driftX = sr(i * 37) * 40 * chaosIntensity;
        const driftY = sr(i * 53) * 30 * chaosIntensity;
        const wobble = Math.sin((frame + i * 20) * 0.06) * 3 * (1 + chaosIntensity);

        return (
          <div
            key={app.name}
            style={{
              position: 'absolute',
              left: `${app.x}%`,
              top: `${app.y}%`,
              transform: `translate(-50%, -50%) scale(${Math.max(0, scale)}) translate(${driftX + wobble}px, ${driftY}px)`,
              opacity,
            }}
          >
            <div
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: 14,
                backgroundColor: `${BRAND.dark}ee`,
                border: `2px solid ${app.color}40`,
                borderRadius: 16,
                padding: '18px 24px',
                boxShadow: `0 8px 30px ${app.color}20`,
              }}
            >
              <div
                style={{
                  width: 56,
                  height: 56,
                  borderRadius: 14,
                  backgroundColor: `${app.color}20`,
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  color: app.color,
                  flexShrink: 0,
                }}
              >
                {app.icon}
              </div>
              <div>
                <div style={{ fontSize: TEXT.label, fontWeight: 700, color: BRAND.white, fontFamily: FONT.system }}>
                  {app.name}
                </div>
                <div style={{ fontSize: TEXT.tiny, color: `${BRAND.white}66`, fontFamily: FONT.system, marginTop: 2 }}>
                  {i + 2} unread
                </div>
              </div>
              {/* Red notification badge */}
              <div
                style={{
                  position: 'absolute',
                  top: -8,
                  right: -8,
                  width: 24,
                  height: 24,
                  borderRadius: '50%',
                  backgroundColor: BRAND.red,
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  fontSize: 13,
                  fontWeight: 700,
                  color: BRAND.white,
                  fontFamily: FONT.system,
                  transform: `scale(${1 + Math.sin(frame * 0.15) * 0.15})`,
                }}
              >
                {i + 2}
              </div>
            </div>
          </div>
        );
      })}

      {/* Connecting lines (tangled) */}
      <svg
        style={{ position: 'absolute', inset: 0, pointerEvents: 'none' }}
        width="1920"
        height="1080"
        viewBox="0 0 1920 1080"
      >
        {scatteredApps.map((app, i) => {
          const nextApp = scatteredApps[(i + 1) % scatteredApps.length];
          const lineOpacity = interpolate(frame, [app.delay + 20, app.delay + 35], [0, 0.15], {
            extrapolateLeft: 'clamp',
            extrapolateRight: 'clamp',
          });
          return (
            <line
              key={`line-${i}`}
              x1={`${app.x}%`}
              y1={`${app.y}%`}
              x2={`${nextApp.x}%`}
              y2={`${nextApp.y}%`}
              stroke={BRAND.red}
              strokeWidth={1.5}
              opacity={lineOpacity}
              strokeDasharray="8 6"
            />
          );
        })}
      </svg>

      {/* Bottom text */}
      <div
        style={{
          position: 'absolute',
          bottom: 80,
          left: 0,
          right: 0,
          textAlign: 'center',
          opacity: bottomOpacity,
          transform: `translateY(${bottomSlide}px)`,
        }}
      >
        <p style={{ fontSize: TEXT.subheading, fontWeight: 600, color: `${BRAND.white}cc`, margin: 0, fontFamily: FONT.system }}>
          Messages get lost. Customers get frustrated.{' '}
          <span style={{ color: BRAND.red }}>Deals slip away.</span>
        </p>
      </div>
    </AbsoluteFill>
  );
};
