import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, TEXT, SPRING_CONFIGS, STAGGER } from '../brand';
import { CustomerPortalProps } from '../types';

const quickRooms = [
  { name: 'Living Room', price: 840, icon: '🛋️' },
  { name: 'Master Bedroom', price: 720, icon: '🛏️' },
  { name: 'Kitchen', price: 680, icon: '🍳' },
  { name: 'Bathroom', price: 420, icon: '🚿' },
  { name: 'Hallway', price: 310, icon: '🚪' },
  { name: 'Office', price: 510, icon: '💼' },
];

const addedRooms = [
  { name: 'Living Room (M)', price: 840, size: 'Medium' },
  { name: 'Master Bedroom (L)', price: 980, size: 'Large' },
  { name: 'Kitchen (M)', price: 680, size: 'Medium' },
];

export const EstimateBuilderScene: React.FC<{ data: CustomerPortalProps }> = ({ data }) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Title
  const titleOpacity = interpolate(frame, [0, 18], [0, 1], { extrapolateRight: 'clamp' });
  const titleSlide = interpolate(frame, [0, 18], [-28, 0], { extrapolateRight: 'clamp' });

  // Main panel
  const panelOpacity = interpolate(frame, [20, 38], [0, 1], { extrapolateRight: 'clamp' });
  const panelScale = spring({ frame: frame - 20, fps, config: SPRING_CONFIGS.gentle });

  // Quick add buttons appear
  const buttonScales = quickRooms.map((_, i) =>
    spring({ frame: frame - (50 + i * 8), fps, config: SPRING_CONFIGS.snappy })
  );

  // Rooms being added to list
  const room0Opacity = interpolate(frame, [110, 125], [0, 1], { extrapolateRight: 'clamp' });
  const room0Slide = interpolate(frame, [110, 125], [30, 0], { extrapolateRight: 'clamp' });

  const room1Opacity = interpolate(frame, [145, 160], [0, 1], { extrapolateRight: 'clamp' });
  const room1Slide = interpolate(frame, [145, 160], [30, 0], { extrapolateRight: 'clamp' });

  const room2Opacity = interpolate(frame, [178, 193], [0, 1], { extrapolateRight: 'clamp' });
  const room2Slide = interpolate(frame, [178, 193], [30, 0], { extrapolateRight: 'clamp' });

  const roomOpacities = [room0Opacity, room1Opacity, room2Opacity];
  const roomSlides = [room0Slide, room1Slide, room2Slide];

  // Total price reveal
  const totalFrame = 210;
  const totalScale = spring({ frame: frame - totalFrame, fps, config: SPRING_CONFIGS.bouncy });
  const totalOpacity = interpolate(frame, [totalFrame, totalFrame + 12], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // Running total value
  const totalValues = [0, 840, 1820, 2500];
  let currentTotal = 0;
  if (frame >= 178) currentTotal = 2500;
  else if (frame >= 145) currentTotal = 1820;
  else if (frame >= 110) currentTotal = 840;
  else currentTotal = 0;

  // AI badge pulse
  const aiBadgePulse = Math.sin(frame * 0.1) * 0.05 + 1;

  // Fade
  const fadeOut = interpolate(frame, [durationInFrames - 15, durationInFrames], [1, 0], {
    extrapolateLeft: 'clamp',
  });

  return (
    <AbsoluteFill style={{ backgroundColor: '#F0FFF4', opacity: fadeOut }}>
      {/* Top gradient */}
      <div
        style={{
          position: 'absolute',
          top: 0,
          left: 0,
          right: 0,
          height: 6,
          background: `linear-gradient(90deg, ${BRAND.green}, ${BRAND.teal}, ${BRAND.blue})`,
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
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 12, marginBottom: 8 }}>
          <div
            style={{
              backgroundColor: BRAND.green,
              borderRadius: 8,
              padding: '4px 14px',
            }}
          >
            <span style={{ fontSize: TEXT.small, fontWeight: 700, color: BRAND.white, fontFamily: FONT.system, letterSpacing: 1 }}>
              ESTIMATE BUILDER
            </span>
          </div>
          <div
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: 6,
              backgroundColor: `${BRAND.amber}20`,
              border: `1px solid ${BRAND.amber}40`,
              borderRadius: 20,
              padding: '4px 12px',
              transform: `scale(${aiBadgePulse})`,
            }}
          >
            <span style={{ fontSize: 14 }}>✨</span>
            <span style={{ fontSize: 12, fontWeight: 700, color: BRAND.amber, fontFamily: FONT.system }}>
              AI-Powered
            </span>
          </div>
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
          Build an Estimate in Seconds
        </h2>
        <p style={{ fontSize: TEXT.small, color: BRAND.gray, marginTop: 6, fontFamily: FONT.system }}>
          Click a room, pick a size — instant professional pricing.
        </p>
      </div>

      {/* Main content */}
      <div
        style={{
          position: 'absolute',
          top: 170,
          left: 80,
          right: 80,
          bottom: 30,
          opacity: panelOpacity,
          transform: `scale(${Math.max(0.9, panelScale)})`,
        }}
      >
        <div
          style={{
            width: '100%',
            height: '100%',
            borderRadius: 20,
            overflow: 'hidden',
            boxShadow: '0 30px 80px rgba(0,0,0,0.18)',
            display: 'flex',
            backgroundColor: BRAND.white,
          }}
        >
          {/* Left panel: Quick add rooms */}
          <div
            style={{
              width: '55%',
              padding: '24px 28px',
              borderRight: `2px solid ${BRAND.lightGray}`,
            }}
          >
            <h3
              style={{
                fontSize: 20,
                fontWeight: 700,
                color: BRAND.dark,
                margin: '0 0 16px',
                fontFamily: FONT.system,
              }}
            >
              ⚡ Quick Add Rooms
            </h3>

            {/* Room buttons grid */}
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10, marginBottom: 20 }}>
              {quickRooms.map((room, i) => {
                const isAdded = addedRooms.some((r) => r.name.startsWith(room.name));
                const isActive = frame >= 110 && i < 3;
                return (
                  <div
                    key={room.name}
                    style={{
                      display: 'flex',
                      alignItems: 'center',
                      gap: 8,
                      padding: '10px 16px',
                      borderRadius: 10,
                      backgroundColor: isActive ? `${BRAND.green}15` : BRAND.lightGray,
                      border: `2px solid ${isActive ? BRAND.green : 'transparent'}`,
                      transform: `scale(${Math.max(0, buttonScales[i])})`,
                      cursor: 'pointer',
                    }}
                  >
                    <span style={{ fontSize: 18 }}>{room.icon}</span>
                    <div>
                      <div style={{ fontSize: 14, fontWeight: 600, color: BRAND.dark, fontFamily: FONT.system }}>
                        {room.name}
                      </div>
                      <div style={{ fontSize: 12, color: BRAND.gray, fontFamily: FONT.system }}>
                        from ${room.price}
                      </div>
                    </div>
                    <div
                      style={{
                        marginLeft: 'auto',
                        width: 22,
                        height: 22,
                        borderRadius: '50%',
                        backgroundColor: isActive ? BRAND.green : '#E5E7EB',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                      }}
                    >
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke={isActive ? BRAND.white : BRAND.gray} strokeWidth="3" strokeLinecap="round">
                        {isActive ? <polyline points="20 6 9 17 4 12" /> : <line x1="12" y1="5" x2="12" y2="19" />}
                      </svg>
                    </div>
                  </div>
                );
              })}
            </div>

            {/* Size selector hint */}
            <div
              style={{
                padding: '12px 16px',
                backgroundColor: `${BRAND.blue}10`,
                borderRadius: 10,
                border: `1px solid ${BRAND.blue}25`,
                display: 'flex',
                alignItems: 'center',
                gap: 10,
              }}
            >
              <span style={{ fontSize: 20 }}>📐</span>
              <div>
                <div style={{ fontSize: 14, fontWeight: 600, color: BRAND.blue, fontFamily: FONT.system }}>
                  Size matters — and we handle the math
                </div>
                <div style={{ fontSize: 12, color: BRAND.gray, fontFamily: FONT.system }}>
                  Small / Medium / Large options with instant recalculation
                </div>
              </div>
            </div>
          </div>

          {/* Right panel: Estimate list */}
          <div style={{ flex: 1, padding: '24px 24px', display: 'flex', flexDirection: 'column' }}>
            <div
              style={{
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                marginBottom: 16,
              }}
            >
              <h3 style={{ fontSize: 20, fontWeight: 700, color: BRAND.dark, margin: 0, fontFamily: FONT.system }}>
                Estimate Summary
              </h3>
              <div
                style={{
                  fontSize: 12,
                  color: BRAND.gray,
                  fontFamily: FONT.system,
                  backgroundColor: BRAND.lightGray,
                  borderRadius: 6,
                  padding: '4px 10px',
                }}
              >
                {addedRooms.filter((_, i) => roomOpacities[i] > 0.5).length} rooms
              </div>
            </div>

            {/* Room list */}
            <div style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: 8 }}>
              {addedRooms.map((room, i) => (
                <div
                  key={room.name}
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    padding: '12px 14px',
                    borderRadius: 10,
                    backgroundColor: BRAND.lightGray,
                    opacity: roomOpacities[i],
                    transform: `translateX(${roomSlides[i]}px)`,
                    border: `1px solid ${BRAND.green}20`,
                  }}
                >
                  <div style={{ flex: 1 }}>
                    <div style={{ fontSize: 15, fontWeight: 600, color: BRAND.dark, fontFamily: FONT.system }}>
                      {room.name}
                    </div>
                    <div style={{ fontSize: 12, color: BRAND.gray, fontFamily: FONT.system }}>
                      Size: {room.size}
                    </div>
                  </div>
                  <div style={{ fontSize: 18, fontWeight: 700, color: BRAND.green, fontFamily: FONT.system }}>
                    ${room.price.toLocaleString()}
                  </div>
                </div>
              ))}

              {/* Empty state */}
              {frame < 110 && (
                <div
                  style={{
                    textAlign: 'center',
                    padding: '30px 20px',
                    color: BRAND.gray,
                    fontFamily: FONT.system,
                    fontSize: 15,
                  }}
                >
                  <div style={{ fontSize: 32, marginBottom: 8 }}>👈</div>
                  Click a room to add it
                </div>
              )}
            </div>

            {/* Running total */}
            <div
              style={{
                marginTop: 12,
                padding: '16px 18px',
                borderRadius: 12,
                background: frame >= totalFrame
                  ? `linear-gradient(135deg, ${BRAND.green}, #059669)`
                  : `${BRAND.lightGray}`,
                opacity: frame >= 110 ? 1 : 0.4,
                transform: frame >= totalFrame ? `scale(${Math.max(0.9, totalScale)})` : 'none',
              }}
            >
              <div
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'space-between',
                }}
              >
                <span
                  style={{
                    fontSize: 18,
                    fontWeight: 700,
                    color: frame >= totalFrame ? BRAND.white : BRAND.dark,
                    fontFamily: FONT.system,
                  }}
                >
                  {frame >= totalFrame ? '🎉 Estimate Total' : 'Running Total'}
                </span>
                <span
                  style={{
                    fontSize: 28,
                    fontWeight: 800,
                    color: frame >= totalFrame ? BRAND.white : BRAND.green,
                    fontFamily: FONT.system,
                  }}
                >
                  ${currentTotal.toLocaleString()}
                </span>
              </div>
              {frame >= totalFrame && (
                <div
                  style={{
                    opacity: totalOpacity,
                    fontSize: 13,
                    color: `${BRAND.white}CC`,
                    fontFamily: FONT.system,
                    marginTop: 4,
                  }}
                >
                  Ready to send to customer →
                </div>
              )}
            </div>
          </div>
        </div>
      </div>
    </AbsoluteFill>
  );
};
