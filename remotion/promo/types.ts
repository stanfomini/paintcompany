// Types for the Painting Made Easy Denver promo video

export interface PromoVideoProps {
  companyName: string;
  tagline: string;
  phone: string;
  website: string;
  city: string;
  state: string;
}

export const defaultPromoProps: PromoVideoProps = {
  companyName: 'Painting Made Easy',
  tagline: 'Denver',
  phone: '',
  website: 'paintingmadeeasydenver.com',
  city: 'Denver',
  state: 'CO',
};
