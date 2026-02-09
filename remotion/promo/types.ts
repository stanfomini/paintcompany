// Types for the Apex Negotiations promo video

export interface PromoVideoProps {
  companyName: string;
  tagline: string;
  phone: string;
  website: string;
  city: string;
  state: string;
}

export const defaultPromoProps: PromoVideoProps = {
  companyName: 'Painting Made Easy Denver',
  tagline: 'Your Home, Our Craft',
  phone: '(720) 555-0123',
  website: 'paintingmadeeasydenver.com',
  city: 'Denver',
  state: 'CO',
};
