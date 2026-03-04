import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, TEXT, SPRING_CONFIGS } from '../brand';
import { FloatingParticles } from '../components/FloatingParticles';

interface IncomingMessage {
  channel: 'chat' | 'email' | 'messenger' | 'deal';
  from: string;
  text: string;
  color: string;
  delay: number;
  side: 'left' | 'right';
  yPos: number;
}

const incoming: IncomingMessage[] = [
  { channel: 'messenger', from: 'Lisa Wong', text: 'Hey, I saw your ad on Facebook!', color: BRAND.blue, delay: 40, side: 'left', yPos: 200 },
  { channel: 'email', from: 'john@contractor.com', text: 'Invoice #4521 attached', color: BRAND.amber, delay: 60, side: 'right', yPos: 280 },
  { channel: 'chat', from: 'Mike R. (Crew)', text: 'Kitchen is done, starting hallway', color: BRAND.gray, delay: 80, side: 'left', yPos: 400 },
  { channel: 'deal', from: 'Sarah M.', text: 'Can we add the garage?', color: BRAND.purple, delay: 100, side: 'right', yPos: 480 },
  { channel: 'email', from: 'amy@realestate.com', text: 'New referral - 123 Oak St', color: BRAND.amber, delay: 120, side: 'left', yPos: 560 },
  { channel: 'messenger', from: 'New Lead', text: 'How much for exterior paint?', color: BRAND.blue, delay: 140, side: 'right', yPos: 640 },
];

const channelLabels: Record<string, string> = {
  chat: 'Direct Chat',
  email: 'Email',
  messenger: 'Messenger',
  deal: 'Deal Chat',
};

