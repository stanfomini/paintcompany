import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, TEXT, SPRING_CONFIGS } from '../brand';
import { PulsingDot } from '../components/PulsingDot';
import { FloatingParticles } from '../components/FloatingParticles';

interface ChatMessage {
  text: string;
  sender: 'customer' | 'company';
  delay: number;
  hasTranslation?: boolean;
  translation?: string;
}

const messages: ChatMessage[] = [
  { text: 'Hey, the crew just arrived!', sender: 'company', delay: 30 },
  { text: 'Great, I left the garage open for them.', sender: 'customer', delay: 55 },
  { text: 'We are starting with the living room first. Should be done by 3pm.', sender: 'company', delay: 80 },
  { text: 'Necesito cambiar el color del dormitorio', sender: 'customer', delay: 110, hasTranslation: true, translation: 'I need to change the bedroom color' },
  { text: 'No problem! I\'ll bring new samples tomorrow.', sender: 'company', delay: 145 },
];

export const LiveChatScene: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  const titleOpacity = interpolate(frame, [0, 20], [0, 1], { extrapolateRight: 'clamp' });
  const titleSlide = interpolate(frame, [0, 20], [-30, 0], { extrapolateRight: 'clamp' });

  const fadeOut = interpolate(frame, [durationInFrames - 15, durationInFrames], [1, 0], { extrapolateLeft: 'clamp' });

  // Typing indicator
  const showTyping = (afterMsg: number, beforeMsg: number): number => {
    const start = messages[afterMsg].delay + 15;
    const end = messages[beforeMsg].delay;
    return frame >= start && frame < end ? interpolate(frame, [start, start + 8], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' }) : 0;
  };

  return (
    <AbsoluteFill style={{ backgroundColor: BRAND.white, opacity: fadeOut }}>
      <FloatingParticles count={10} color={`${BRAND.green}10`} seed={77} />

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
          Real-Time Chat
        </h2>
        <p style={{ fontSize: TEXT.label, color: BRAND.gray, marginTop: 6, fontFamily: FONT.system }}>
          Instant messaging with typing indicators, audio messages, and auto-translation
        </p>
      </div>

      {/* Chat window */}
      <div
        style={{
          position: 'absolute',
          top: 150,
          left: 250,
          right: 250,
          bottom: 60,
          backgroundColor: BRAND.lightGray,
          borderRadius: 24,
          boxShadow: '0 20px 60px rgba(0,0,0,0.08)',
          border: '1px solid #E5E7EB',
          display: 'flex',
          flexDirection: 'column',
          overflow: 'hidden',
        }}
      >
        {/* Header */}
        <div
          style={{
            padding: '16px 24px',
            backgroundColor: BRAND.white,
            borderBottom: '1px solid #E5E7EB',
            display: 'flex',
            alignItems: 'center',
            gap: 14,
          }}
        >
          <div
            style={{
              width: 48,
              height: 48,
              borderRadius: '50%',
              backgroundColor: `${BRAND.green}20`,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              color: BRAND.green,
              fontWeight: 700,
              fontSize: 17,
              fontFamily: FONT.system,
            }}
          >
            SM
          </div>
          <div>
            <span style={{ fontWeight: 700, fontSize: 20, color: BRAND.dark, fontFamily: FONT.system }}>Sarah Martinez</span>
            <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginTop: 2 }}>
              <PulsingDot color={BRAND.green} size={7} />
              <span style={{ fontSize: 13, color: BRAND.green, fontFamily: FONT.system }}>Online</span>
            </div>
          </div>
          <div
            style={{
              marginLeft: 'auto',
              backgroundColor: BRAND.red,
              borderRadius: 6,
              padding: '4px 12px',
              transform: `scale(${1 + Math.sin(frame * 0.15) * 0.08})`,
            }}
          >
            <span style={{ fontSize: 12, fontWeight: 700, color: BRAND.white, fontFamily: FONT.system, letterSpacing: 1 }}>LIVE</span>
          </div>
        </div>

        {/* Messages area */}
        <div style={{ flex: 1, padding: '20px 24px', display: 'flex', flexDirection: 'column', gap: 14, justifyContent: 'flex-end' }}>
          {messages.map((msg, i) => {
            const msgScale = spring({ frame: frame - msg.delay, fps, config: SPRING_CONFIGS.snappy });
            const msgOpacity = interpolate(frame, [msg.delay, msg.delay + 8], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });
            const isCustomer = msg.sender === 'customer';

            // Translation reveal
            const translationDelay = msg.delay + 20;
            const transOpacity = msg.hasTranslation
              ? interpolate(frame, [translationDelay, translationDelay + 12], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' })
              : 0;

            return (
              <div key={i}>
                <div
                  style={{
                    display: 'flex',
                    justifyContent: isCustomer ? 'flex-end' : 'flex-start',
                    opacity: msgOpacity,
                    transform: `scale(${Math.max(0, msgScale)}) translateY(${(1 - Math.max(0, msgScale)) * 12}px)`,
                    transformOrigin: isCustomer ? 'right center' : 'left center',
                  }}
                >
                  <div
                    style={{
                      maxWidth: '75%',
                      padding: '14px 20px',
                      borderRadius: isCustomer ? '18px 4px 18px 18px' : '4px 18px 18px 18px',
                      backgroundColor: isCustomer ? BRAND.blue : BRAND.white,
                      color: isCustomer ? BRAND.white : BRAND.dark,
                      fontSize: 18,
                      fontFamily: FONT.system,
                      lineHeight: 1.4,
                      boxShadow: isCustomer ? 'none' : '0 2px 8px rgba(0,0,0,0.05)',
                    }}
                  >
                    {msg.text}
                  </div>
                </div>

                {/* Translation */}
                {msg.hasTranslation && (
                  <div
                    style={{
                      display: 'flex',
                      justifyContent: 'flex-end',
                      marginTop: 6,
                      opacity: transOpacity,
                    }}
                  >
                    <div
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 6,
                        padding: '6px 14px',
                        backgroundColor: `${BRAND.sky}15`,
                        borderRadius: 12,
                        border: `1px solid ${BRAND.sky}30`,
                      }}
                    >
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke={BRAND.sky} strokeWidth="2" strokeLinecap="round">
                        <path d="m5 8 6 6M4 14l6-6 2-3M2 5h12M7 2h1" />
                        <path d="m22 22-5-10-5 10M14 18h6" />
                      </svg>
                      <span style={{ fontSize: 14, color: BRAND.sky, fontFamily: FONT.system, fontStyle: 'italic' }}>
                        {msg.translation}
                      </span>
                    </div>
                  </div>
                )}
              </div>
            );
          })}

          {/* Typing indicator */}
          {(() => {
            const typingOpacity = frame >= 90 && frame < 110
              ? interpolate(frame, [90, 98], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' })
              : frame >= 130 && frame < 145
              ? interpolate(frame, [130, 138], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' })
              : 0;

            return (
              <div style={{ display: 'flex', justifyContent: 'flex-end', opacity: typingOpacity }}>
                <div
                  style={{
                    display: 'flex',
                    gap: 5,
                    padding: '14px 20px',
                    borderRadius: '18px 4px 18px 18px',
                    backgroundColor: `${BRAND.blue}30`,
                  }}
                >
                  {[0, 1, 2].map((dot) => (
                    <div
                      key={dot}
                      style={{
                        width: 8,
                        height: 8,
                        borderRadius: '50%',
                        backgroundColor: BRAND.blue,
                        opacity: 0.4 + Math.sin((frame * 0.2) + dot * 1.5) * 0.4,
                        transform: `translateY(${Math.sin((frame * 0.2) + dot * 1.5) * 3}px)`,
                      }}
                    />
                  ))}
                </div>
              </div>
            );
          })()}
        </div>

        {/* Composer */}
        <div
          style={{
            padding: '14px 20px',
            backgroundColor: BRAND.white,
            borderTop: '1px solid #E5E7EB',
            display: 'flex',
            alignItems: 'center',
            gap: 12,
          }}
        >
          <div style={{ flex: 1, backgroundColor: BRAND.lightGray, borderRadius: 12, padding: '10px 16px', fontSize: 15, color: BRAND.gray, fontFamily: FONT.system }}>
            Type a message...
          </div>
          <div
            style={{
              width: 40,
              height: 40,
              borderRadius: '50%',
              backgroundColor: BRAND.blue,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
            }}
          >
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke={BRAND.white} strokeWidth="2.5" strokeLinecap="round">
              <path d="m22 2-7 20-4-9-9-4z" />
              <path d="m22 2-11 11" />
            </svg>
          </div>
        </div>
      </div>

      {/* Feature callouts */}
      {[
        { text: 'WebSocket real-time', icon: '~', x: 80, y: 380, delay: 160, color: BRAND.green },
        { text: 'Auto-translate', icon: 'A', x: 1560, y: 480, delay: 175, color: BRAND.sky },
      ].map((callout) => {
        const cOpacity = interpolate(frame, [callout.delay, callout.delay + 15], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });
        const cSlide = interpolate(frame, [callout.delay, callout.delay + 15], [15, 0], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });
        return (
          <div
            key={callout.text}
            style={{
              position: 'absolute',
              left: callout.x,
              top: callout.y,
              opacity: cOpacity,
              transform: `translateY(${cSlide}px)`,
              display: 'flex',
              alignItems: 'center',
              gap: 10,
              backgroundColor: `${callout.color}10`,
              border: `1px solid ${callout.color}30`,
              borderRadius: 12,
              padding: '10px 18px',
            }}
          >
            <div
              style={{
                width: 32,
                height: 32,
                borderRadius: 8,
                backgroundColor: `${callout.color}20`,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                color: callout.color,
                fontWeight: 700,
                fontSize: 16,
                fontFamily: FONT.system,
              }}
            >
              {callout.icon}
            </div>
            <span style={{ fontSize: 15, fontWeight: 600, color: callout.color, fontFamily: FONT.system }}>
              {callout.text}
            </span>
          </div>
        );
      })}
    </AbsoluteFill>
  );
};
