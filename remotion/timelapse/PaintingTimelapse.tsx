import React from 'react';
import {
  AbsoluteFill,
  useCurrentFrame,
  useVideoConfig,
  spring,
  interpolate,
  Sequence,
} from 'remotion';

// ─── Constants ────────────────────────────────────────────────────────────────

export const TIMELAPSE_FPS = 30;

// Scene durations (frames)
const SCENE = {
  title: 70,
  bareWall: 90,
  firstCoat: 210,
  drying: 90,
  finalCoat: 210,
  reveal: 80,
} as const;

export const TOTAL_TIMELAPSE_FRAMES =
  SCENE.title + SCENE.bareWall + SCENE.firstCoat + SCENE.drying + SCENE.finalCoat + SCENE.reveal;
// 70 + 90 + 210 + 90 + 210 + 80 = 750 frames = 25 seconds

// Cumulative offsets for the continuous timer
const TIMER_SCENE_OFFSET = {
  firstCoat: 0,
  drying: SCENE.firstCoat,
  finalCoat: SCENE.firstCoat + SCENE.drying,
  total: SCENE.firstCoat + SCENE.drying + SCENE.finalCoat, // 510
};

// ─── Colors ───────────────────────────────────────────────────────────────────

const C = {
  dark: '#07111F',
  navy: '#1B3560',
  navyLight: '#2044AA',
  navyDeep: '#0F1F3A',
  sky: '#38BDF8',
  green: '#10B981',
  amber: '#F59E0B',
  white: '#FFFFFF',
  gray: '#9CA3AF',
  wallBare: '#EDE8DF',
  wallTexture: '#E3DDD4',
  tape: '#F0C040',
  primer: '#F6F2EC',
  red: '#EF4444',
};

const FONT = 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
const MONO = '"Courier New", Courier, monospace';

// ─── Helpers ──────────────────────────────────────────────────────────────────

/** Deterministic pseudo-random (no Math.random in Remotion render) */
const sr = (seed: number): number => {
  const x = Math.sin(seed * 9301 + 49297) * 233280;
  return x - Math.floor(x);
};

const clamp = (v: number, lo: number, hi: number): number => Math.max(lo, Math.min(hi, v));

// ─── Shared UI Components ─────────────────────────────────────────────────────

const Vignette: React.FC<{ strength?: number }> = ({ strength = 0.4 }) => (
  <div
    style={{
      position: 'absolute',
      inset: 0,
      background: `radial-gradient(ellipse at 50% 50%, transparent 35%, rgba(0,0,0,${strength}) 100%)`,
      pointerEvents: 'none',
    }}
  />
);

const TapeStrip: React.FC<{ y: number; width: number; horizontal?: boolean }> = ({
  y,
  width,
  horizontal = true,
}) => (
  <div
    style={{
      position: 'absolute',
      top: y - 12,
      left: 0,
      width: horizontal ? width : 24,
      height: horizontal ? 24 : 1080,
      backgroundColor: C.tape,
      opacity: 0.88,
      boxShadow: '0 2px 6px rgba(0,0,0,0.15)',
    }}
  >
    <div
      style={{
        position: 'absolute',
        top: 0,
        left: 0,
        right: 0,
        height: 2,
        backgroundColor: 'rgba(255,255,255,0.45)',
      }}
    />
    <div
      style={{
        position: 'absolute',
        bottom: 0,
        left: 0,
        right: 0,
        height: 2,
        backgroundColor: 'rgba(0,0,0,0.18)',
      }}
    />
  </div>
);

// ─── Paint Roller ─────────────────────────────────────────────────────────────

