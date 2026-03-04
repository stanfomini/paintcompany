import React from 'react';
import { AbsoluteFill, Sequence, interpolate, useVideoConfig } from 'remotion';
import { SCENE_FRAMES, BRAND } from './brand';
import { CommsHubProps } from './types';
import { HeroScene } from './scenes/HeroScene';
import { TheProblemScene } from './scenes/TheProblemScene';
import { TheAnswerScene } from './scenes/TheAnswerScene';
import { UnifiedInboxScene } from './scenes/UnifiedInboxScene';
import { LiveChatScene } from './scenes/LiveChatScene';
import { EmailIntegrationScene } from './scenes/EmailIntegrationScene';
import { MessengerScene } from './scenes/MessengerScene';
import { SmartFeaturesScene } from './scenes/SmartFeaturesScene';
import { AllTogetherScene } from './scenes/AllTogetherScene';
import { CTAScene } from './scenes/CTAScene';

export const CommsHubVideo: React.FC<CommsHubProps> = (props) => {
  const { durationInFrames } = useVideoConfig();
  let offset = 0;

  const scenes: Array<{ key: string; frames: number; component: React.ReactNode }> = [
    { key: 'hero', frames: SCENE_FRAMES.hero, component: <HeroScene data={props} /> },
    { key: 'the-problem', frames: SCENE_FRAMES.theProblem, component: <TheProblemScene /> },
    { key: 'the-answer', frames: SCENE_FRAMES.theAnswer, component: <TheAnswerScene /> },
    { key: 'unified-inbox', frames: SCENE_FRAMES.unifiedInbox, component: <UnifiedInboxScene /> },
    { key: 'live-chat', frames: SCENE_FRAMES.liveChat, component: <LiveChatScene /> },
    { key: 'email-integration', frames: SCENE_FRAMES.emailIntegration, component: <EmailIntegrationScene /> },
    { key: 'messenger', frames: SCENE_FRAMES.messenger, component: <MessengerScene /> },
    { key: 'smart-features', frames: SCENE_FRAMES.smartFeatures, component: <SmartFeaturesScene /> },
    { key: 'all-together', frames: SCENE_FRAMES.allTogether, component: <AllTogetherScene /> },
    { key: 'cta', frames: SCENE_FRAMES.cta, component: <CTAScene data={props} /> },
  ];

  return (
    <AbsoluteFill style={{ backgroundColor: BRAND.white }}>
      {scenes.map((scene) => {
        const from = offset;
        offset += scene.frames;
        return (
          <Sequence key={scene.key} from={from} durationInFrames={scene.frames}>
            {scene.component}
          </Sequence>
        );
      })}
    </AbsoluteFill>
  );
};
