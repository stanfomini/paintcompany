import React from 'react';
import { useCurrentFrame, interpolate } from 'remotion';

interface Particle {
  x: number;
  y: number;
  size: number;
  speed: number;
  opacity: number;
  delay: number;
}

interface FloatingParticlesProps {
  count?: number;
  color?: string;
  seed?: number;
}

// Deterministic pseudo-random based on seed
const seededRandom = (seed: number): number => {
  const x = Math.sin(seed * 9301 + 49297) * 233280;
  return x - Math.floor(x);
};

export const FloatingParticles: React.FC<FloatingParticlesProps> = ({
  count = 20,
  color = 'rgba(56, 189, 248, 0.15)',
  seed = 42,
}) => {
  const frame = useCurrentFrame();

  const particles: Particle[] = [];
  for (let i = 0; i < count; i++) {
    particles.push({
      x: seededRandom(seed + i * 7) * 100,
      y: seededRandom(seed + i * 13) * 100,
      size: 4 + seededRandom(seed + i * 19) * 12,
      speed: 0.3 + seededRandom(seed + i * 23) * 0.7,
      opacity: 0.3 + seededRandom(seed + i * 29) * 0.5,
      delay: seededRandom(seed + i * 31) * 60,
    });
  }

  return (
    <>
      {particles.map((p, i) => {
        const yOffset = interpolate(
          frame,
          [p.delay, p.delay + 300],
          [0, -80 * p.speed],
          { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' }
        );
        const xWobble = Math.sin((frame + p.delay) * 0.03) * 15;
        const particleOpacity = interpolate(
          frame,
          [p.delay, p.delay + 20],
          [0, p.opacity],
          { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' }
        );

        return (
          <div
            key={i}
            style={{
              position: 'absolute',
              left: `${p.x}%`,
              top: `${p.y}%`,
              width: p.size,
              height: p.size,
              borderRadius: '50%',
              backgroundColor: color,
              opacity: particleOpacity,
              transform: `translate(${xWobble}px, ${yOffset}px)`,
              pointerEvents: 'none',
            }}
          />
        );
      })}
    </>
  );
};
