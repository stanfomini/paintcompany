import { AbsoluteFill, interpolate, spring, useCurrentFrame, useVideoConfig } from 'remotion';

interface TimelineSceneProps {
  completionWeeks?: number;
  primaryColor?: string;
  accentColor?: string;
}

export const TimelineScene: React.FC<TimelineSceneProps> = ({
  completionWeeks = 3,
  primaryColor = '#1e3a5f',
  accentColor = '#d4a853',
}) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Animations
  const textOpacity = interpolate(frame, [0, 20], [0, 1], {
    extrapolateRight: 'clamp',
  });

  const textSlide = interpolate(frame, [0, 20], [-30, 0], {
    extrapolateRight: 'clamp',
  });

  const clockScale = spring({
    frame: frame - 20,
    fps,
    config: { stiffness: 100, damping: 15 },
  });

  const clockOpacity = interpolate(frame, [20, 35], [0, 1], {
    extrapolateRight: 'clamp',
  });

  const guaranteeScale = spring({
    frame: frame - 45,
    fps,
    config: { stiffness: 80, damping: 12 },
  });

  const guaranteeOpacity = interpolate(frame, [45, 55], [0, 1], {
    extrapolateRight: 'clamp',
  });

  // Fade out
  const fadeOut = interpolate(
    frame,
    [durationInFrames - 15, durationInFrames],
    [1, 0],
    { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' }
  );

  return (
    <AbsoluteFill
      style={{
        backgroundColor: 'white',
        justifyContent: 'center',
        alignItems: 'center',
        opacity: fadeOut,
      }}
    >
      {/* Main Text */}
      <div
        style={{
          opacity: textOpacity,
          transform: `translateY(${textSlide}px)`,
          textAlign: 'center',
          marginBottom: 50,
        }}
      >
        <h2
          style={{
            fontSize: 56,
            fontWeight: 'bold',
            color: '#111',
            margin: 0,
            lineHeight: 1.3,
            fontFamily: 'system-ui, -apple-system, sans-serif',
          }}
        >
          Project to start at close;
          <br />
          to be completed in {completionWeeks} weeks!
        </h2>
      </div>

      {/* Clock Icon */}
      <div
        style={{
          opacity: clockOpacity,
          transform: `scale(${Math.max(0, clockScale)})`,
          marginBottom: 50,
        }}
      >
        <svg
          width="120"
          height="120"
          viewBox="0 0 24 24"
          fill="none"
          stroke="#333"
          strokeWidth="1.5"
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <circle cx="12" cy="12" r="10" />
          <polyline points="12 6 12 12 16 14" />
        </svg>
      </div>

      {/* Guarantee Badge */}
      <div
        style={{
          opacity: guaranteeOpacity,
          transform: `scale(${Math.max(0, guaranteeScale)})`,
          display: 'flex',
          alignItems: 'center',
          gap: 0,
        }}
      >
        {/* Shield */}
        <div
          style={{
            width: 80,
            height: 90,
            backgroundColor: '#1f2937',
            borderRadius: '50% 50% 50% 50% / 30% 30% 70% 70%',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            marginRight: -10,
            zIndex: 1,
          }}
        >
          <svg
            width="40"
            height="40"
            viewBox="0 0 24 24"
            fill="none"
            stroke={accentColor}
            strokeWidth="3"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <polyline points="20 6 9 17 4 12" />
          </svg>
        </div>

        {/* Guarantee Text */}
        <div
          style={{
            backgroundColor: accentColor,
            padding: '16px 40px 16px 30px',
            borderRadius: '0 30px 30px 0',
          }}
        >
          <span
            style={{
              fontSize: 32,
              fontWeight: 'bold',
              color: '#1f2937',
              fontFamily: 'system-ui, -apple-system, sans-serif',
              letterSpacing: 2,
            }}
          >
            GUARANTEE
          </span>
        </div>
      </div>
    </AbsoluteFill>
  );
};