const PaintRoller: React.FC<{
  x: number;
  y: number;
  color: string;
  facing?: 'right' | 'left';
  stripeH: number;
}> = ({ x, y, color, facing = 'right', stripeH }) => {
  const rollerW = Math.min(72, stripeH * 1.2);
  const rollerH = Math.min(20, stripeH * 0.38);
  const handleH = 40;
  const scaleX = facing === 'left' ? -1 : 1;

  return (
    <div
      style={{
        position: 'absolute',
        left: x - rollerW / 2,
        top: y - handleH - rollerH / 2,
        width: rollerW,
        height: handleH + rollerH + 4,
        transform: `scaleX(${scaleX})`,
        transformOrigin: 'center bottom',
        pointerEvents: 'none',
      }}
    >
      {/* Handle */}
      <div
        style={{
          position: 'absolute',
          left: rollerW / 2 - 3,
          top: 0,
          width: 6,
          height: handleH,
          backgroundColor: '#A0A0A0',
          borderRadius: 3,
          transform: 'rotate(15deg)',
          transformOrigin: 'bottom center',
          boxShadow: '1px 1px 3px rgba(0,0,0,0.3)',
        }}
      />
      {/* Roller cylinder */}
      <div
        style={{
          position: 'absolute',
          left: 0,
          bottom: 0,
          width: rollerW,
          height: rollerH,
          backgroundColor: color,
          borderRadius: rollerH / 2,
          boxShadow: `0 3px 10px rgba(0,0,0,0.35), inset 0 2px 4px rgba(255,255,255,0.18)`,
        }}
      />
      {/* Wet paint drip at bottom edge */}
      <div
        style={{
          position: 'absolute',
          left: rollerW * 0.3,
          bottom: -4,
          width: 4,
          height: 8,
          backgroundColor: color,
          borderRadius: '0 0 4px 4px',
          opacity: 0.7,
        }}
      />
    </div>
  );
};

// ─── Paint Stripes ────────────────────────────────────────────────────────────

interface PaintStripesProps {
  startFrame: number;
  color: string;
  stripeCount?: number;
  framesBetween?: number;
  stripeFrames?: number;
  alternating?: boolean;
}

const PaintStripes: React.FC<PaintStripesProps> = ({
  startFrame,
  color,
  stripeCount = 18,
  framesBetween = 9,
  stripeFrames = 28,
  alternating = true,
}) => {
  const frame = useCurrentFrame();
  const { width, height } = useVideoConfig();
  const stripeH = height / stripeCount;

  return (
    <>
      {Array.from({ length: stripeCount }).map((_, i) => {
        const reverse = alternating && i % 2 === 1;
        const thisStart = startFrame + i * framesBetween;
        const progress = interpolate(frame, [thisStart, thisStart + stripeFrames], [0, 1], {
          extrapolateLeft: 'clamp',
          extrapolateRight: 'clamp',
        });

        const stripeW = progress * width;
        const xPos = reverse ? width - stripeW : 0;

        // Subtle color variation for a realistic painted look
        const factor = sr(i * 7) * 0.08 - 0.04;
        const hex = color.replace('#', '');
        const r = clamp(Math.round(parseInt(hex.slice(0, 2), 16) * (1 + factor)), 0, 255);
        const g = clamp(Math.round(parseInt(hex.slice(2, 4), 16) * (1 + factor)), 0, 255);
        const b = clamp(Math.round(parseInt(hex.slice(4, 6), 16) * (1 + factor)), 0, 255);
        const stripeColor = `rgb(${r},${g},${b})`;

        const rollerVisible = progress > 0.01 && progress < 0.99;
        const rollerX = reverse ? width - stripeW : stripeW;
        const rollerY = i * stripeH + stripeH / 2;

        return (
          <React.Fragment key={i}>
            {/* Painted stripe */}
            <div
              style={{
                position: 'absolute',
                top: i * stripeH,
                left: xPos,
                width: stripeW,
                height: stripeH + 1, // +1 prevents sub-pixel gaps
                backgroundColor: stripeColor,
              }}
            />

            {/* Wet-paint sheen at the leading edge */}
            {rollerVisible && (
              <div
                style={{
                  position: 'absolute',
                  top: i * stripeH - 1,
                  left: reverse ? width - stripeW - 8 : stripeW - 4,
                  width: 16,
                  height: stripeH + 2,
                  background: reverse
                    ? `linear-gradient(to left, rgba(255,255,255,0.25) 0%, transparent 100%)`
                    : `linear-gradient(to right, transparent 0%, rgba(255,255,255,0.25) 100%)`,
                  pointerEvents: 'none',
                }}
              />
            )}

            {/* Paint roller */}
            {rollerVisible && (
              <PaintRoller
                x={rollerX}
                y={rollerY}
                color={stripeColor}
                facing={reverse ? 'left' : 'right'}
                stripeH={stripeH}
              />
            )}
          </React.Fragment>
        );
      })}
    </>
  );
};

