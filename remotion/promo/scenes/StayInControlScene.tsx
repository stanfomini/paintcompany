import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, TEXT, SPRING_CONFIGS } from '../brand';
import { PromoVideoProps } from '../types';
import { FloatingParticles } from '../components/FloatingParticles';

interface ToolCard {
  title: string;
  subtitle: string;
  icon: React.ReactNode;
  color: string;
  delay: number;
}

const tools: ToolCard[] = [
  {
    title: 'Send a Message',
    subtitle: 'Real-time chat with your crew',
    color: BRAND.blue,
    delay: 40,
    icon: (
      <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
      </svg>
    ),
  },
  {
    title: 'Snap a Photo',
    subtitle: 'Document anything instantly',
    color: BRAND.green,
    delay: 60,
    icon: (
      <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z" />
        <circle cx="12" cy="13" r="3" />
      </svg>
    ),
  },
  {
    title: 'Take a Video',
    subtitle: 'Show us what you see',
    color: BRAND.amber,
    delay: 80,
    icon: (
      <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <rect x="2" y="6" width="14" height="12" rx="2" />
        <path d="m22 8-6 4 6 4V8z" />
      </svg>
    ),
  },
];

interface ChatMsg {
  text: string;
  sender: 'company' | 'customer';
  delay: number;
}

const chatMessages: ChatMsg[] = [
  { text: 'Just noticed a spot near the window.', sender: 'customer', delay: 115 },
  { text: 'Got it! We\'ll touch that up right now.', sender: 'company', delay: 140 },
  { text: 'Done! Take a look when you can.', sender: 'company', delay: 165 },
];

