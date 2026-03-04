import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, TEXT, SPRING_CONFIGS } from '../brand';
import { MockupFrame } from '../components/MockupFrame';
import { PulsingDot } from '../components/PulsingDot';
import { FloatingParticles } from '../components/FloatingParticles';

interface Contact {
  initials: string;
  name: string;
  preview: string;
  type: 'Customer' | 'Agent' | 'Crew';
  typeColor: string;
  unread: number;
  delay: number;
}

const contacts: Contact[] = [
  { initials: 'SM', name: 'Sarah Martinez', preview: 'When can you start the kitchen?', type: 'Customer', typeColor: BRAND.green, unread: 3, delay: 30 },
  { initials: 'JD', name: 'John Davis', preview: 'The crew finished the trim today', type: 'Crew', typeColor: BRAND.purple, unread: 0, delay: 42 },
  { initials: 'LW', name: 'Lisa Wong', preview: 'I have a referral for you!', type: 'Agent', typeColor: BRAND.amber, unread: 1, delay: 54 },
  { initials: 'MR', name: 'Mike Rodriguez', preview: 'Colors confirmed - see attached', type: 'Customer', typeColor: BRAND.green, unread: 2, delay: 66 },
  { initials: 'AP', name: 'Amy Park', preview: 'Invoice #4521 is approved', type: 'Agent', typeColor: BRAND.amber, unread: 0, delay: 78 },
];

