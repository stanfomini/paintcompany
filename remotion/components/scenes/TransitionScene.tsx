import { AbsoluteFill, interpolate, spring, useCurrentFrame, useVideoConfig } from 'remotion';

interface TransitionSceneProps {
  text?: string;
  primaryColor?: string;
}

export const TransitionScene: React.FC<TransitionSceneProps> = ({
  text = 'Home Sweet Home',
  primaryColor = '#1e3a5f',
}) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Heart animation
  const heartScale = spring({
    frame,
    fps,
    config: { stiffness: 100, damping: 10 },
  });

  const textOpacity = interpolate(frame, [10, 25], [0, 1], {
    extrapolateRight: 'clamp',
  });

  const textScale = spring({
    frame: frame - 10,
    fps,
    config: { stiffness: 80, damping: 15 },
  });

  // Fade out
  const fadeOut = interpolate(
    frame,
    [durationInFrames - 10, durationInFrames],
    [1, 0],
    { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' }
  );

  // Floating hearts positions
  const hearts = [
    { x: -200, y: -150, size: 30, delay: 0 },
    { x: 200, y: -120, size: 25, delay: 5 },
    { x: -180, y: 50, size: 20, delay: 10 },
    { x: 220, y: 80, size: 28, delay: 8 },
    { x: 0, y: -200, size: 40, delay: 3 },
    { x: -100, y: 120, size: 22, delay: 12 },
    { x: 150, y: -80, size: 24, delay: 6 },
  ];

  return (
    <AbsoluteFill
      style={{
        backgroundColor: 'white',
        justifyContent: 'center',
        alignItems: 'center',
        opacity: fadeOut,
      }}
    >
      {/* Floating Hearts */}
      {hearts.map((heart, index) => {
        const heartOpacity = interpolate(
          frame,
          [heart.delay, heart.delay + 15],
          [0, 1],
          { extrapolateRight: 'clamp' }
        );
        const heartFloat = interpolate(
          frame,
          [0, durationInFrames],
          [0, -20],
          { extrapolateRight: 'clamp' }
        );

        return (
          <div
            key={index}
            style={{
              position: 'absolute',
              left: '50%',
              top: '50%',
              transform: `translate(${heart.x}px, ${heart.y + heartFloat}px)`,
              opacity: heartOpacity,
            }}
          >
            <svg
              width={heart.size}
              height={heart.size}
              viewBox="0 0 24 24"
              fill="#fca5a5"
            >
              <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" />
            </svg>
          </div>
        );
      })}

      {/* Main Text */}
      <div
        style={{
          opacity: textOpacity,
          transform: `scale(${Math.max(0, textScale)})`,
          textAlign: 'center',
        }}
      >
        <h2
          style={{
            fontSize: 80,
            fontWeight: 'bold',
            color: '#111',
            margin: 0,
            fontFamily: 'Georgia, serif',
          }}
        >
          HOME
        </h2>
        <h2
          style={{
            fontSize: 72,
            fontWeight: 400,
            color: '#111',
            margin: '10px 0',
            fontFamily: 'Dancing Script, cursive, Georgia, serif',
            fontStyle: 'italic',
          }}
        >
          sweet
        </h2>
        <h2
          style={{
            fontSize: 80,
            fontWeight: 'bold',
            color: '#111',
            margin: 0,
            fontFamily: 'Georgia, serif',
          }}
        >
          HOME
        </h2>
      </div>

      {/* Large heart above text */}
      <div
        style={{
          position: 'absolute',
          top: '20%',
          transform: `scale(${heartScale})`,
        }}
      >
        <svg width="60" height="60" viewBox="0 0 24 24" fill="#fca5a5">
          <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" />
        </svg>
      </div>
    </AbsoluteFill>
  );
};