// ─── Timelapse Badge (continuous timer across painting scenes) ─────────────────

const TimelapseBadge: React.FC<{ frameOffset?: number }> = ({ frameOffset = 0 }) => {
  const frame = useCurrentFrame();
  const total = TIMER_SCENE_OFFSET.total; // 510 frames of painting
  const cumulative = clamp(frame + frameOffset, 0, total);
  const seconds = Math.floor((cumulative / total) * 167); // max 2:47
  const mm = Math.floor(seconds / 60);
  const ss = seconds % 60;
  const timeStr = `${mm}:${ss.toString().padStart(2, '0')}`;

  const recPulse = Math.floor(frame / 18) % 2 === 0 ? 1 : 0.25;

  return (
    <div
      style={{
        position: 'absolute',
        bottom: 36,
        right: 36,
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        backgroundColor: 'rgba(0,0,0,0.72)',
        borderRadius: 8,
        padding: '9px 18px',
        border: '1px solid rgba(255,255,255,0.08)',
      }}
    >
      {/* REC dot */}
      <div
        style={{
          width: 9,
          height: 9,
          borderRadius: '50%',
          backgroundColor: C.red,
          opacity: recPulse,
          boxShadow: recPulse > 0.5 ? `0 0 6px ${C.red}` : 'none',
        }}
      />
      <span
        style={{
          color: C.white,
          fontFamily: MONO,
          fontSize: 20,
          fontWeight: 700,
          letterSpacing: 3,
        }}
      >
        {timeStr}
      </span>
      <div
        style={{
          borderLeft: '1px solid rgba(255,255,255,0.25)',
          paddingLeft: 10,
          color: C.amber,
          fontFamily: FONT,
          fontSize: 13,
          fontWeight: 700,
          letterSpacing: 1.5,
          textTransform: 'uppercase',
        }}
      >
        16× Speed
      </div>
    </div>
  );
};

// ─── Scene 1: Title Card ──────────────────────────────────────────────────────

const TitleScene: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();

  const bgOpacity = interpolate(frame, [0, 18], [0, 1], { extrapolateRight: 'clamp' });
  const fadeOut = interpolate(frame, [52, 70], [1, 0], { extrapolateLeft: 'clamp' });

  const iconScale = spring({ frame: frame - 5, fps, config: { stiffness: 100, damping: 14 } });

  const titleOpacity = interpolate(frame, [22, 42], [0, 1], { extrapolateRight: 'clamp' });
  const titleY = interpolate(frame, [22, 42], [28, 0], { extrapolateRight: 'clamp' });

  const subtitleOpacity = interpolate(frame, [42, 60], [0, 1], { extrapolateRight: 'clamp' });

  return (
    <AbsoluteFill
      style={{
        background: `linear-gradient(140deg, ${C.dark} 0%, #0E1E40 55%, ${C.dark} 100%)`,
        opacity: bgOpacity * fadeOut,
        justifyContent: 'center',
        alignItems: 'center',
        flexDirection: 'column',
        gap: 0,
      }}
    >
      {/* Radial glow behind icon */}
      <div
        style={{
          position: 'absolute',
          top: '50%',
          left: '50%',
          transform: 'translate(-50%, -55%)',
          width: 600,
          height: 600,
          borderRadius: '50%',
          background: `radial-gradient(circle, ${C.navy}30 0%, transparent 70%)`,
        }}
      />

      {/* Paintbrush icon */}
      <div
        style={{
          transform: `scale(${clamp(iconScale, 0, 1)})`,
          marginBottom: 28,
        }}
      >
        <div
          style={{
            width: 110,
            height: 110,
            borderRadius: 28,
            background: `linear-gradient(135deg, ${C.navy}, ${C.navyLight})`,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            boxShadow: `0 12px 48px ${C.navy}80`,
          }}
        >
          <svg
            width="60"
            height="60"
            viewBox="0 0 24 24"
            fill="none"
            stroke={C.white}
            strokeWidth="1.7"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <path d="M18.37 2.63 14 7l-1.59-1.59a2 2 0 0 0-2.82 0L8 7l9 9 1.59-1.59a2 2 0 0 0 0-2.82L17 10l4.37-4.37a2.12 2.12 0 1 0-3-3Z" />
            <path d="M9 8c-2 3-4 3.5-7 4l8 10c2-1 6-5 6-7" />
            <path d="M14.5 17.5 4.5 15" />
          </svg>
        </div>
      </div>

      {/* Title */}
      <div
        style={{
          opacity: titleOpacity,
          transform: `translateY(${titleY}px)`,
          textAlign: 'center',
        }}
      >
        <div
          style={{
            fontSize: 88,
            fontWeight: 800,
            color: C.white,
            fontFamily: FONT,
            letterSpacing: -3,
            lineHeight: 0.95,
          }}
        >
          PAINTING
        </div>
        <div
          style={{
            fontSize: 50,
            fontWeight: 200,
            color: C.sky,
            fontFamily: FONT,
            letterSpacing: 18,
            lineHeight: 1.3,
            textTransform: 'uppercase',
          }}
        >
          TIMELAPSE
        </div>
      </div>

      {/* Subtitle */}
      <div
        style={{
          opacity: subtitleOpacity,
          marginTop: 32,
          display: 'flex',
          alignItems: 'center',
          gap: 14,
        }}
      >
        <div
          style={{
            width: 48,
            height: 1,
            background: `linear-gradient(to right, transparent, ${C.sky}60)`,
          }}
        />
        <span
          style={{
            fontSize: 20,
            fontWeight: 400,
            color: `${C.white}70`,
            fontFamily: FONT,
            letterSpacing: 5,
            textTransform: 'uppercase',
          }}
        >
          Painting Made Easy Denver
        </span>
        <div
          style={{
            width: 48,
            height: 1,
            background: `linear-gradient(to left, transparent, ${C.sky}60)`,
          }}
        />
      </div>

      <Vignette strength={0.5} />
    </AbsoluteFill>
  );
};