export const UnifiedInboxScene: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  const titleOpacity = interpolate(frame, [0, 20], [0, 1], { extrapolateRight: 'clamp' });
  const titleSlide = interpolate(frame, [0, 20], [-30, 0], { extrapolateRight: 'clamp' });

  const mockupScale = spring({ frame: frame - 15, fps, config: SPRING_CONFIGS.gentle });

  // Highlight selected contact
  const selectedIdx = Math.min(Math.floor(interpolate(frame, [100, 180], [0, 2.99], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' })), 2);

  // Right panel content
  const rightPanelOpacity = interpolate(frame, [100, 120], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });

  const fadeOut = interpolate(frame, [durationInFrames - 15, durationInFrames], [1, 0], { extrapolateLeft: 'clamp' });

  return (
    <AbsoluteFill style={{ backgroundColor: BRAND.white, opacity: fadeOut }}>
      <FloatingParticles count={12} color={`${BRAND.blue}08`} seed={55} />

      {/* Title */}
      <div
        style={{
          position: 'absolute',
          top: 30,
          left: 0,
          right: 0,
          textAlign: 'center',
          opacity: titleOpacity,
          transform: `translateY(${titleSlide}px)`,
        }}
      >
        <h2 style={{ fontSize: TEXT.heading - 8, fontWeight: 800, color: BRAND.navy, margin: 0, fontFamily: FONT.system }}>
          Gmail-Style Inbox
        </h2>
        <p style={{ fontSize: TEXT.label, color: BRAND.gray, marginTop: 6, fontFamily: FONT.system }}>
          Every person you've communicated with, sorted by latest activity
        </p>
      </div>

      {/* Browser mockup */}
      <div
        style={{
          position: 'absolute',
          top: 140,
          left: 80,
          right: 80,
          bottom: 50,
          transform: `scale(${Math.max(0.01, mockupScale)})`,
          transformOrigin: 'center top',
        }}
      >
        <MockupFrame type="browser" url="paintingmadeeasydenver.com/communications">
          <div style={{ display: 'flex', height: '100%' }}>
            {/* Left sidebar - contact list */}
            <div
              style={{
                width: 380,
                borderRight: `1px solid #E5E7EB`,
                display: 'flex',
                flexDirection: 'column',
                flexShrink: 0,
              }}
            >
              {/* Search bar */}
              <div style={{ padding: '12px 16px', borderBottom: '1px solid #E5E7EB' }}>
                <div
                  style={{
                    backgroundColor: BRAND.lightGray,
                    borderRadius: 8,
                    padding: '8px 14px',
                    fontSize: 14,
                    color: BRAND.gray,
                    fontFamily: FONT.system,
                  }}
                >
                  Search conversations...
                </div>
              </div>

              {/* Filter tabs */}
              <div style={{ display: 'flex', gap: 0, borderBottom: '1px solid #E5E7EB' }}>
                {['All', 'Customers', 'Agents', 'Crew'].map((tab, i) => (
                  <div
                    key={tab}
                    style={{
                      flex: 1,
                      padding: '8px 0',
                      textAlign: 'center',
                      fontSize: 12,
                      fontWeight: i === 0 ? 700 : 500,
                      color: i === 0 ? BRAND.blue : BRAND.gray,
                      borderBottom: i === 0 ? `2px solid ${BRAND.blue}` : 'none',
                      fontFamily: FONT.system,
                    }}
                  >
                    {tab}
                  </div>
                ))}
              </div>

              {/* Contact list */}
              <div style={{ flex: 1, overflow: 'hidden' }}>
                {contacts.map((contact, i) => {
                  const contactScale = spring({ frame: frame - contact.delay, fps, config: SPRING_CONFIGS.snappy });
                  const contactOpacity = interpolate(frame, [contact.delay, contact.delay + 10], [0, 1], {
                    extrapolateLeft: 'clamp',
                    extrapolateRight: 'clamp',
                  });
                  const isSelected = i === selectedIdx && frame >= 100;

                  return (
                    <div
                      key={contact.name}
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 12,
                        padding: '14px 16px',
                        backgroundColor: isSelected ? `${BRAND.blue}10` : 'transparent',
                        borderLeft: isSelected ? `3px solid ${BRAND.blue}` : '3px solid transparent',
                        opacity: contactOpacity,
                        transform: `translateX(${(1 - Math.max(0, contactScale)) * 20}px)`,
                      }}
                    >
                      {/* Avatar */}
                      <div
                        style={{
                          width: 44,
                          height: 44,
                          borderRadius: '50%',
                          backgroundColor: `${contact.typeColor}20`,
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'center',
                          color: contact.typeColor,
                          fontWeight: 700,
                          fontSize: 15,
                          fontFamily: FONT.system,
                          flexShrink: 0,
                        }}
                      >
                        {contact.initials}
                      </div>
                      <div style={{ flex: 1, minWidth: 0 }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                          <span style={{ fontSize: 14, fontWeight: contact.unread > 0 ? 700 : 500, color: BRAND.dark, fontFamily: FONT.system }}>
                            {contact.name}
                          </span>
                          <span
                            style={{
                              fontSize: 10,
                              fontWeight: 600,
                              color: contact.typeColor,
                              backgroundColor: `${contact.typeColor}15`,
                              padding: '2px 6px',
                              borderRadius: 4,
                              fontFamily: FONT.system,
                            }}
                          >
                            {contact.type}
                          </span>
                        </div>
                        <p
                          style={{
                            fontSize: 12,
                            color: BRAND.gray,
                            margin: '3px 0 0',
                            fontFamily: FONT.system,
                            overflow: 'hidden',
                            textOverflow: 'ellipsis',
                            whiteSpace: 'nowrap',
                          }}
                        >
                          {contact.preview}
                        </p>
                      </div>
                      {contact.unread > 0 && (
                        <div
                          style={{
                            width: 22,
                            height: 22,
                            borderRadius: '50%',
                            backgroundColor: BRAND.blue,
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            fontSize: 11,
                            fontWeight: 700,
                            color: BRAND.white,
                            fontFamily: FONT.system,
                            flexShrink: 0,
                          }}
                        >
                          {contact.unread}
                        </div>
                      )}
                    </div>
                  );
                })}
              </div>
            </div>

            {/* Right panel - conversation view */}
            <div style={{ flex: 1, opacity: rightPanelOpacity, display: 'flex', flexDirection: 'column' }}>
              {/* Header */}
              <div
                style={{
                  padding: '14px 20px',
                  borderBottom: '1px solid #E5E7EB',
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
                    backgroundColor: `${BRAND.green}20`,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    color: BRAND.green,
                    fontWeight: 700,
                    fontSize: 14,
                    fontFamily: FONT.system,
                  }}
                >
                  SM
                </div>
                <div>
                  <div style={{ fontSize: 16, fontWeight: 700, color: BRAND.dark, fontFamily: FONT.system }}>
                    Sarah Martinez
                  </div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 5, marginTop: 1 }}>
                    <PulsingDot color={BRAND.green} size={6} />
                    <span style={{ fontSize: 11, color: BRAND.green, fontFamily: FONT.system }}>Online</span>
                  </div>
                </div>
                {/* Tabs */}
                <div style={{ marginLeft: 'auto', display: 'flex', gap: 4 }}>
                  {['All', 'Messenger', 'Deals', 'Emails'].map((tab, i) => (
                    <div
                      key={tab}
                      style={{
                        padding: '5px 12px',
                        borderRadius: 6,
                        fontSize: 11,
                        fontWeight: i === 0 ? 700 : 500,
                        backgroundColor: i === 0 ? `${BRAND.blue}15` : 'transparent',
                        color: i === 0 ? BRAND.blue : BRAND.gray,
                        fontFamily: FONT.system,
                      }}
                    >
                      {tab}
                    </div>
                  ))}
                </div>
              </div>

              {/* Messages */}
              <div style={{ flex: 1, padding: 20, display: 'flex', flexDirection: 'column', gap: 12, justifyContent: 'flex-end' }}>
                {[
                  { text: 'Hi! When can you start the kitchen repaint?', sender: 'them', channel: 'messenger', channelColor: BRAND.blue },
                  { text: 'We can start next Tuesday. I\'ll send the color samples tonight.', sender: 'us', channel: 'chat', channelColor: BRAND.gray },
                  { text: 'Perfect! I\'ll be home all day.', sender: 'them', channel: 'messenger', channelColor: BRAND.blue },
                ].map((msg, i) => {
                  const msgDelay = 120 + i * 20;
                  const msgOpacity = interpolate(frame, [msgDelay, msgDelay + 12], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });
                  const msgSlide = interpolate(frame, [msgDelay, msgDelay + 12], [15, 0], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });
                  const isUs = msg.sender === 'us';

                  return (
                    <div key={i} style={{ display: 'flex', justifyContent: isUs ? 'flex-end' : 'flex-start', opacity: msgOpacity, transform: `translateY(${msgSlide}px)` }}>
                      <div>
                        <div
                          style={{
                            maxWidth: 380,
                            padding: '10px 16px',
                            borderRadius: isUs ? '14px 4px 14px 14px' : '4px 14px 14px 14px',
                            backgroundColor: isUs ? BRAND.blue : BRAND.lightGray,
                            color: isUs ? BRAND.white : BRAND.dark,
                            fontSize: 14,
                            fontFamily: FONT.system,
                            lineHeight: 1.4,
                          }}
                        >
                          {msg.text}
                        </div>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 4, marginTop: 3, justifyContent: isUs ? 'flex-end' : 'flex-start' }}>
                          <div style={{ width: 6, height: 6, borderRadius: '50%', backgroundColor: msg.channelColor }} />
                          <span style={{ fontSize: 10, color: BRAND.gray, fontFamily: FONT.system }}>via {msg.channel}</span>
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          </div>
        </MockupFrame>
      </div>
    </AbsoluteFill>
  );
};