export const AllTogetherScene: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  const titleOpacity = interpolate(frame, [0, 20], [0, 1], { extrapolateRight: 'clamp' });
  const titleSlide = interpolate(frame, [0, 20], [-30, 0], { extrapolateRight: 'clamp' });

  // Central hub icon
  const hubScale = spring({ frame: frame - 10, fps, config: SPRING_CONFIGS.bouncy });
  const hubRotation = frame * 0.5;

  // Counter
  const messageCount = incoming.filter((m) => frame >= m.delay).length;
  const countScale = spring({ frame: frame - 35, fps, config: SPRING_CONFIGS.punch });

  const fadeOut = interpolate(frame, [durationInFrames - 15, durationInFrames], [1, 0], { extrapolateLeft: 'clamp' });

  return (
    <AbsoluteFill
      style={{
        background: `linear-gradient(135deg, ${BRAND.dark} 0%, ${BRAND.navy} 50%, #1a2744 100%)`,
        opacity: fadeOut,
      }}
    >
      <FloatingParticles count={20} color={`${BRAND.sky}15`} seed={99} />

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
          zIndex: 10,
        }}
      >
        <h2 style={{ fontSize: TEXT.heading - 4, fontWeight: 800, color: BRAND.white, margin: 0, fontFamily: FONT.system }}>
          Everything Flows Into{' '}
          <span style={{ color: BRAND.sky }}>One Place</span>
        </h2>
      </div>

      {/* Central hub orb */}
      <div
        style={{
          position: 'absolute',
          top: '50%',
          left: '50%',
          transform: `translate(-50%, -50%) scale(${Math.max(0, hubScale)})`,
          zIndex: 5,
        }}
      >
        {/* Glow */}
        <div
          style={{
            position: 'absolute',
            top: '50%',
            left: '50%',
            width: 300,
            height: 300,
            transform: 'translate(-50%, -50%)',
            borderRadius: '50%',
            background: `radial-gradient(circle, ${BRAND.blue}30 0%, transparent 70%)`,
          }}
        />

        {/* Rotating ring */}
        <div
          style={{
            width: 140,
            height: 140,
            borderRadius: '50%',
            background: `conic-gradient(from ${hubRotation}deg, ${BRAND.blue}, ${BRAND.sky}, ${BRAND.purple}, ${BRAND.amber}, ${BRAND.green}, ${BRAND.blue})`,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            boxShadow: `0 0 80px ${BRAND.blue}40`,
          }}
        >
          <div
            style={{
              width: 120,
              height: 120,
              borderRadius: '50%',
              backgroundColor: BRAND.dark,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              flexDirection: 'column',
            }}
          >
            <span
              style={{
                fontSize: 40,
                fontWeight: 800,
                color: BRAND.white,
                fontFamily: FONT.system,
                transform: `scale(${Math.max(0, countScale)})`,
              }}
            >
              {messageCount}
            </span>
            <span style={{ fontSize: 11, color: `${BRAND.white}88`, fontFamily: FONT.system, fontWeight: 600, letterSpacing: 1 }}>
              NEW
            </span>
          </div>
        </div>
      </div>

      {/* Incoming messages flying to center */}
      {incoming.map((msg, i) => {
        const msgOpacity = interpolate(frame, [msg.delay, msg.delay + 8], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });
        const flyProgress = interpolate(frame, [msg.delay, msg.delay + 30], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });

        const startX = msg.side === 'left' ? -400 : 400;
        const currentX = interpolate(flyProgress, [0, 0.7], [startX, startX * 0.3], { extrapolateRight: 'clamp' });
        const msgScale = interpolate(flyProgress, [0.7, 1], [1, 0.3], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });
        const yToCenter = interpolate(flyProgress, [0.7, 1], [0, (540 - msg.yPos) * 0.5], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });

        return (
          <div
            key={i}
            style={{
              position: 'absolute',
              left: '50%',
              top: msg.yPos,
              transform: `translateX(calc(-50% + ${currentX}px)) translateY(${yToCenter}px) scale(${msgScale})`,
              opacity: msgOpacity * (flyProgress < 0.95 ? 1 : interpolate(flyProgress, [0.95, 1], [1, 0])),
              zIndex: 2,
            }}
          >
            <div
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: 14,
                backgroundColor: `${BRAND.dark}ee`,
                border: `1px solid ${msg.color}40`,
                borderRadius: 14,
                padding: '14px 20px',
                boxShadow: `0 4px 20px ${msg.color}15`,
                whiteSpace: 'nowrap',
              }}
            >
              {/* Channel badge */}
              <div
                style={{
                  width: 10,
                  height: 10,
                  borderRadius: '50%',
                  backgroundColor: msg.color,
                  flexShrink: 0,
                }}
              />
              <div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  <span style={{ fontSize: 15, fontWeight: 700, color: BRAND.white, fontFamily: FONT.system }}>
                    {msg.from}
                  </span>
                  <span
                    style={{
                      fontSize: 10,
                      fontWeight: 600,
                      color: msg.color,
                      backgroundColor: `${msg.color}20`,
                      padding: '2px 6px',
                      borderRadius: 4,
                      fontFamily: FONT.system,
                    }}
                  >
                    {channelLabels[msg.channel]}
                  </span>
                </div>
                <p style={{ fontSize: 13, color: `${BRAND.white}99`, margin: '3px 0 0', fontFamily: FONT.system }}>
                  {msg.text}
                </p>
              </div>
            </div>
          </div>
        );
      })}

      {/* Bottom stat */}
      {(() => {
        const statDelay = 170;
        const statOpacity = interpolate(frame, [statDelay, statDelay + 15], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });
        return (
          <div
            style={{
              position: 'absolute',
              bottom: 60,
              left: 0,
              right: 0,
              textAlign: 'center',
              opacity: statOpacity,
              zIndex: 10,
            }}
          >
            <p style={{ fontSize: TEXT.subheading, fontWeight: 700, color: `${BRAND.white}dd`, margin: 0, fontFamily: FONT.system }}>
              Zero context switching. Zero lost messages.
            </p>
          </div>
        );
      })()}
    </AbsoluteFill>
  );
};