// ─── Scene 2: Bare Wall ───────────────────────────────────────────────────────

const BareWallScene: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps, width, height } = useVideoConfig();

  const fadeIn = interpolate(frame, [0, 22], [0, 1], { extrapolateRight: 'clamp' });
  const fadeOut = interpolate(frame, [72, 90], [1, 0], { extrapolateLeft: 'clamp' });

  const badgeScale = spring({ frame: frame - 28, fps, config: { stiffness: 115, damping: 14 } });

  const labelOpacity = interpolate(frame, [44, 64], [0, 1], { extrapolateRight: 'clamp' });
  const labelY = interpolate(frame, [44, 64], [20, 0], { extrapolateRight: 'clamp' });

  return (
    <AbsoluteFill style={{ opacity: fadeIn * fadeOut }}>
      {/* Bare wall */}
      <div
        style={{
          position: 'absolute',
          inset: 0,
          background: `linear-gradient(175deg, ${C.wallBare} 0%, ${C.wallTexture} 100%)`,
        }}
      />

      {/* Subtle drywall joint lines */}
      {[280, 560, 840, 1120, 1400, 1680].map((x) => (
        <div
          key={x}
          style={{
            position: 'absolute',
            top: 0,
            bottom: 0,
            left: x,
            width: 1,
            backgroundColor: 'rgba(0,0,0,0.04)',
          }}
        />
      ))}
      {[360, 720].map((y) => (
        <div
          key={y}
          style={{
            position: 'absolute',
            left: 0,
            right: 0,
            top: y,
            height: 1,
            backgroundColor: 'rgba(0,0,0,0.04)',
          }}
        />
      ))}

      {/* Painter's tape borders */}
      <TapeStrip y={80} width={width} />
      <TapeStrip y={height - 80} width={width} />
      {/* Vertical tape at sides */}
      {[80, width - 56].map((x) => (
        <div
          key={x}
          style={{
            position: 'absolute',
            top: 80,
            bottom: 80,
            left: x,
            width: 24,
            backgroundColor: C.tape,
            opacity: 0.85,
          }}
        />
      ))}

      {/* BEFORE badge */}
      <div
        style={{
          position: 'absolute',
          top: 36,
          left: 36,
          transform: `scale(${clamp(badgeScale, 0, 1)})`,
          transformOrigin: 'top left',
        }}
      >
        <div
          style={{
            display: 'inline-flex',
            alignItems: 'center',
            gap: 10,
            backgroundColor: 'rgba(0,0,0,0.72)',
            borderRadius: 8,
            padding: '11px 22px',
          }}
        >
          <div
            style={{
              width: 11,
              height: 11,
              borderRadius: '50%',
              backgroundColor: C.gray,
            }}
          />
          <span
            style={{
              color: C.white,
              fontFamily: FONT,
              fontSize: 19,
              fontWeight: 700,
              letterSpacing: 3,
            }}
          >
            BEFORE
          </span>
        </div>
      </div>

      {/* Center label */}
      <div
        style={{
          position: 'absolute',
          inset: 0,
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          opacity: labelOpacity,
          transform: `translateY(${labelY}px)`,
        }}
      >
        <p
          style={{
            fontSize: 30,
            fontWeight: 300,
            color: 'rgba(0,0,0,0.22)',
            fontFamily: FONT,
            fontStyle: 'italic',
            letterSpacing: 4,
            margin: 0,
          }}
        >
          Ready to transform...
        </p>
      </div>

      <Vignette strength={0.18} />
    </AbsoluteFill>
  );
};

