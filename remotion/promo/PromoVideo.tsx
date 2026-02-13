import React from 'react';
import { AbsoluteFill, Audio, Sequence, interpolate, staticFile, useVideoConfig } from 'remotion';
import { SCENE_FRAMES, BRAND, FPS } from './brand';
import { PromoVideoProps } from './types';
import { PainPointsScene } from './scenes/PainPointsScene';
import { SolutionRevealScene } from './scenes/SolutionRevealScene';
import { ProcessOverviewScene } from './scenes/ProcessOverviewScene';
import { EstimateExperienceScene } from './scenes/EstimateExperienceScene';
import { JobProgressScene } from './scenes/JobProgressScene';
import { CrewDispatchScene } from './scenes/CrewDispatchScene';
import { StayInControlScene } from './scenes/StayInControlScene';
import { CTAScene } from './scenes/CTAScene';

export const PromoVideo: React.FC<PromoVideoProps> = (props) => {
  const { fps, durationInFrames } = useVideoConfig();
  let offset = 0;

  const scenes: Array<{ key: string; frames: number; component: React.ReactNode }> = [
    { key: 'pain-points', frames: SCENE_FRAMES.painPoints, component: <PainPointsScene data={props} /> },
    { key: 'solution-reveal', frames: SCENE_FRAMES.solutionReveal, component: <SolutionRevealScene data={props} /> },
    { key: 'process-overview', frames: SCENE_FRAMES.processOverview, component: <ProcessOverviewScene data={props} /> },
    { key: 'estimate-experience', frames: SCENE_FRAMES.estimateExperience, component: <EstimateExperienceScene data={props} /> },
    { key: 'job-progress', frames: SCENE_FRAMES.jobProgress, component: <JobProgressScene data={props} /> },
    { key: 'crew-dispatch', frames: SCENE_FRAMES.crewDispatch, component: <CrewDispatchScene data={props} /> },
    { key: 'stay-in-control', frames: SCENE_FRAMES.stayInControl, component: <StayInControlScene data={props} /> },
    { key: 'cta', frames: SCENE_FRAMES.cta, component: <CTAScene data={props} /> },
  ];

  return (
    <AbsoluteFill style={{ backgroundColor: BRAND.white }}>
      {/* Background music */}
      <Audio
        src={staticFile('promo-music.mp3')}
        volume={(f: number) => {
          const fadeInEnd = fps;
          const fadeOutStart = durationInFrames - 2 * fps;
          if (f < fadeInEnd) {
            return interpolate(f, [0, fadeInEnd], [0, 0.4], {
              extrapolateLeft: 'clamp',
              extrapolateRight: 'clamp',
            });
          }
          if (f > fadeOutStart) {
            return interpolate(f, [fadeOutStart, durationInFrames], [0.4, 0], {
              extrapolateLeft: 'clamp',
              extrapolateRight: 'clamp',
            });
          }
          return 0.4;
        }}
      />

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
