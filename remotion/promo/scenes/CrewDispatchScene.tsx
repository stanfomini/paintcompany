import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, TEXT, SPRING_CONFIGS } from '../brand';
import { PromoVideoProps } from '../types';
import { MockupFrame } from '../components/MockupFrame';

interface CrewMember {
  initials: string;
  name: string;
  role: string;
  color: string;
  delay: number;
}

const crew: CrewMember[] = [
  { initials: 'MR', name: 'Mike R.', role: 'Lead Painter', color: BRAND.blue, delay: 50 },
  { initials: 'DL', name: 'David L.', role: 'Painter', color: BRAND.green, delay: 65 },
  { initials: 'SK', name: 'Sarah K.', role: 'Painter', color: BRAND.amber, delay: 80 },
];

export const CrewDispatchScene: React.FC<{ data: PromoVideoProps }> = ({ data }) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Title
  const titleOpacity = interpolate(frame, [0, 20], [0, 1], { extrapolateRight: 'clamp' });
  const titleSlide = interpolate(frame, [0, 20], [-30, 0], { extrapolateRight: 'clamp' });

  // Crew card
  const cardScale = spring({ frame: frame - 30, fps, config: SPRING_CONFIGS.gentle });
  const cardOpacity = interpolate(frame, [30, 50], [0, 1], { extrapolateRight: 'clamp' });

  // "Dispatched" badge
  const dispatchFrame = 100;
  const dispatchScale = spring({ frame: frame - dispatchFrame, fps, config: SPRING_CONFIGS.bouncy });

  // Phone notification
  const notifFrame = 115;
  const notifScale = spring({ frame: frame - notifFrame, fps, config: SPRING_CONFIGS.snappy });
  const notifOpacity = interpolate(frame, [notifFrame, notifFrame + 10], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // Fade
  const fadeOut = interpolate(frame, [durationInFrames - 15, durationInFrames], [1, 0], {
    extrapolateLeft: 'clamp',
  });

  return (
    <AbsoluteFill
      style={{
        background: `linear-gradient(180deg, ${BRAND.navy} 0%, #0F1A2E 100%)`,
        opacity: fadeOut,
      }}
    >
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
        <h2
          style={{
            fontSize: TEXT.heading,
            fontWeight: 800,
            color: BRAND.white,
            margin: 0,
            fontFamily: FONT.system,
          }}
        >
          Your Crew Is Assigned
        </h2>
        <p style={{ fontSize: TEXT.label, color: `${BRAND.white}80`, marginTop: 8, fontFamily: FONT.system }}>
          Know exactly who's coming to your home
        </p>
      </div>

      {/* Two-panel: Crew card + Phone notification */}
      <div
        style={{
          position: 'absolute',
          top: 200,
          left: 0,
          right: 0,
          bottom: 60,
          display: 'flex',
          justifyContent: 'center',
          alignItems: 'flex-start',
          gap: 60,
        }}
      >
        {/* Crew card */}
        <div
          style={{
            width: 500,
            opacity: cardOpacity,
            transform: `scale(${Math.max(0.85, cardScale)})`,
          }}
        >
          <div
            style={{
              backgroundColor: BRAND.white,
              borderRadius: 20,
              padding: 30,
              boxShadow: '0 20px 60px rgba(0,0,0,0.3)',
            }}
          >
            <h3 style={{ fontSize: 24, fontWeight: 700, color: BRAND.dark, margin: '0 0 24px', fontFamily: FONT.system }}>
              Assigned Crew
            </h3>

            {crew.map((member) => {
              const memberScale = spring({ frame: frame - member.delay, fps, config: SPRING_CONFIGS.snappy });
              return (
                <div
                  key={member.initials}
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 16,
                    marginBottom: 16,
                    transform: `scale(${Math.max(0, memberScale)})`,
                    transformOrigin: 'left center',
                  }}
                >
                  <div
                    style={{
                      width: 52,
                      height: 52,
                      borderRadius: '50%',
                      backgroundColor: `${member.color}20`,
                      border: `2px solid ${member.color}`,
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      color: member.color,
                      fontWeight: 700,
                      fontSize: 18,
                      fontFamily: FONT.system,
                      flexShrink: 0,
                    }}
                  >
                    {member.initials}
                  </div>
                  <div>
                    <span style={{ fontSize: 22, fontWeight: 600, color: BRAND.dark, fontFamily: FONT.system }}>
                      {member.name}
                    </span>
                    <p style={{ fontSize: 17, color: BRAND.gray, margin: '3px 0 0', fontFamily: FONT.system }}>
                      {member.role}
                    </p>
                  </div>
                </div>
              );
            })}

            {/* Dispatch badge */}
            {frame >= dispatchFrame && (
              <div
                style={{
                  marginTop: 16,
                  textAlign: 'center',
                  transform: `scale(${Math.max(0, dispatchScale)})`,
                }}
              >
                <div
                  style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 10,
                    backgroundColor: `${BRAND.green}15`,
                    borderRadius: 12,
                    padding: '12px 24px',
                    border: `2px solid ${BRAND.green}30`,
                  }}
                >
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke={BRAND.green} strokeWidth="2.5" strokeLinecap="round">
                    <path d="M20 6 9 17l-5-5" />
                  </svg>
                  <span style={{ fontSize: 16, fontWeight: 700, color: BRAND.green, fontFamily: FONT.system }}>
                    Crew Dispatched
                  </span>
                </div>
              </div>
            )}
          </div>
        </div>

        {/* Phone notification */}
        <div style={{ marginTop: 30 }}>
          <MockupFrame type="phone">
            <div
              style={{
                height: '100%',
                background: `linear-gradient(180deg, ${BRAND.navy}, #0F1A2E)`,
                padding: 20,
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'center',
              }}
            >
              {/* Time */}
              <div style={{ marginTop: 20, textAlign: 'center' }}>
                <span style={{ fontSize: 42, fontWeight: 200, color: BRAND.white, fontFamily: FONT.system }}>
                  8:45
                </span>
                <p style={{ fontSize: 13, color: 'rgba(255,255,255,0.5)', marginTop: 4, fontFamily: FONT.system }}>
                  Monday, March 15
                </p>
              </div>

              {/* Notification */}
              {frame >= notifFrame && (
                <div
                  style={{
                    marginTop: 50,
                    width: '100%',
                    opacity: notifOpacity,
                    transform: `scale(${Math.max(0, notifScale)})`,
                    transformOrigin: 'top center',
                  }}
                >
                  <div
                    style={{
                      backgroundColor: 'rgba(255,255,255,0.95)',
                      borderRadius: 16,
                      padding: 16,
                      boxShadow: '0 8px 30px rgba(0,0,0,0.3)',
                    }}
                  >
                    <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8 }}>
                      <div
                        style={{
                          width: 28,
                          height: 28,
                          borderRadius: 7,
                          backgroundColor: BRAND.blue,
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'center',
                        }}
                      >
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke={BRAND.white} strokeWidth="2.5">
                          <path d="M18.37 2.63 14 7l-1.59-1.59a2 2 0 0 0-2.82 0L8 7l9 9 1.59-1.59a2 2 0 0 0 0-2.82L17 10l4.37-4.37a2.12 2.12 0 1 0-3-3Z" />
                        </svg>
                      </div>
                      <span style={{ fontSize: 13, fontWeight: 700, color: BRAND.dark, fontFamily: FONT.system }}>
                        Painting Made Easy
                      </span>
                      <span style={{ fontSize: 11, color: BRAND.gray, marginLeft: 'auto', fontFamily: FONT.system }}>
                        now
                      </span>
                    </div>
                    <p style={{ fontSize: 17, color: BRAND.dark, margin: 0, fontFamily: FONT.system, lineHeight: 1.4, fontWeight: 500 }}>
                      Your crew is on the way! Mike, David & Sarah will arrive by 9am.
                    </p>
                  </div>
                </div>
              )}
            </div>
          </MockupFrame>
        </div>
      </div>
    </AbsoluteFill>
  );
};