// ─── Scene 3: First Coat (Primer) ─────────────────────────────────────────────

const FirstCoatScene: React.FC = () => {
  const frame = useCurrentFrame();
  const { width, height } = useVideoConfig();

  const fadeIn = interpolate(frame, [0, 14], [0, 1], { extrapolateRight: 'clamp' });
  const fadeOut = interpolate(frame, [196, 210], [1, 0], { extrapolateLeft: 'clamp' });

  // "First Coat" label fades in early
  const labelOpacity = interpolate(frame, [8, 28], [0, 1], { extrapolateRight: 'clamp' });

  return (
    <AbsoluteFill style={{ opacity: fadeIn * fadeOut }}>
      {/* Bare wall background showing through */}
      <div
        style={{
          position: 'absolute',
          inset: 0,
          backgroundColor: C.wallBare,
        }}
      />

      {/* Primer stripes sweeping L→R / R→L alternating */}
      <PaintStripes
        startFrame={8}
        color={C.primer}
        stripeCount={18}
        framesBetween={9}
        stripeFrames={30}
        alternating
      />

      {/* Tape on top */}
      <TapeStrip y={80} width={width} />
      <TapeStrip y={height - 80} width={width} />

      {/* Coat label */}
      <div
        style={{
          position: 'absolute',
          top: 36,
          left: 36,
          opacity: labelOpacity,
        }}
      >
        <div
          style={{
            display: 'inline-flex',
            alignItems: 'center',
            gap: 10,
            backgroundColor: 'rgba(0,0,0,0.65)',
            borderRadius: 8,
            padding: '10px 20px',
          }}
        >
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#F6F2EC" strokeWidth="2.5" strokeLinecap="round">
            <path d="M18.37 2.63 14 7l-1.59-1.59a2 2 0 0 0-2.82 0L8 7l9 9 1.59-1.59a2 2 0 0 0 0-2.82L17 10l4.37-4.37a2.12 2.12 0 1 0-3-3Z" />
          </svg>
          <span
            style={{
              color: C.white,
              fontFamily: FONT,
              fontSize: 18,
              fontWeight: 600,
              letterSpacing: 2,
            }}
          >
            PRIMER COAT
          </span>
        </div>
      </div>

      <TimelapseBadge frameOffset={TIMER_SCENE_OFFSET.firstCoat} />
      <Vignette strength={0.2} />
    </AbsoluteFill>
  );
};

// ─── Scene 4: Drying ──────────────────────────────────────────────────────────

