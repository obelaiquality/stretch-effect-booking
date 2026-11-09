// Real photos of Ivan and the studio, used only with client consent (docs/DESIGN.md section 6).
// Put files in web/public/photos/ and list them here. While this list is empty, the site shows
// no photo slots at all.
export interface Photo {
  src: string; // e.g. 'photos/hero-desktop.webp' (relative to the site base)
  srcPhone?: string; // 4:5 crop for phones
  alt: string;
  role: 'hero' | 'gallery';
}

export const PHOTOS: Photo[] = [];
