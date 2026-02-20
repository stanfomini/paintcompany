import { AbsoluteFill, interpolate, spring, useCurrentFrame, useVideoConfig } from 'remotion';

interface IntroSceneProps {
  address: string;
  city: string;
  state: string;
  companyName?: string;
  primaryColor?: string;
}

export const IntroScene: React.FC<IntroSceneProps> = ({
  address,
  city,
  state,
  companyName = 'Painting Made Easy Denver',
  primaryColor = '#1e3a5f',
}) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Animations
  const titleScale = spring({
    frame,
    fps,
    config: { stiffness: 100, damping: 20 },
  });

  const titleOpacity = interpolate(frame, [0, 15], [0, 1], {
    extrapolateRight: 'clamp',
  });

  const subtitleOpacity = interpolate(frame, [15, 30], [0, 1], {
    extrapolateRight: 'clamp',
  });

  const brandingOpacity = interpolate(frame, [30, 45], [0, 1], {
    extrapolateRight: 'clamp',
  });

  // Fade out at end
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
      {/* Main Title - Address */}
      <div
        style={{
          transform: `scale(${titleScale})`,
          opacity: titleOpacity,
          textAlign: 'center',
        }}
      >
        <h1
          style={{
            fontSize: 72,
            fontWeight: 'bold',
            color: '#111',
            margin: 0,
            fontFamily: 'system-ui, -apple-system, sans-serif',
          }}
        >
          {address}
        </h1>
      </div>

      {/* Subtitle - City, State */}
      <div
        style={{
          opacity: subtitleOpacity,
          marginTop: 20,
        }}
      >
        <p
          style={{
            fontSize: 36,
            color: '#666',
            margin: 0,
            fontFamily: 'system-ui, -apple-system, sans-serif',
          }}
        >
          {city}, {state}
        </p>
      </div>

      {/* Company Branding */}
      <div
        style={{
          position: 'absolute',
          bottom: 80,
          opacity: brandingOpacity,
          display: 'flex',
          alignItems: 'center',
          gap: 16,
        }}
      >
        <div
          style={{
            width: 60,
            height: 60,
            borderRadius: '50%',
            backgroundColor: primaryColor,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            color: 'white',
            fontSize: 24,
            fontWeight: 'bold',
            fontFamily: 'system-ui, -apple-system, sans-serif',
          }}
        >
          {companyName?.charAt(0) || 'A'}
        </div>
        <span
          style={{
            fontSize: 28,
            fontWeight: 600,
            color: primaryColor,
            fontFamily: 'system-ui, -apple-system, sans-serif',
          }}
        >
          {companyName}
        </span>
      </div>
    </AbsoluteFill>
  );
};
