import type { ReactNode } from 'react';
import { ToastProvider } from './Toast';

/** Page frame for /app pages: toast host (the header holds the navigation), width wrap. */
export default function ClientFrame({ children }: { children: ReactNode }) {
  return (
    <ToastProvider>
      <div className="wrap tse-clientpage">
        {children}
      </div>
    </ToastProvider>
  );
}
