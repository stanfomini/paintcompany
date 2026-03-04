import React from 'react';
import { BRAND } from '../brand';

interface MockupFrameProps {
  type: 'browser' | 'phone';
  children: React.ReactNode;
  url?: string;
  style?: React.CSSProperties;
}

export const MockupFrame: React.FC<MockupFrameProps> = ({ type, children, url, style }) => {
  if (type === 'browser') {
    return (
      <div
        style={{
          width: '100%',
          height: '100%',
          borderRadius: 16,
          overflow: 'hidden',
          boxShadow: '0 25px 80px rgba(0,0,0,0.25)',
          display: 'flex',
          flexDirection: 'column',
          backgroundColor: BRAND.white,
          ...style,
        }}
      >
        <div
          style={{
            height: 48,
            backgroundColor: '#E5E7EB',
            display: 'flex',
            alignItems: 'center',
            padding: '0 16px',
            gap: 8,
            flexShrink: 0,
          }}
        >
          <div style={{ display: 'flex', gap: 7 }}>
            <div style={{ width: 13, height: 13, borderRadius: '50%', backgroundColor: '#EF4444' }} />
            <div style={{ width: 13, height: 13, borderRadius: '50%', backgroundColor: '#F59E0B' }} />
            <div style={{ width: 13, height: 13, borderRadius: '50%', backgroundColor: '#10B981' }} />
          </div>
          {url && (
            <div
              style={{
                flex: 1,
                marginLeft: 16,
                backgroundColor: BRAND.white,
                borderRadius: 6,
                padding: '6px 14px',
                fontSize: 14,
                color: BRAND.gray,
                fontFamily: 'system-ui, sans-serif',
              }}
            >
              {url}
            </div>
          )}
        </div>
        <div style={{ flex: 1, overflow: 'hidden', position: 'relative' }}>{children}</div>
      </div>
    );
  }

  return (
    <div
      style={{
        width: 320,
        height: 640,
        borderRadius: 40,
        overflow: 'hidden',
        boxShadow: '0 25px 80px rgba(0,0,0,0.3)',
        border: '6px solid #1F2937',
        display: 'flex',
        flexDirection: 'column',
        backgroundColor: BRAND.white,
        position: 'relative',
        ...style,
      }}
    >
      <div
        style={{
          position: 'absolute',
          top: 0,
          left: '50%',
          transform: 'translateX(-50%)',
          width: 120,
          height: 28,
          backgroundColor: '#1F2937',
          borderRadius: '0 0 16px 16px',
          zIndex: 10,
        }}
      />
      <div style={{ flex: 1, overflow: 'hidden', position: 'relative', marginTop: 28 }}>
        {children}
      </div>
      <div style={{ height: 30, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
        <div style={{ width: 100, height: 5, backgroundColor: '#D1D5DB', borderRadius: 3 }} />
      </div>
    </div>
  );
};
