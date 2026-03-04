import React from 'react';
import { AbsoluteFill, Sequence } from 'remotion';
import { SCENE_FRAMES, BRAND } from './brand';
import { CustomerPortalProps } from './types';
import { HeroScene } from './scenes/HeroScene';
import { DashboardScene } from './scenes/DashboardScene';
import { EstimateViewerScene } from './scenes/EstimateViewerScene';
import { EstimateBuilderScene } from './scenes/EstimateBuilderScene';
import { JobProgressScene } from './scenes/JobProgressScene';
import { CTAScene } from './scenes/CTAScene';

export const CustomerPortalVideo: React.FC<CustomerPortalProps> = (props) => {
  let offset = 0;

  const scenes: Array<{ key: string; frames: number; component: React.ReactNode }> = [
    { key: 'hero', frames: SCENE_FRAMES.hero, component: <HeroScene data={props} /> },
    { key: 'dashboard', frames: SCENE_FRAMES.dashboard, component: <DashboardScene data={props} /> },
    { key: 'estimate-viewer', frames: SCENE_FRAMES.estimateViewer, component: <EstimateViewerScene data={props} /> },
    { key: 'estimate-builder', frames: SCENE_FRAMES.estimateBuilder, component: <EstimateBuilderScene data={props} /> },
    { key: 'job-progress', frames: SCENE_FRAMES.jobProgress, component: <JobProgressScene data={props} /> },
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
