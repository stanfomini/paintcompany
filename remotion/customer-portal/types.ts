export interface CustomerPortalProps {
  companyName: string;
  website: string;
  city: string;
  customerName: string;
  address: string;
}

export const defaultCustomerPortalProps: CustomerPortalProps = {
  companyName: 'Painting Made Easy',
  website: 'paintingmadeeasydenver.com',
  city: 'Denver',
  customerName: 'Sarah',
  address: '123 Maple Street',
};