export const StayInControlScene: React.FC<{ data: PromoVideoProps }> = () => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Title
  const titleOpacity = interpolate(frame, [0, 20], [0, 1], { extrapolateRight: 'clamp' });
  const titleSlide = interpolate(frame, [0, 20], [-30, 0], { extrapolateRight: 'clamp' });

  // Bottom call-out
  const calloutFrame = 185;
  const calloutOpacity = interpolate(frame, [calloutFrame, calloutFrame + 15], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });
  const calloutSlide = interpolate(frame, [calloutFrame, calloutFrame + 15], [20, 0], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // Fade
  const fadeOut = interpolate(frame, [durationInFrames - 15, durationInFrames], [1, 0], {
    extrapolateLeft: 'clamp',
  });

  return (
    <AbsoluteFill style={{ backgroundColor: BRAND.white, opacity: fadeOut }}>
      <FloatingParticles count={10} color={`${BRAND.blue}10`} seed={88} />

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
        <h2
          style={{
            fontSize: TEXT.heading,
            fontWeight: 800,
            color: BRAND.navy,
            margin: 0,
            fontFamily: FONT.system,
          }}
        >
          Stay In Control
        </h2>
        <p style={{ fontSize: TEXT.label, color: BRAND.gray, marginTop: 8, fontFamily: FONT.system }}>
          Notice something? You have the tools.
        </p>
      </div>

      {/* Two columns: Tools + Live chat demo */}
      <div
        style={{
          position: 'absolute',
          top: 200,
          left: 80,
          right: 80,
          display: 'flex',
          gap: 50,
        }}
      >
        {/* Left: Tool cards */}
        <div style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: 20 }}>
          {tools.map((tool) => {
            const toolScale = spring({ frame: frame - tool.delay, fps, config: SPRING_CONFIGS.snappy });
            const toolSlide = interpolate(
              frame,
              [tool.delay, tool.delay + 18],
              [30, 0],
              { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' }
            );

            return (
              <div
                key={tool.title}
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: 22,
                  backgroundColor: BRAND.lightGray,
                  borderRadius: 18,
                  padding: '24px 28px',
                  transform: `scale(${Math.max(0, toolScale)}) translateY(${toolSlide}px)`,
                  transformOrigin: 'left center',
                  border: `1px solid ${tool.color}20`,
                }}
              >
                <div
                  style={{
                    width: 64,
                    height: 64,
                    borderRadius: 16,
                    backgroundColor: `${tool.color}15`,
                    border: `2px solid ${tool.color}30`,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    color: tool.color,
                    flexShrink: 0,
                  }}
                >
                  {tool.icon}
                </div>
                <div>
                  <h3 style={{ fontSize: TEXT.label, fontWeight: 700, color: BRAND.dark, margin: 0, fontFamily: FONT.system }}>
                    {tool.title}
                  </h3>
                  <p style={{ fontSize: TEXT.small, color: BRAND.gray, margin: '4px 0 0', fontFamily: FONT.system }}>
                    {tool.subtitle}
                  </p>
                </div>
              </div>
            );
          })}
        </div>

        {/* Right: Live chat simulation */}
        <div style={{ flex: 1, maxWidth: 550 }}>
          <div
            style={{
              backgroundColor: BRAND.lightGray,
              borderRadius: 20,
              padding: 28,
              boxShadow: '0 8px 30px rgba(0,0,0,0.06)',
              border: `1px solid #E5E7EB`,
            }}
          >
            {/* Chat header */}
            <div
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: 12,
                paddingBottom: 16,
                borderBottom: '1px solid #E5E7EB',
                marginBottom: 16,
              }}
            >
              <div
                style={{
                  width: 44,
                  height: 44,
                  borderRadius: '50%',
                  backgroundColor: BRAND.blue,
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  color: BRAND.white,
                  fontWeight: 700,
                  fontSize: 17,
                  fontFamily: FONT.system,
                }}
              >
                MR
              </div>
              <div>
                <span style={{ fontWeight: 600, fontSize: 19, color: BRAND.dark, fontFamily: FONT.system }}>
                  Mike R. - Lead Painter
                </span>
                <div style={{ display: 'flex', alignItems: 'center', gap: 5, marginTop: 2 }}>
                  <div style={{ width: 8, height: 8, borderRadius: '50%', backgroundColor: BRAND.green }} />
                  <span style={{ fontSize: 14, color: BRAND.green, fontFamily: FONT.system }}>Online</span>
                </div>
              </div>
              <div
                style={{
                  marginLeft: 'auto',
                  backgroundColor: BRAND.red,
                  borderRadius: 6,
                  padding: '3px 10px',
                  transform: `scale(${1 + Math.sin(frame * 0.15) * 0.08})`,
                }}
              >
                <span style={{ fontSize: 11, fontWeight: 700, color: BRAND.white, fontFamily: FONT.system, letterSpacing: 1 }}>
                  LIVE
                </span>
              </div>
            </div>

            {/* Messages */}
            <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
              {chatMessages.map((msg, i) => {
                const msgScale = spring({ frame: frame - msg.delay, fps, config: SPRING_CONFIGS.snappy });
                const msgOpacity = interpolate(frame, [msg.delay, msg.delay + 8], [0, 1], {
                  extrapolateLeft: 'clamp',
                  extrapolateRight: 'clamp',
                });
                const isCustomer = msg.sender === 'customer';

                return (
                  <div
                    key={i}
                    style={{
                      display: 'flex',
                      justifyContent: isCustomer ? 'flex-end' : 'flex-start',
                      opacity: msgOpacity,
                      transform: `scale(${Math.max(0, msgScale)}) translateY(${(1 - Math.max(0, msgScale)) * 15}px)`,
                      transformOrigin: isCustomer ? 'right center' : 'left center',
                    }}
                  >
                    <div
                      style={{
                        maxWidth: '80%',
                        padding: '14px 20px',
                        borderRadius: isCustomer ? '16px 4px 16px 16px' : '4px 16px 16px 16px',
                        backgroundColor: isCustomer ? BRAND.blue : BRAND.white,
                        color: isCustomer ? BRAND.white : BRAND.dark,
                        fontSize: 19,
                        fontFamily: FONT.system,
                        lineHeight: 1.4,
                        boxShadow: isCustomer ? 'none' : '0 2px 8px rgba(0,0,0,0.06)',
                      }}
                    >
                      {msg.text}
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        </div>
      </div>

      {/* Bottom call-out */}
      <div
        style={{
          position: 'absolute',
          bottom: 55,
          left: 0,
          right: 0,
          textAlign: 'center',
          opacity: calloutOpacity,
          transform: `translateY(${calloutSlide}px)`,
        }}
      >
        <p
          style={{
            fontSize: TEXT.subheading,
            fontWeight: 700,
            color: BRAND.navy,
            margin: 0,
            fontFamily: FONT.system,
          }}
        >
          Use the app. We will too.{' '}
          <span style={{ color: BRAND.blue }}>That's the deal.</span>
        </p>
      </div>
    </AbsoluteFill>
  );
};