const DryingScene: React.FC = () => {
  const frame = useCurrentFrame();
  const { width, height } = useVideoConfig();

  const fadeIn = interpolate(frame, [0, 14], [0, 1], { extrapolateRight: 'clamp' });
  const fadeOut = interpolate(frame, [76, 90], [1, 0], { extrapolateLeft: 'clamp' });

  const dotCount = Math.floor(frame / 22) % 4;
  const shimmer = Math.sin(frame * 0.12) * 0.015;

  const msgOpacity = interpolate(frame, [10, 28], [0, 1], { extrapolateRight: 'clamp' });

  return (
    <AbsoluteFill style={{ opacity: fadeIn * fadeOut }}>
      {/* Primed wall */}
      <div
        style={{
          position: 'absolute',
          inset: 0,
          background: `linear-gradient(175deg, ${C.primer} 0%, #E8E4DC 100%)`,
        }}
      />

      {/* Sheen shimmer overlay */}
      <div
        style={{
          position: 'absolute',
          inset: 0,
          background: `linear-gradient(120deg, transparent 30%, rgba(255,255,255,${0.06 + shimmer}) 60%, transparent 80%)`,
        }}
      />

      {/* Tape */}
      <TapeStrip y={80} width={width} />
      <TapeStrip y={height - 80} width={width} />

      {/* Drying message */}
      <div
        style={{
          position: 'absolute',
          inset: 0,
          display: 'flex',
          flexDirection: 'column',
          alignItems: 'center',
          justifyContent: 'center',
          gap: 24,
          opacity: msgOpacity,
        }}
      >
        {/* Paint drop icon */}
        <svg width="56" height="72" viewBox="0 0 56 72" fill="none">
          <path
            d="M28 6 C28 6, 6 36, 6 50 C6 64, 16 68, 28 68 C40 68, 50 64, 50 50 C50 36, 28 6, 28 6Z"
            fill={C.primer}
            stroke="rgba(0,0,0,0.12)"
            strokeWidth="1.5"
          />
          <path
            d="M18 52 Q24 48, 32 52"
            stroke="rgba(255,255,255,0.5)"
            strokeWidth="2"
            strokeLinecap="round"
            fill="none"
          />
        </svg>

        <div
          style={{
            backgroundColor: 'rgba(0,0,0,0.62)',
            borderRadius: 12,
            padding: '16px 36px',
          }}
        >
          <p
            style={{
              color: C.white,
              fontFamily: FONT,
              fontSize: 28,
              fontWeight: 600,
              margin: 0,
              letterSpacing: 2,
            }}
          >
            PRIMER COAT DRYING{'.'.repeat(dotCount)}
          </p>
        </div>

        <p
          style={{
            color: 'rgba(0,0,0,0.3)',
            fontFamily: FONT,
            fontSize: 20,
            fontWeight: 300,
            fontStyle: 'italic',
            letterSpacing: 2,
            margin: 0,
          }}
        >
          Waiting for the perfect base...
        </p>
      </div>

      <TimelapseBadge frameOffset={TIMER_SCENE_OFFSET.drying} />
      <Vignette strength={0.15} />
    </AbsoluteFill>
  );
};

// ─── Scene 5: Final Color Coat ────────────────────────────────────────────────

const FinalCoatScene: React.FC = () => {
  const frame = useCurrentFrame();
  const { width, height } = useVideoConfig();

  const fadeIn = interpolate(frame, [0, 14], [0, 1], { extrapolateRight: 'clamp' });
  const fadeOut = interpolate(frame, [196, 210], [1, 0], { extrapolateLeft: 'clamp' });

  const labelOpacity = interpolate(frame, [8, 28], [0, 1], { extrapolateRight: 'clamp' });

  return (
    <AbsoluteFill style={{ opacity: fadeIn * fadeOut }}>
      {/* Primer base showing through */}
      <div
        style={{
          position: 'absolute',
          inset: 0,
          backgroundColor: C.primer,
        }}
      />

      {/* Final color stripes – right-to-left on even rows, left-to-right on odd */}
      <PaintStripes
        startFrame={8}
        color={C.navy}
        stripeCount={18}
        framesBetween={9}
        stripeFrames={30}
        alternating
      />

      {/* Tape */}
      <TapeStrip y={80} width={width} />
      <TapeStrip y={height - 80} width={width} />

      {/* Coat label */}
      <div
        style={{
          position: 'absolute',
          top: 36,
          left: 36,
          opacity: labelOpacity,
        }}
      >
        <div
          style={{
            display: 'inline-flex',
            alignItems: 'center',
            gap: 10,
            background: `linear-gradient(135deg, ${C.navy}E0, ${C.navyLight}E0)`,
            borderRadius: 8,
            padding: '10px 20px',
            boxShadow: `0 4px 16px ${C.navy}60`,
          }}
        >
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke={C.sky} strokeWidth="2.5" strokeLinecap="round">
            <path d="M18.37 2.63 14 7l-1.59-1.59a2 2 0 0 0-2.82 0L8 7l9 9 1.59-1.59a2 2 0 0 0 0-2.82L17 10l4.37-4.37a2.12 2.12 0 1 0-3-3Z" />
          </svg>
          <span
            style={{
              color: C.white,
              fontFamily: FONT,
              fontSize: 18,
              fontWeight: 600,
              letterSpacing: 2,
            }}
          >
            FINAL COAT — Deep Navy
          </span>
        </div>
      </div>

      <TimelapseBadge frameOffset={TIMER_SCENE_OFFSET.finalCoat} />
      <Vignette strength={0.28} />
    </AbsoluteFill>
  );
};

