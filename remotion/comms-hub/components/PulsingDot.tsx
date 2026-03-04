import React from 'react';
import { useCurrentFrame, interpolate } from 'remotion';

interface PulsingDotProps {
  color: string;
  size?: number;
  speed?: number;
}

export const PulsingDot: React.FC<PulsingDotProps> = ({ color, size = 10, speed = 0.12 }) => {
  const frame = useCurrentFrame();
  const pulse = 1 + Math.sin(frame * speed) * 0.3;
  const outerOpacity = interpolate(Math.sin(frame * speed), [-1, 1], [0.2, 0.5]);

  return (
    <div style={{ position: 'relative', width: size * 2.5, height: size * 2.5, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
      <div
        style={{
          position: 'absolute',
          width: size * 2.5 * pulse,
          height: size * 2.5 * pulse,
          borderRadius: '50%',
          backgroundColor: color,
          opacity: outerOpacity,
        }}
      />
      <div
        style={{
          width: size,
          height: size,
          borderRadius: '50%',
          backgroundColor: color,
          position: 'relative',
          zIndex: 1,
        }}
      />
    </div>
  );
};
