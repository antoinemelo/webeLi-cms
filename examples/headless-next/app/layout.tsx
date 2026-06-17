import type { Metadata } from 'next';
import './style.css';

export const metadata: Metadata = {
  title: 'DEC CMS Headless Next.js',
  description: 'Exemple minimal Next.js App Router pour DEC CMS headless v1.',
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="fr">
      <body>{children}</body>
    </html>
  );
}
