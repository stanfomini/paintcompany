import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, SPRING_CONFIGS, STAGGER_OFFSET } from '../brand';
import { PromoVideoProps } from '../types';
import { MockupFrame } from '../components/MockupFrame';
import { FloatingParticles } from '../components/FloatingParticles';

interface ChatMessage {
  text: string;
  sender: 'company' | 'customer';
  delay: number;
}

const messages: ChatMessage[] = [
  { text: "Hi! We're scheduled for Monday at 9am.", sender: 'company', delay: 40 },
  { text: 'Sounds great! Should I move furniture?', sender: 'customer', delay: 80 },
  { text: "No need - we handle everything!", sender: 'company', delay: 120 },
  { text: 'Wow, that makes it easy. Thank you!', sender: 'customer', delay: 155 },
];

export const CommunicationScene: React.FC<{ data: PromoVideoProps }> = ({ data }) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Title
  const titleOpacity = interpolate(frame, [0, 20], [0, 1], { extrapolateRight: 'clamp' });
  const titleSlide = interpolate(frame, [0, 20], [-30, 0], { extrapolateRight: 'clamp' });

  // Phone mockup
  const phoneScale = spring({ frame: frame - 20, fps, config: SPRING_CONFIGS.gentle });

  // LIVE badge pulse
  const livePulse = Math.sin(frame * 0.15) * 0.15 + 1;

  // Notification badge
  const notifFrame = 190;
  const notifScale = spring({ frame: frame - notifFrame, fps, config: SPRING_CONFIGS.bouncy });

  // Fade
  const fadeOut = interpolate(frame, [durationInFrames - 15, durationInFrames], [1, 0], {
    extrapolateLeft: 'clamp',
  });

  return (
    <AbsoluteFill style={{ backgroundColor: BRAND.lightGray, opacity: fadeOut }}>
      <FloatingParticles count={15} color={`${BRAND.sky}20`} seed={77} />

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
        <h2 style={{ fontSize: 48, fontWeight: 700, color: BRAND.navy, margin: 0, fontFamily: FONT.system }}>
          Real-Time Communication
        </h2>
        <p style={{ fontSize: 24, color: BRAND.gray, marginTop: 8, fontFamily: FONT.system }}>
          Stay connected every step of the way
        </p>
      </div>

      {/* Two-panel layout: chat + phone notification */}
      <div
        style={{
          position: 'absolute',
          top: 170,
          left: 0,
          right: 0,
          bottom: 60,
          display: 'flex',
          justifyContent: 'center',
          alignItems: 'flex-start',
          gap: 60,
        }}
      >
        {/* Chat panel */}
        <div
          style={{
            width: 600,
            transform: `scale(${Math.max(0.8, phoneScale)})`,
          }}
        >
          <MockupFrame type="browser" url={`${data.website}/chat`}>
            <div style={{ padding: 24, height: 550 }}>
              {/* Chat header */}
              <div
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: 12,
                  paddingBottom: 16,
                  borderBottom: '1px solid #E5E7EB',
                  marginBottom: 20,
                }}
              >
                <div
                  style={{
                    width: 40,
                    height: 40,
                    borderRadius: '50%',
                    backgroundColor: BRAND.blue,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    color: BRAND.white,
                    fontWeight: 700,
                    fontSize: 16,
                    fontFamily: FONT.system,
                  }}
                >
                  J
                </div>
                <div>
                  <div style={{ fontWeight: 600, fontSize: 16, color: BRAND.dark, fontFamily: FONT.system }}>
                    John - Your Painter
                  </div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                    <div
                      style={{
                        width: 8,
                        height: 8,
                        borderRadius: '50%',
                        backgroundColor: BRAND.green,
                      }}
                    />
                    <span style={{ fontSize: 12, color: BRAND.green, fontFamily: FONT.system }}>Online</span>
                  </div>
                </div>

                {/* LIVE badge */}
                <div
                  style={{
                    marginLeft: 'auto',
                    backgroundColor: BRAND.red,
                    borderRadius: 6,
                    padding: '4px 12px',
                    transform: `scale(${livePulse})`,
                  }}
                >
                  <span style={{ fontSize: 12, fontWeight: 700, color: BRAND.white, fontFamily: FONT.system, letterSpacing: 1 }}>
                    LIVE
                  </span>
                </div>
              </div>

              {/* Chat messages */}
              <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                {messages.map((msg, i) => {
                  const msgScale = spring({ frame: frame - msg.delay, fps, config: SPRING_CONFIGS.snappy });
                  const msgOpacity = interpolate(frame, [msg.delay, msg.delay + 10], [0, 1], {
                    extrapolateLeft: 'clamp',
                    extrapolateRight: 'clamp',
                  });
                  const isCompany = msg.sender === 'company';

                  return (
                    <div
                      key={i}
                      style={{
                        display: 'flex',
                        justifyContent: isCompany ? 'flex-start' : 'flex-end',
                        opacity: msgOpacity,
                        transform: `scale(${Math.max(0, msgScale)}) translateY(${(1 - Math.max(0, msgScale)) * 20}px)`,
                        transformOrigin: isCompany ? 'left center' : 'right center',
                      }}
                    >
                      <div
                        style={{
                          maxWidth: '75%',
                          padding: '12px 18px',
                          borderRadius: isCompany ? '4px 16px 16px 16px' : '16px 4px 16px 16px',
                          backgroundColor: isCompany ? BRAND.lightGray : BRAND.blue,
                          color: isCompany ? BRAND.dark : BRAND.white,
                          fontSize: 16,
                          fontFamily: FONT.system,
                          lineHeight: 1.4,
                        }}
                      >
                        {msg.text}
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          </MockupFrame>
        </div>

        {/* Phone notification */}
        <div
          style={{
            transform: `scale(${Math.max(0.8, phoneScale)})`,
            marginTop: 60,
          }}
        >
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
                <span style={{ fontSize: 48, fontWeight: 200, color: BRAND.white, fontFamily: FONT.system }}>
                  9:41
                </span>
                <p style={{ fontSize: 14, color: 'rgba(255,255,255,0.6)', marginTop: 4, fontFamily: FONT.system }}>
                  Monday, January 15
                </p>
              </div>

              {/* Notification */}
              {frame >= notifFrame && (
                <div
                  style={{
                    marginTop: 60,
                    width: '100%',
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
                    <p style={{ fontSize: 14, color: BRAND.dark, margin: 0, fontFamily: FONT.system, lineHeight: 1.4 }}>
                      Your crew is on the way! ETA 15 minutes.
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
