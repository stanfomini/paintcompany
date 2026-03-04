import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, TEXT, SPRING_CONFIGS } from '../brand';
import { MockupFrame } from '../components/MockupFrame';
import { FloatingParticles } from '../components/FloatingParticles';

interface EmailThread {
  subject: string;
  from: string;
  preview: string;
  time: string;
  hasAttachment: boolean;
  isRead: boolean;
  delay: number;
}

const emails: EmailThread[] = [
  { subject: 'Estimate #1045 - Kitchen Repaint', from: 'sarah@gmail.com', preview: 'I accept the estimate! When can we start?', time: '2m ago', hasAttachment: false, isRead: false, delay: 35 },
  { subject: 'Re: Color Selection for Master Bedroom', from: 'john.d@outlook.com', preview: 'We decided to go with Sherwin-Williams Agreeable Gray...', time: '15m ago', hasAttachment: true, isRead: false, delay: 50 },
  { subject: 'Invoice Payment Confirmation', from: 'accounting@referralpartner.com', preview: 'Payment of $4,250 has been processed for...', time: '1h ago', hasAttachment: true, isRead: true, delay: 65 },
];

export const EmailIntegrationScene: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  const titleOpacity = interpolate(frame, [0, 20], [0, 1], { extrapolateRight: 'clamp' });
  const titleSlide = interpolate(frame, [0, 20], [-30, 0], { extrapolateRight: 'clamp' });

  const mockupScale = spring({ frame: frame - 15, fps, config: SPRING_CONFIGS.gentle });

  // Email thread expansion
  const expandFrame = 110;
  const expandProgress = interpolate(frame, [expandFrame, expandFrame + 25], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });

  // Reply composer slides up
  const replyFrame = 150;
  const replyOpacity = interpolate(frame, [replyFrame, replyFrame + 15], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });
  const replySlide = interpolate(frame, [replyFrame, replyFrame + 15], [30, 0], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });

  const fadeOut = interpolate(frame, [durationInFrames - 15, durationInFrames], [1, 0], { extrapolateLeft: 'clamp' });

  return (
    <AbsoluteFill style={{ backgroundColor: BRAND.white, opacity: fadeOut }}>
      <FloatingParticles count={10} color={`${BRAND.amber}10`} seed={33} />

      {/* Title */}
      <div
        style={{
          position: 'absolute',
          top: 35,
          left: 0,
          right: 0,
          textAlign: 'center',
          opacity: titleOpacity,
          transform: `translateY(${titleSlide}px)`,
        }}
      >
        <h2 style={{ fontSize: TEXT.heading - 8, fontWeight: 800, color: BRAND.navy, margin: 0, fontFamily: FONT.system }}>
          Full Email Client
        </h2>
        <p style={{ fontSize: TEXT.label, color: BRAND.gray, marginTop: 6, fontFamily: FONT.system }}>
          Send, reply, and manage threads without leaving the hub
        </p>
      </div>

      {/* Browser mockup */}
      <div
        style={{
          position: 'absolute',
          top: 135,
          left: 80,
          right: 80,
          bottom: 50,
          transform: `scale(${Math.max(0.01, mockupScale)})`,
          transformOrigin: 'center top',
        }}
      >
        <MockupFrame type="browser" url="paintingmadeeasydenver.com/communications">
          <div style={{ display: 'flex', height: '100%' }}>
            {/* Email list or expanded thread */}
            <div style={{ flex: 1, display: 'flex', flexDirection: 'column' }}>
              {/* Tabs */}
              <div style={{ display: 'flex', alignItems: 'center', padding: '12px 20px', borderBottom: '1px solid #E5E7EB', gap: 16 }}>
                <span style={{ fontSize: 14, fontWeight: 700, color: BRAND.amber, fontFamily: FONT.system, borderBottom: `2px solid ${BRAND.amber}`, paddingBottom: 8 }}>
                  Emails
                </span>
                <span style={{ fontSize: 14, fontWeight: 500, color: BRAND.gray, fontFamily: FONT.system, paddingBottom: 8 }}>
                  Direct
                </span>
                <span style={{ fontSize: 14, fontWeight: 500, color: BRAND.gray, fontFamily: FONT.system, paddingBottom: 8 }}>
                  Deals
                </span>

                {/* Compose button */}
                <div
                  style={{
                    marginLeft: 'auto',
                    display: 'flex',
                    alignItems: 'center',
                    gap: 6,
                    padding: '6px 14px',
                    backgroundColor: BRAND.blue,
                    borderRadius: 8,
                  }}
                >
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke={BRAND.white} strokeWidth="2.5">
                    <path d="M12 5v14M5 12h14" />
                  </svg>
                  <span style={{ fontSize: 13, fontWeight: 600, color: BRAND.white, fontFamily: FONT.system }}>Compose</span>
                </div>
              </div>

              {expandProgress < 0.5 ? (
                // Email list view
                <div style={{ flex: 1, overflow: 'hidden' }}>
                  {emails.map((email, i) => {
                    const emailScale = spring({ frame: frame - email.delay, fps, config: SPRING_CONFIGS.snappy });
                    const emailOpacity = interpolate(frame, [email.delay, email.delay + 10], [0, 1], {
                      extrapolateLeft: 'clamp',
                      extrapolateRight: 'clamp',
                    });

                    return (
                      <div
                        key={email.subject}
                        style={{
                          display: 'flex',
                          alignItems: 'flex-start',
                          gap: 14,
                          padding: '16px 20px',
                          borderBottom: '1px solid #F3F4F6',
                          backgroundColor: !email.isRead ? `${BRAND.amber}06` : 'transparent',
                          borderLeft: !email.isRead ? `3px solid ${BRAND.amber}` : '3px solid transparent',
                          opacity: emailOpacity,
                          transform: `translateX(${(1 - Math.max(0, emailScale)) * 20}px)`,
                        }}
                      >
                        {/* Email icon */}
                        <div
                          style={{
                            width: 40,
                            height: 40,
                            borderRadius: 10,
                            backgroundColor: `${BRAND.amber}15`,
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            flexShrink: 0,
                            marginTop: 2,
                          }}
                        >
                          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke={BRAND.amber} strokeWidth="2" strokeLinecap="round">
                            <rect x="2" y="4" width="20" height="16" rx="2" />
                            <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" />
                          </svg>
                        </div>
                        <div style={{ flex: 1, minWidth: 0 }}>
                          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                            <span style={{ fontSize: 15, fontWeight: email.isRead ? 500 : 700, color: BRAND.dark, fontFamily: FONT.system }}>
                              {email.subject}
                            </span>
                            {email.hasAttachment && (
                              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke={BRAND.gray} strokeWidth="2" strokeLinecap="round">
                                <path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48" />
                              </svg>
                            )}
                          </div>
                          <div style={{ fontSize: 12, color: BRAND.gray, fontFamily: FONT.system, marginTop: 2 }}>
                            {email.from}
                          </div>
                          <p style={{ fontSize: 13, color: BRAND.slate, margin: '4px 0 0', fontFamily: FONT.system, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                            {email.preview}
                          </p>
                        </div>
                        <span style={{ fontSize: 11, color: BRAND.gray, fontFamily: FONT.system, flexShrink: 0, marginTop: 2 }}>
                          {email.time}
                        </span>
                      </div>
                    );
                  })}
                </div>
              ) : (
                // Expanded email thread view
                <div style={{ flex: 1, padding: 20, display: 'flex', flexDirection: 'column', gap: 16, opacity: interpolate(expandProgress, [0.5, 1], [0, 1]) }}>
                  <div style={{ fontSize: 20, fontWeight: 700, color: BRAND.dark, fontFamily: FONT.system }}>
                    Estimate #1045 - Kitchen Repaint
                  </div>

                  {/* Email in iframe mockup */}
                  <div style={{ backgroundColor: BRAND.lightGray, borderRadius: 12, padding: 20, border: '1px solid #E5E7EB' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 12 }}>
                      <div style={{ width: 36, height: 36, borderRadius: '50%', backgroundColor: `${BRAND.green}20`, display: 'flex', alignItems: 'center', justifyContent: 'center', color: BRAND.green, fontWeight: 700, fontSize: 14, fontFamily: FONT.system }}>
                        SM
                      </div>
                      <div>
                        <div style={{ fontSize: 14, fontWeight: 700, color: BRAND.dark, fontFamily: FONT.system }}>Sarah Martinez</div>
                        <div style={{ fontSize: 11, color: BRAND.gray, fontFamily: FONT.system }}>sarah@gmail.com</div>
                      </div>
                      <div style={{ marginLeft: 'auto', backgroundColor: `${BRAND.green}15`, padding: '3px 10px', borderRadius: 6 }}>
                        <span style={{ fontSize: 11, fontWeight: 600, color: BRAND.green, fontFamily: FONT.system }}>Inbound</span>
                      </div>
                    </div>
                    <div style={{ fontSize: 15, color: BRAND.dark, fontFamily: FONT.system, lineHeight: 1.6 }}>
                      Hi! I accept the estimate for the kitchen repaint. The colors look perfect. When can your crew start? We're flexible on dates.
                    </div>
                  </div>

                  {/* CRM auto-link badge */}
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 14px', backgroundColor: `${BRAND.purple}10`, borderRadius: 10, border: `1px solid ${BRAND.purple}25`, alignSelf: 'flex-start' }}>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke={BRAND.purple} strokeWidth="2" strokeLinecap="round">
                      <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
                      <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
                    </svg>
                    <span style={{ fontSize: 13, fontWeight: 600, color: BRAND.purple, fontFamily: FONT.system }}>
                      Auto-linked to Deal #1045
                    </span>
                  </div>

                  {/* Reply composer */}
                  <div
                    style={{
                      marginTop: 'auto',
                      opacity: replyOpacity,
                      transform: `translateY(${replySlide}px)`,
                      backgroundColor: BRAND.white,
                      borderRadius: 12,
                      border: `1px solid ${BRAND.blue}30`,
                      padding: 16,
                    }}
                  >
                    <div style={{ fontSize: 13, color: BRAND.gray, fontFamily: FONT.system, marginBottom: 8 }}>
                      Reply to sarah@gmail.com
                    </div>
                    <div style={{ fontSize: 15, color: BRAND.dark, fontFamily: FONT.system, lineHeight: 1.5 }}>
                      Great news, Sarah! We can start next Tuesday. I'll have the crew there at 8am. The kitchen should take about 2 days...
                    </div>
                    <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 12 }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 20px', backgroundColor: BRAND.blue, borderRadius: 8 }}>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke={BRAND.white} strokeWidth="2.5" strokeLinecap="round">
                          <path d="m22 2-7 20-4-9-9-4z" />
                        </svg>
                        <span style={{ fontSize: 14, fontWeight: 600, color: BRAND.white, fontFamily: FONT.system }}>Send</span>
                      </div>
                    </div>
                  </div>
                </div>
              )}
            </div>
          </div>
        </MockupFrame>
      </div>
    </AbsoluteFill>
  );
};
