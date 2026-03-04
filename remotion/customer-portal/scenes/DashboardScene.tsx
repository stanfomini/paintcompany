import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, TEXT, SPRING_CONFIGS, STAGGER } from '../brand';
import { CustomerPortalProps } from '../types';

const statCards = [
  { label: 'Job Status', value: 'In Progress', icon: '🎨', color: BRAND.blue, isBadge: true },
  { label: 'Estimated Total', value: '$4,800', icon: '💰', color: BRAND.green, isBadge: false },
  { label: 'Est. Completion', value: 'Mar 15', icon: '📅', color: BRAND.amber, isBadge: false },
  { label: 'Crew Size', value: '3 Painters', icon: '👥', color: BRAND.purple, isBadge: false },
];

const activities = [
  { msg: 'Your estimate has been approved ✓', time: '2m ago', color: BRAND.green },
  { msg: 'Crew assigned — team of 3 painters', time: '1h ago', color: BRAND.blue },
  { msg: 'Paint colors confirmed', time: '3h ago', color: BRAND.amber },
  { msg: 'Job scheduled for March 12', time: '1d ago', color: BRAND.purple },
];

export const DashboardScene: React.FC<{ data: CustomerPortalProps }> = ({ data }) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Title
  const titleOpacity = interpolate(frame, [0, 18], [0, 1], { extrapolateRight: 'clamp' });
  const titleSlide = interpolate(frame, [0, 18], [-30, 0], { extrapolateRight: 'clamp' });

  // Browser mockup
  const mockupOpacity = interpolate(frame, [20, 40], [0, 1], { extrapolateRight: 'clamp' });
  const mockupScale = spring({ frame: frame - 20, fps, config: SPRING_CONFIGS.gentle });

  // Stat cards stagger
  const cardScales = statCards.map((_, i) =>
    spring({ frame: frame - (55 + i * STAGGER), fps, config: SPRING_CONFIGS.snappy })
  );

  // Activity items stagger
  const activityOpacities = activities.map((_, i) =>
    interpolate(frame, [120 + i * 14, 140 + i * 14], [0, 1], { extrapolateRight: 'clamp' })
  );
  const activitySlides = activities.map((_, i) =>
    interpolate(frame, [120 + i * 14, 140 + i * 14], [30, 0], { extrapolateRight: 'clamp' })
  );

  // Notification pop
  const notifFrame = 205;
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
    <AbsoluteFill style={{ backgroundColor: '#F0F4FF', opacity: fadeOut }}>
      {/* Gradient top strip */}
      <div
        style={{
          position: 'absolute',
          top: 0,
          left: 0,
          right: 0,
          height: 6,
          background: `linear-gradient(90deg, ${BRAND.blue}, ${BRAND.sky}, ${BRAND.green})`,
        }}
      />

      {/* Section label */}
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
        <div style={{ display: 'inline-flex', alignItems: 'center', gap: 10, marginBottom: 8 }}>
          <div
            style={{
              backgroundColor: BRAND.blue,
              borderRadius: 8,
              padding: '4px 14px',
            }}
          >
            <span style={{ fontSize: TEXT.small, fontWeight: 700, color: BRAND.white, fontFamily: FONT.system, letterSpacing: 1 }}>
              CUSTOMER DASHBOARD
            </span>
          </div>
        </div>
        <h2
          style={{
            fontSize: TEXT.heading - 4,
            fontWeight: 800,
            color: BRAND.navy,
            margin: 0,
            fontFamily: FONT.system,
          }}
        >
          Your Project, Front &amp; Center
        </h2>
        <p style={{ fontSize: TEXT.label, color: BRAND.gray, marginTop: 6, fontFamily: FONT.system }}>
          Everything you need to know, at a glance
        </p>
      </div>

      {/* Browser mockup */}
      <div
        style={{
          position: 'absolute',
          top: 165,
          left: 80,
          right: 80,
          bottom: 30,
          opacity: mockupOpacity,
          transform: `scale(${Math.max(0.85, mockupScale)})`,
        }}
      >
        {/* Browser chrome */}
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
          {/* Browser bar */}
          <div
            style={{
              height: 48,
              backgroundColor: '#E5E7EB',
              display: 'flex',
              alignItems: 'center',
              padding: '0 16px',
              gap: 8,
              flexShrink: 0,
            }}
          >
            <div style={{ display: 'flex', gap: 7 }}>
              <div style={{ width: 13, height: 13, borderRadius: '50%', backgroundColor: '#EF4444' }} />
              <div style={{ width: 13, height: 13, borderRadius: '50%', backgroundColor: '#F59E0B' }} />
              <div style={{ width: 13, height: 13, borderRadius: '50%', backgroundColor: '#10B981' }} />
            </div>
            <div
              style={{
                flex: 1,
                marginLeft: 12,
                backgroundColor: BRAND.white,
                borderRadius: 6,
                padding: '5px 12px',
                fontSize: 13,
                color: BRAND.gray,
                fontFamily: FONT.system,
              }}
            >
              {data.website}/my-dashboard
            </div>
          </div>

          {/* Dashboard content */}
          <div style={{ flex: 1, padding: '20px 28px', overflow: 'hidden' }}>
            {/* Header greeting */}
            <div
              style={{
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                marginBottom: 18,
              }}
            >
              <div>
                <h3 style={{ fontSize: 26, fontWeight: 700, color: BRAND.dark, margin: 0, fontFamily: FONT.system }}>
                  Welcome back, {data.customerName}! 👋
                </h3>
                <p style={{ fontSize: 16, color: BRAND.gray, margin: '4px 0 0', fontFamily: FONT.system }}>
                  {data.address} — Interior Painting
                </p>
              </div>
              <div
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: 8,
                  backgroundColor: `${BRAND.blue}12`,
                  borderRadius: 10,
                  padding: '8px 14px',
                }}
              >
                <div style={{ width: 10, height: 10, borderRadius: '50%', backgroundColor: BRAND.green }} />
                <span style={{ fontSize: 14, fontWeight: 600, color: BRAND.blue, fontFamily: FONT.system }}>
                  Job Active
                </span>
              </div>
            </div>

            {/* Stat cards */}
            <div style={{ display: 'flex', gap: 14, marginBottom: 20 }}>
              {statCards.map((card, i) => (
                <div
                  key={card.label}
                  style={{
                    flex: 1,
                    borderRadius: 12,
                    padding: '14px 16px',
                    backgroundColor: `${card.color}10`,
                    border: `2px solid ${card.color}25`,
                    transform: `scale(${Math.max(0, cardScales[i])})`,
                  }}
                >
                  <div style={{ fontSize: 22, marginBottom: 6 }}>{card.icon}</div>
                  <div style={{ fontSize: 12, color: BRAND.gray, fontFamily: FONT.system, marginBottom: 4 }}>
                    {card.label}
                  </div>
                  {card.isBadge ? (
                    <div
                      style={{
                        display: 'inline-block',
                        backgroundColor: card.color,
                        borderRadius: 6,
                        padding: '3px 10px',
                      }}
                    >
                      <span style={{ fontSize: 14, fontWeight: 700, color: BRAND.white, fontFamily: FONT.system }}>
                        {card.value}
                      </span>
                    </div>
                  ) : (
                    <div style={{ fontSize: 20, fontWeight: 700, color: BRAND.dark, fontFamily: FONT.system }}>
                      {card.value}
                    </div>
                  )}
                </div>
              ))}
            </div>

            {/* Two-column layout */}
            <div style={{ display: 'flex', gap: 18 }}>
              {/* Activity feed */}
              <div style={{ flex: 1 }}>
                <h4 style={{ fontSize: 16, fontWeight: 700, color: BRAND.dark, margin: '0 0 10px', fontFamily: FONT.system }}>
                  Recent Activity
                </h4>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                  {activities.map((act, i) => (
                    <div
                      key={i}
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 10,
                        padding: '8px 12px',
                        backgroundColor: BRAND.lightGray,
                        borderRadius: 8,
                        borderLeft: `3px solid ${act.color}`,
                        opacity: activityOpacities[i],
                        transform: `translateX(${activitySlides[i]}px)`,
                      }}
                    >
                      <div style={{ flex: 1, fontSize: 14, color: BRAND.dark, fontFamily: FONT.system }}>
                        {act.msg}
                      </div>
                      <div style={{ fontSize: 11, color: BRAND.gray, fontFamily: FONT.system, whiteSpace: 'nowrap' }}>
                        {act.time}
                      </div>
                    </div>
                  ))}
                </div>
              </div>

              {/* Quick actions */}
              <div style={{ width: 200 }}>
                <h4 style={{ fontSize: 16, fontWeight: 700, color: BRAND.dark, margin: '0 0 10px', fontFamily: FONT.system }}>
                  Quick Actions
                </h4>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                  {[
                    { label: 'View Estimate', color: BRAND.blue },
                    { label: 'Track Progress', color: BRAND.green },
                    { label: 'Message Us', color: BRAND.purple },
                  ].map((action) => (
                    <div
                      key={action.label}
                      style={{
                        padding: '10px 14px',
                        backgroundColor: `${action.color}15`,
                        border: `1.5px solid ${action.color}30`,
                        borderRadius: 8,
                        fontSize: 14,
                        fontWeight: 600,
                        color: action.color,
                        fontFamily: FONT.system,
                        cursor: 'pointer',
                      }}
                    >
                      {action.label} →
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Pop-up notification */}
      {frame >= notifFrame && (
        <div
          style={{
            position: 'absolute',
            top: 180,
            right: 60,
            opacity: notifOpacity,
            transform: `scale(${Math.max(0, notifScale)})`,
            transformOrigin: 'top right',
          }}
        >
          <div
            style={{
              backgroundColor: BRAND.white,
              borderRadius: 14,
              padding: '14px 20px',
              boxShadow: `0 12px 40px rgba(0,0,0,0.18), 0 0 0 2px ${BRAND.green}30`,
              display: 'flex',
              alignItems: 'center',
              gap: 12,
              maxWidth: 320,
            }}
          >
            <div
              style={{
                width: 40,
                height: 40,
                borderRadius: 10,
                background: `linear-gradient(135deg, ${BRAND.green}, #059669)`,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                flexShrink: 0,
              }}
            >
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke={BRAND.white} strokeWidth="2.5" strokeLinecap="round">
                <path d="M20 6 9 17l-5-5" />
              </svg>
            </div>
            <div>
              <div style={{ fontSize: 14, fontWeight: 700, color: BRAND.dark, fontFamily: FONT.system }}>
                Crew Assigned!
              </div>
              <div style={{ fontSize: 12, color: BRAND.gray, fontFamily: FONT.system, marginTop: 2 }}>
                Your painting team is confirmed.
              </div>
            </div>
          </div>
        </div>
      )}
    </AbsoluteFill>
  );
};
