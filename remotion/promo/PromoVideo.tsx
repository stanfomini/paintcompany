import React from 'react';
import { AbsoluteFill, Sequence } from 'remotion';
import { SCENE_FRAMES, BRAND } from './brand';
import { PromoVideoProps } from './types';
import { BrandIntroScene } from './scenes/BrandIntroScene';
import { ProposalMockupScene } from './scenes/ProposalMockupScene';
import { RoomPickerScene } from './scenes/RoomPickerScene';
import { CommunicationScene } from './scenes/CommunicationScene';
import { PhotoCollaborationScene } from './scenes/PhotoCollaborationScene';
import { JobProgressScene } from './scenes/JobProgressScene';
import { ProfessionalTeamScene } from './scenes/ProfessionalTeamScene';
import { CTAScene } from './scenes/CTAScene';

export const PromoVideo: React.FC<PromoVideoProps> = (props) => {
  let offset = 0;

  const scenes: Array<{ key: string; frames: number; component: React.ReactNode }> = [
    { key: 'brand-intro', frames: SCENE_FRAMES.brandIntro, component: <BrandIntroScene data={props} /> },
    { key: 'proposal-mockup', frames: SCENE_FRAMES.proposalMockup, component: <ProposalMockupScene data={props} /> },
    { key: 'room-picker', frames: SCENE_FRAMES.roomPicker, component: <RoomPickerScene data={props} /> },
    { key: 'communication', frames: SCENE_FRAMES.communication, component: <CommunicationScene data={props} /> },
    { key: 'photo-collaboration', frames: SCENE_FRAMES.photoCollaboration, component: <PhotoCollaborationScene data={props} /> },
    { key: 'job-progress', frames: SCENE_FRAMES.jobProgress, component: <JobProgressScene data={props} /> },
    { key: 'professional-team', frames: SCENE_FRAMES.professionalTeam, component: <ProfessionalTeamScene data={props} /> },
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