// ─── Scene 6: Reveal ──────────────────────────────────────────────────────────

const RevealScene: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();

  const fadeIn = interpolate(frame, [0, 18], [0, 1], { extrapolateRight: 'clamp' });

  const badgeScale = spring({ frame: frame - 8, fps, config: { stiffness: 115, damping: 13 } });

  const titleOpacity = interpolate(frame, [18, 38], [0, 1], { extrapolateRight: 'clamp' });
  const titleY = interpolate(frame, [18, 38], [28, 0], { extrapolateRight: 'clamp' });

  const dividerWidth = interpolate(frame, [36, 62], [0, 380], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  const ctaScale = spring({ frame: frame - 50, fps, config: { stiffness: 100, damping: 12 } });
  const ctaOpacity = interpolate(frame, [50, 68], [0, 1], { extrapolateRight: 'clamp' });

  // Ambient sparkles
  const sparkles = Array.from({ length: 14 }).map((_, i) => ({
    x: sr(i * 7) * 100,
    y: sr(i * 13) * 100,
    r: 3 + sr(i * 19) * 7,
    delay: Math.floor(sr(i * 31) * 25),
  }));

  return (
    <AbsoluteFill style={{ opacity: fadeIn }}>
      {/* Painted wall — deep navy */}
      <div
        style={{
          position: 'absolute',
          inset: 0,
          background: `linear-gradient(155deg, ${C.navy} 0%, ${C.navyDeep} 55%, ${C.navy} 100%)`,
        }}
      />

      {/* Paint sheen highlight */}
      <div
        style={{
          position: 'absolute',
          inset: 0,
          background: `radial-gradient(ellipse at 28% 38%, rgba(255,255,255,0.07) 0%, transparent 55%)`,
        }}
      />

      {/* Sparkles / dust motes */}
      {sparkles.map((s, i) => {
        const op =
          interpolate(frame, [16 + s.delay, 36 + s.delay], [0, 1], {
            extrapolateLeft: 'clamp',
            extrapolateRight: 'clamp',
          }) *
          (0.5 + Math.sin(frame * 0.14 + i * 1.2) * 0.35);

        return (
          <div
            key={i}
            style={{
              position: 'absolute',
              left: `${s.x}%`,
              top: `${s.y}%`,
              width: s.r,
              height: s.r,
              borderRadius: '50%',
              backgroundColor: C.sky,
              opacity: op * 0.45,
              boxShadow: `0 0 ${s.r * 2}px ${C.sky}`,
            }}
          />
        );
      })}

      {/* AFTER badge */}
      <div
        style={{
          position: 'absolute',
          top: 36,
          left: 36,
          transform: `scale(${clamp(badgeScale, 0, 1)})`,
          transformOrigin: 'top left',
        }}
      >
        <div
          style={{
            display: 'inline-flex',
            alignItems: 'center',
            gap: 10,
            backgroundColor: C.green,
            borderRadius: 8,
            padding: '11px 22px',
            boxShadow: `0 4px 20px rgba(16,185,129,0.45)`,
          }}
        >
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="white" strokeWidth="3" strokeLinecap="round">
            <path d="M20 6 9 17l-5-5" />
          </svg>
          <span
            style={{
              color: C.white,
              fontFamily: FONT,
              fontSize: 19,
              fontWeight: 700,
              letterSpacing: 3,
            }}
          >
            AFTER
          </span>
        </div>
      </div>

      {/* Center content */}
      <AbsoluteFill
        style={{
          justifyContent: 'center',
          alignItems: 'center',
          flexDirection: 'column',
          gap: 0,
        }}
      >
        {/* Main headline */}
        <div
          style={{
            opacity: titleOpacity,
            transform: `translateY(${titleY}px)`,
            textAlign: 'center',
          }}
        >
          <div
            style={{
              fontSize: 82,
              fontWeight: 800,
              color: C.white,
              fontFamily: FONT,
              letterSpacing: -2,
              lineHeight: 0.95,
            }}
          >
            Beautifully
          </div>
          <div
            style={{
              fontSize: 82,
              fontWeight: 200,
              color: C.sky,
              fontFamily: FONT,
              letterSpacing: 5,
              lineHeight: 1.15,
              textTransform: 'uppercase',
            }}
          >
            Transformed
          </div>
        </div>

        {/* Divider */}
        <div
          style={{
            width: dividerWidth,
            height: 2,
            background: `linear-gradient(90deg, transparent, ${C.sky}, transparent)`,
            borderRadius: 2,
            marginTop: 32,
            marginBottom: 28,
          }}
        />

        {/* Company tagline */}
        <div
          style={{
            opacity: ctaOpacity,
            textAlign: 'center',
          }}
        >
          <p
            style={{
              fontSize: 26,
              fontWeight: 300,
              color: `${C.white}88`,
              fontFamily: FONT,
              letterSpacing: 5,
              textTransform: 'uppercase',
              margin: '0 0 28px',
            }}
          >
            Painting Made Easy Denver
          </p>
        </div>

        {/* CTA button */}
        <div
          style={{
            opacity: ctaOpacity,
            transform: `scale(${clamp(ctaScale, 0, 1.05)})`,
          }}
        >
          <div
            style={{
              background: `linear-gradient(135deg, ${C.green}, #059669)`,
              borderRadius: 16,
              padding: '20px 58px',
              boxShadow: `0 8px 32px rgba(16,185,129,0.5)`,
            }}
          >
            <span
              style={{
                fontSize: 26,
                fontWeight: 700,
                color: C.white,
                fontFamily: FONT,
                letterSpacing: 1.5,
              }}
            >
              GET YOUR FREE ESTIMATE
            </span>
          </div>
        </div>
      </AbsoluteFill>

      <Vignette strength={0.38} />
    </AbsoluteFill>
  );
};

