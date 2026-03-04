import React from 'react';

type Channel = 'chat' | 'email' | 'messenger' | 'deal';

const icons: Record<Channel, { svg: React.ReactNode; color: string; label: string }> = {
  chat: {
    color: '#6B7280',
    label: 'Chat',
    svg: (
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
      </svg>
    ),
  },
  email: {
    color: '#F59E0B',
    label: 'Email',
    svg: (
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <rect x="2" y="4" width="20" height="16" rx="2" />
        <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" />
      </svg>
    ),
  },
  messenger: {
    color: '#2563EB',
    label: 'Messenger',
    svg: (
      <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
        <path d="M12 2C6.36 2 2 6.13 2 11.7c0 2.91 1.2 5.42 3.15 7.2.16.15.26.36.27.58l.06 1.82c.02.58.63.96 1.16.72l2.03-.9c.17-.08.37-.1.55-.06.9.25 1.85.38 2.78.38 5.64 0 10-4.13 10-9.7S17.64 2 12 2zm6 7.68l-2.93 4.65c-.47.74-1.44.93-2.13.41l-2.33-1.75a.6.6 0 0 0-.72 0L7 15.11c-.39.3-.9-.15-.64-.57l2.93-4.65c.47-.74 1.44-.93 2.13-.41l2.33 1.75a.6.6 0 0 0 .72 0L17.36 9c.39-.3.9.15.64.57z" />
      </svg>
    ),
  },
  deal: {
    color: '#8B5CF6',
    label: 'Deals',
    svg: (
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
      </svg>
    ),
  },
};

interface ChannelIconProps {
  channel: Channel;
  size?: number;
}

export const ChannelIcon: React.FC<ChannelIconProps> = ({ channel, size = 40 }) => {
  const { svg, color } = icons[channel];
  return (
    <div
      style={{
        width: size,
        height: size,
        borderRadius: size * 0.3,
        backgroundColor: `${color}15`,
        border: `2px solid ${color}30`,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        color,
        flexShrink: 0,
      }}
    >
      {svg}
    </div>
  );
};

export const getChannelColor = (channel: Channel): string => icons[channel].color;
export const getChannelLabel = (channel: Channel): string => icons[channel].label;
