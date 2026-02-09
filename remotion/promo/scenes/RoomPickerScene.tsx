import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, SPRING_CONFIGS, STAGGER_OFFSET } from '../brand';
import { PromoVideoProps } from '../types';
import { AnimatedCursor } from '../components/AnimatedCursor';
import { MockupFrame } from '../components/MockupFrame';
import { CountUpNumber } from '../components/CountUpNumber';

interface RoomButton {
  name: string;
  icon: string;
}

const rooms: RoomButton[] = [
  { name: 'Living Room', icon: 'M' },
  { name: 'Bedroom', icon: 'B' },
  { name: 'Kitchen', icon: 'K' },
  { name: 'Bathroom', icon: 'Ba' },
  { name: 'Dining Room', icon: 'D' },
  { name: 'Office', icon: 'O' },
];

export const RoomPickerScene: React.FC<{ data: PromoVideoProps }> = ({ data }) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Scene title
  const titleOpacity = interpolate(frame, [0, 20], [0, 1], { extrapolateRight: 'clamp' });
  const titleSlide = interpolate(frame, [0, 20], [-30, 0], { extrapolateRight: 'clamp' });

  // Subtitle
  const subtitleOpacity = interpolate(frame, [15, 35], [0, 1], { extrapolateRight: 'clamp' });

  // Mockup
  const mockupScale = spring({ frame: frame - 30, fps, config: SPRING_CONFIGS.gentle });
  const mockupOpacity = interpolate(frame, [30, 50], [0, 1], { extrapolateRight: 'clamp' });

  // Room buttons stagger
  const roomButtonStart = 55;

  // Click living room at frame 100
  const clickFrame = 100;
  const livingRoomSelected = frame >= clickFrame;

  // Size dropdown appears
  const sizeFrame = 120;
  const sizeOpacity = interpolate(frame, [sizeFrame, sizeFrame + 15], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // "M" gets highlighted at frame 140
  const mSelectFrame = 140;
  const mSelected = frame >= mSelectFrame;

  // Price springs in
  const priceFrame = 160;
  const priceScale = spring({ frame: frame - priceFrame, fps, config: SPRING_CONFIGS.bouncy });
  const priceOpacity = interpolate(frame, [priceFrame, priceFrame + 10], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // Speed text
  const speedFrame = 190;
  const speedOpacity = interpolate(frame, [speedFrame, speedFrame + 15], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // Fade out
  const fadeOut = interpolate(frame, [durationInFrames - 15, durationInFrames], [1, 0], {
    extrapolateLeft: 'clamp',
  });

  // Cursor waypoints
  const cursorWaypoints = [
    { x: 960, y: 300, frame: 80 },
    { x: 500, y: 400, frame: clickFrame, click: true },
    { x: 700, y: 530, frame: mSelectFrame, click: true },
  ];

  const sizes = ['S', 'M', 'L'];

  return (
    <AbsoluteFill style={{ backgroundColor: BRAND.white, opacity: fadeOut }}>
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
            fontSize: 48,
            fontWeight: 700,
            color: BRAND.navy,
            margin: 0,
            fontFamily: FONT.system,
          }}
        >
          One-Click Room Pricing
        </h2>
        <p
          style={{
            fontSize: 24,
            color: BRAND.gray,
            marginTop: 8,
            fontFamily: FONT.system,
            opacity: subtitleOpacity,
          }}
        >
          Pick a room. Pick a size. Get instant pricing.
        </p>
      </div>

      {/* Mockup */}
      <div
        style={{
          position: 'absolute',
          top: 160,
          left: 200,
          right: 200,
          bottom: 60,
          opacity: mockupOpacity,
          transform: `scale(${Math.max(0.8, mockupScale)})`,
        }}
      >
        <MockupFrame type="browser" url={`${data.website}/estimates/new`}>
          <div style={{ padding: '30px 40px' }}>
            {/* Room grid */}
            <div
              style={{
                display: 'grid',
                gridTemplateColumns: 'repeat(3, 1fr)',
                gap: 16,
                marginBottom: 30,
              }}
            >
              {rooms.map((room, i) => {
                const btnScale = spring({
                  frame: frame - (roomButtonStart + i * 8),
                  fps,
                  config: SPRING_CONFIGS.snappy,
                });
                const isLivingRoom = i === 0;
                const selected = isLivingRoom && livingRoomSelected;

                return (
                  <div
                    key={room.name}
                    style={{
                      transform: `scale(${Math.max(0, btnScale)})`,
                      padding: '18px 16px',
                      borderRadius: 12,
                      backgroundColor: selected ? BRAND.blue : BRAND.lightGray,
                      color: selected ? BRAND.white : BRAND.dark,
                      textAlign: 'center',
                      fontFamily: FONT.system,
                      fontWeight: 600,
                      fontSize: 18,
                      boxShadow: selected
                        ? `0 4px 16px ${BRAND.blue}40`
                        : '0 2px 8px rgba(0,0,0,0.05)',
                      cursor: 'pointer',
                    }}
                  >
                    <div style={{ fontSize: 28, marginBottom: 4, fontWeight: 700 }}>{room.icon}</div>
                    {room.name}
                  </div>
                );
              })}
            </div>

            {/* Size selector */}
            {livingRoomSelected && (
              <div
                style={{
                  opacity: sizeOpacity,
                  display: 'flex',
                  alignItems: 'center',
                  gap: 16,
                  marginBottom: 24,
                }}
              >
                <span style={{ fontSize: 18, fontWeight: 600, color: BRAND.dark, fontFamily: FONT.system }}>
                  Size:
                </span>
                <div style={{ display: 'flex', gap: 8 }}>
                  {sizes.map((size) => {
                    const isM = size === 'M';
                    const active = isM && mSelected;
                    return (
                      <div
                        key={size}
                        style={{
                          width: 56,
                          height: 44,
                          borderRadius: 10,
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'center',
                          backgroundColor: active ? BRAND.blue : BRAND.lightGray,
                          color: active ? BRAND.white : BRAND.dark,
                          fontWeight: 700,
                          fontSize: 20,
                          fontFamily: FONT.system,
                          boxShadow: active ? `0 2px 10px ${BRAND.blue}40` : 'none',
                        }}
                      >
                        {size}
                      </div>
                    );
                  })}
                </div>
              </div>
            )}

            {/* Price display */}
            {frame >= priceFrame && (
              <div
                style={{
                  textAlign: 'center',
                  opacity: priceOpacity,
                  transform: `scale(${Math.max(0, priceScale)})`,
                  padding: '20px 0',
                }}
              >
                <div
                  style={{
                    display: 'inline-block',
                    backgroundColor: BRAND.green + '15',
                    borderRadius: 16,
                    padding: '20px 60px',
                    border: `2px solid ${BRAND.green}30`,
                  }}
                >
                  <CountUpNumber
                    value={827}
                    startFrame={priceFrame}
                    duration={30}
                    prefix="$"
                    style={{
                      fontSize: 72,
                      fontWeight: 800,
                      color: BRAND.green,
                      fontFamily: FONT.system,
                    }}
                  />
                  <p style={{ fontSize: 16, color: BRAND.gray, margin: '6px 0 0', fontFamily: FONT.system }}>
                    Living Room - Medium
                  </p>
                </div>
              </div>
            )}
          </div>
        </MockupFrame>
      </div>

      {/* Speed callout */}
      {frame >= speedFrame && (
        <div
          style={{
            position: 'absolute',
            bottom: 70,
            left: 0,
            right: 0,
            textAlign: 'center',
            opacity: speedOpacity,
          }}
        >
          <span
            style={{
              fontSize: 28,
              fontWeight: 600,
              color: BRAND.blue,
              fontFamily: FONT.system,
            }}
          >
            Faster than a spreadsheet
          </span>
        </div>
      )}

      <AnimatedCursor waypoints={cursorWaypoints} />
    </AbsoluteFill>
  );
};