// ─── Root Composition ─────────────────────────────────────────────────────────

export const PaintingTimelapse: React.FC = () => {
  const sceneOffsets = {
    title: 0,
    bareWall: SCENE.title,
    firstCoat: SCENE.title + SCENE.bareWall,
    drying: SCENE.title + SCENE.bareWall + SCENE.firstCoat,
    finalCoat: SCENE.title + SCENE.bareWall + SCENE.firstCoat + SCENE.drying,
    reveal: SCENE.title + SCENE.bareWall + SCENE.firstCoat + SCENE.drying + SCENE.finalCoat,
  };

  return (
    <AbsoluteFill style={{ backgroundColor: C.dark }}>
      <Sequence from={sceneOffsets.title} durationInFrames={SCENE.title}>
        <TitleScene />
      </Sequence>

      <Sequence from={sceneOffsets.bareWall} durationInFrames={SCENE.bareWall}>
        <BareWallScene />
      </Sequence>

      <Sequence from={sceneOffsets.firstCoat} durationInFrames={SCENE.firstCoat}>
        <FirstCoatScene />
      </Sequence>

      <Sequence from={sceneOffsets.drying} durationInFrames={SCENE.drying}>
        <DryingScene />
      </Sequence>

      <Sequence from={sceneOffsets.finalCoat} durationInFrames={SCENE.finalCoat}>
        <FinalCoatScene />
      </Sequence>

      <Sequence from={sceneOffsets.reveal} durationInFrames={SCENE.reveal}>
        <RevealScene />
      </Sequence>
    </AbsoluteFill>
  );
};
