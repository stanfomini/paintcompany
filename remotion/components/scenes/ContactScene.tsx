import { AbsoluteFill, interpolate, spring, useCurrentFrame, useVideoConfig } from 'remotion';

interface ContactSceneProps {
  address: string;
  city: string;
  companyName?: string;
  companyPhone?: string;
  companyTagline?: string;
  qrCodeUrl?: string;
  primaryColor?: string;
}

export const ContactScene: React.FC<ContactSceneProps> = ({
  address,
  city,
  companyName = 'Painting Made Easy Denver',
  companyPhone = '(555) 123-4567',
  companyTagline = 'Professional Painting Services',
  qrCodeUrl,
  primaryColor = '#1e3a5f',
}) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Animations
  const headerOpacity = interpolate(frame, [0, 15], [0, 1], {
    extrapolateRight: 'clamp',
  });

  const headerSlide = interpolate(frame, [0, 15], [-20, 0], {
    extrapolateRight: 'clamp',
  });

  const contentOpacity = interpolate(frame, [15, 30], [0, 1], {
    extrapolateRight: 'clamp',
  });

  const qrScale = spring({
    frame: frame - 25,
    fps,
    config: { stiffness: 100, damping: 15 },
  });

  const ctaOpacity = interpolate(frame, [40, 55], [0, 1], {
    extrapolateRight: 'clamp',
  });

  // No fade out for last scene - hold until end
  const opacity = interpolate(frame, [0, 10], [0, 1], {
    extrapolateRight: 'clamp',
  });

  return (
    <AbsoluteFill
      style={{
        backgroundColor: 'white',
        justifyContent: 'center',
        alignItems: 'center',
        padding: 60,
        opacity,
      }}
    >
      {/* Company Name */}
      <div
        style={{
          opacity: headerOpacity,
          transform: `translateY(${headerSlide}px)`,
          textAlign: 'center',
          marginBottom: 20,
        }}
      >
        <h1
          style={{
            fontSize: 56,
            fontWeight: 'bold',
            color: '#111',
            margin: 0,
            fontFamily: 'system-ui, -apple-system, sans-serif',
          }}
        >
          {companyName}
        </h1>
      </div>

      {/* Address */}
      <div
        style={{
          opacity: contentOpacity,
          textAlign: 'center',
          marginBottom: 50,
        }}
      >
        <p
          style={{
            fontSize: 36,
            color: '#333',
            margin: 0,
            fontFamily: 'system-ui, -apple-system, sans-serif',
          }}
        >
          {address}, {city}
        </p>
      </div>

      {/* QR Code Placeholder */}
      <div
        style={{
          opacity: contentOpacity,
          transform: `scale(${Math.max(0, qrScale)})`,
          marginBottom: 40,
          display: 'flex',
          alignItems: 'center',
          gap: 30,
        }}
      >
        {/* Cute emoji */}
        <span style={{ fontSize: 48 }}>(・‿・)ノ</span>

        {/* QR Code or Placeholder */}
        <div
          style={{
            width: 180,
            height: 180,
            backgroundColor: '#f5f5f5',
            border: '2px solid #ddd',
            borderRadius: 12,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            flexDirection: 'column',
            gap: 8,
          }}
        >
          {qrCodeUrl ? (
            <img src={qrCodeUrl} alt="QR Code" style={{ width: 160, height: 160 }} />
          ) : (
            <>
              <svg
                width="80"
                height="80"
                viewBox="0 0 24 24"
                fill="none"
                stroke="#999"
                strokeWidth="1.5"
              >
                <rect x="3" y="3" width="7" height="7" />
                <rect x="14" y="3" width="7" height="7" />
                <rect x="3" y="14" width="7" height="7" />
                <rect x="14" y="14" width="4" height="4" />
                <rect x="19" y="14" width="2" height="2" />
                <rect x="14" y="19" width="2" height="2" />
                <rect x="19" y="19" width="2" height="2" />
              </svg>
              <span style={{ fontSize: 12, color: '#999' }}>QR Code</span>
            </>
          )}
        </div>
      </div>

      {/* CTA Text */}
      <div
        style={{
          opacity: ctaOpacity,
          textAlign: 'center',
        }}
      >
        <p
          style={{
            fontSize: 32,
            fontWeight: 600,
            color: '#111',
            margin: 0,
            fontFamily: 'system-ui, -apple-system, sans-serif',
          }}
        >
          {companyTagline}
        </p>
        <p
          style={{
            fontSize: 28,
            color: primaryColor,
            marginTop: 16,
            fontFamily: 'system-ui, -apple-system, sans-serif',
          }}
        >
          {companyPhone}
        </p>
      </div>

      {/* Footer branding */}
      <div
        style={{
          position: 'absolute',
          bottom: 30,
          opacity: ctaOpacity,
        }}
      >
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 12,
          }}
        >
          <div
            style={{
              width: 40,
              height: 40,
              borderRadius: '50%',
              backgroundColor: primaryColor,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              color: 'white',
              fontSize: 18,
              fontWeight: 'bold',
            }}
          >
            {companyName?.charAt(0) || 'A'}
          </div>
          <span
            style={{
              fontSize: 20,
              fontWeight: 600,
              color: '#666',
              fontFamily: 'system-ui, -apple-system, sans-serif',
            }}
          >
            {companyName}
          </span>
        </div>
      </div>
    </AbsoluteFill>
  );
};
