import React from 'react';
import { useCurrentFrame, interpolate } from 'remotion';

interface CountUpNumberProps {
  value: number;
  startFrame?: number;
  duration?: number;
  prefix?: string;
  suffix?: string;
  decimals?: number;
  style?: React.CSSProperties;
}

export const CountUpNumber: React.FC<CountUpNumberProps> = ({
  value,
  startFrame = 0,
  duration = 40,
  prefix = '',
  suffix = '',
  decimals = 0,
  style,
}) => {
  const frame = useCurrentFrame();

  const progress = interpolate(
    frame,
    [startFrame, startFrame + duration],
    [0, 1],
    { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' }
  );

  // Ease-out cubic for natural counting feel
  const eased = 1 - Math.pow(1 - progress, 3);
  const current = value * eased;

  const display = decimals > 0 ? current.toFixed(decimals) : Math.round(current).toLocaleString();

  return (
    <span style={style}>
      {prefix}{display}{suffix}
    </span>
  );
};
