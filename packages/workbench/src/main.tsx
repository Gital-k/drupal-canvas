import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

import './index.css';

import App from './App.tsx';
import PreviewFrameApp from './PreviewFrameApp.tsx';

const isPreviewFrameRoute = window.location.pathname === '/__canvas/preview-frame';

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    {isPreviewFrameRoute ? <PreviewFrameApp /> : <App />}
  </StrictMode>,
);
