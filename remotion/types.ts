// Types for the Property Showcase video

export interface Photo {
  url: string;
  caption?: string;
  category: 'before' | 'after' | 'general';
}

export interface EstimateItem {
  description: string;
  quantity?: number;
  unit?: string;
  unitPrice?: number;
  total: number;
}

export interface Estimate {
  items: EstimateItem[];
  subtotal: number;
  total: number;
  squareFootage?: number;
  pricePerSqft?: number;
}

export interface PropertyShowcaseProps {
  // Property/Listing info
  type: 'property' | 'listing';
  id: number;
  address: string;
  city: string;
  state: string;
  zip: string;
  
  // Photos
  photos: Photo[];
  
  // Estimate (optional - for placeholder)
  estimate?: Estimate;
  scopeOfWork?: string;
  
  // Timeline
  startDate?: string;
  completionWeeks?: number;
  
  // Branding
  companyName?: string;
  companyLogo?: string;
  companyPhone?: string;
  companyTagline?: string;
  qrCodeUrl?: string;
  
  // Styling
  primaryColor?: string;
  accentColor?: string;
}

export const defaultProps: Partial<PropertyShowcaseProps> = {
  companyName: 'Apex Negotiations',
  companyPhone: '(555) 123-4567',
  companyTagline: 'Professional Painting Services',
  primaryColor: '#1e3a5f',
  accentColor: '#10b981',
  completionWeeks: 3,
  scopeOfWork: 'Full interior painting transformation including walls, ceilings, trim, and doors.',
};
