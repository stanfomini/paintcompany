import { AbsoluteFill, interpolate, spring, useCurrentFrame, useVideoConfig } from 'remotion';
import { Estimate } from '../../types';

interface PricingSceneProps {
  estimate?: Estimate;
  scopeOfWork?: string;
  accentColor?: string;
}

export const PricingScene: React.FC<PricingSceneProps> = ({
  estimate,
  scopeOfWork = 'Full interior painting transformation including walls, ceilings, trim, and doors.',
  accentColor = '#10b981',
}) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Animations
  const scopeOpacity = interpolate(frame, [0, 20], [0, 1], {
    extrapolateRight: 'clamp',
  });

  const scopeSlide = interpolate(frame, [0, 20], [30, 0], {
    extrapolateRight: 'clamp',
  });

  const calculationOpacity = interpolate(frame, [25, 45], [0, 1], {
    extrapolateRight: 'clamp',
  });

  const priceScale = spring({
    frame: frame - 50,
    fps,
    config: { stiffness: 80, damping: 15 },
  });

  const priceOpacity = interpolate(frame, [50, 60], [0, 1], {
    extrapolateRight: 'clamp',
  });

  // Fade out
  const fadeOut = interpolate(
    frame,
    [durationInFrames - 15, durationInFrames],
    [1, 0],
    { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' }
  );

  // Format currency
  const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: 'USD',
      minimumFractionDigits: 0,
      maximumFractionDigits: 0,
    }).format(amount);
  };

  // Use estimate data or placeholder
  const hasEstimate = estimate && estimate.total > 0;
  const total = estimate?.total || 0;
  const squareFootage = estimate?.squareFootage;
  const pricePerSqft = estimate?.pricePerSqft;

  return (
    <AbsoluteFill
      style={{
        backgroundColor: 'white',
        justifyContent: 'center',
        alignItems: 'center',
        padding: 80,
        opacity: fadeOut,
      }}
    >
      {/* Scope of Work */}
      <div
        style={{
          opacity: scopeOpacity,
          transform: `translateY(${scopeSlide}px)`,
          textAlign: 'center',
          maxWidth: 1000,
          marginBottom: 60,
        }}
      >
        <p
          style={{
            fontSize: 36,
            fontWeight: 500,
            color: '#333',
            lineHeight: 1.4,
            fontFamily: 'system-ui, -apple-system, sans-serif',
            fontStyle: 'italic',
          }}
        >
          {scopeOfWork}
        </p>
      </div>

      {/* Calculation (if we have sqft data) */}
      {squareFootage && pricePerSqft && (
        <div
          style={{
            opacity: calculationOpacity,
            textAlign: 'center',
            marginBottom: 30,
          }}
        >
          <p
            style={{
              fontSize: 32,
              color: '#666',
              fontFamily: 'system-ui, -apple-system, sans-serif',
            }}
          >
            {squareFootage.toLocaleString()} sqft × ${pricePerSqft.toFixed(2)}
          </p>
        </div>
      )}

      {/* Total Price */}
      {hasEstimate ? (
        <div
          style={{
            opacity: priceOpacity,
            transform: `scale(${Math.max(0, priceScale)})`,
            textAlign: 'center',
          }}
        >
          <p
            style={{
              fontSize: 120,
              fontWeight: 'bold',
              color: accentColor,
              margin: 0,
              fontFamily: 'system-ui, -apple-system, sans-serif',
            }}
          >
            {formatCurrency(total)}
          </p>
        </div>
      ) : (
        /* Placeholder when no estimate */
        <div
          style={{
            opacity: priceOpacity,
            transform: `scale(${Math.max(0, priceScale)})`,
            textAlign: 'center',
            padding: 40,
            border: `3px dashed ${accentColor}`,
            borderRadius: 16,
          }}
        >
          <p
            style={{
              fontSize: 48,
              fontWeight: 'bold',
              color: accentColor,
              margin: 0,
              fontFamily: 'system-ui, -apple-system, sans-serif',
            }}
          >
            Estimate Coming Soon
          </p>
          <p
            style={{
              fontSize: 24,
              color: '#666',
              marginTop: 16,
              fontFamily: 'system-ui, -apple-system, sans-serif',
            }}
          >
            Contact us for a personalized quote
          </p>
        </div>
      )}
    </AbsoluteFill>
  );
};
