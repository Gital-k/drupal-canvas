import {
  bootstrapApplication,
  provideClientHydration,
} from '@angular/platform-browser';
import { provideRouter } from '@angular/router';
import { provideCanvas } from '@drupal-canvas/headless-angular';

import { Shell } from './app';
import { routes } from './routes';

bootstrapApplication(Shell, {
  providers: [provideClientHydration(), provideRouter(routes), provideCanvas()],
})
  .then((app) => {
    Object.assign(window, { __destroyApp: () => app.destroy() });
  })
  .catch(console.error);
