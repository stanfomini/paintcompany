import React from 'react';
import { useCurrentFrame, useVideoConfig, interpolate } from 'remotion';

interface Waypoint {
  x: number;
  y: number;
  frame: number;
  click?: boolean;
}

interface AnimatedCursorProps {
  waypoints: Waypoint[];
  color?: string;
}

export const AnimatedCursor: React.FC<AnimatedCursorProps> = ({ waypoints, color = '#111' }) => {
  const frame = useCurrentFrame();

  if (waypoints.length === 0) {
    return null;
  }

  // Find current segment
  let x = waypoints[0].x;
  let y = waypoints[0].y;
  let clicking = false;

  for (let i = 0; i < waypoints.length - 1; i++) {
    const from = waypoints[i];
    const to = waypoints[i + 1];

    if (frame >= from.frame && frame <= to.frame) {
      x = interpolate(frame, [from.frame, to.frame], [from.x, to.x], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
      });
      y = interpolate(frame, [from.frame, to.frame], [from.y, to.y], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
      });
      break;
    } else if (frame > to.frame) {
      x = to.x;
      y = to.y;
    }
  }

  // Check if clicking at any waypoint
  for (const wp of waypoints) {
    if (wp.click && Math.abs(frame - wp.frame) < 5) {
      clicking = true;
      break;
    }
  }

  const cursorScale = clicking ? 0.85 : 1;

  // Only show after first waypoint frame
  const opacity = frame >= waypoints[0].frame ? 1 : 0;

  return (
    <div
      style={{
        position: 'absolute',
        left: x,
        top: y,
        opacity,
        transform: `scale(${cursorScale})`,
        zIndex: 1000,
        pointerEvents: 'none',
      }}
    >
      {/* Cursor SVG */}
      <svg width="28" height="34" viewBox="0 0 28 34" fill="none">
        <path
          d="M2 2L2 26L8.5 19.5L14 30L18 28L12.5 17.5L22 17.5L2 2Z"
          fill={color}
          stroke="white"
          strokeWidth="2"
          strokeLinejoin="round"
        />
      </svg>
      {/* Click ripple */}
      {clicking && (
        <div
          style={{
            position: 'absolute',
            top: 0,
            left: 0,
            width: 40,
            height: 40,
            borderRadius: '50%',
            border: `3px solid ${color}`,
            opacity: 0.5,
            transform: 'translate(-6px, -6px)',
          }}
        />
      )}
    </div>
  );
};
