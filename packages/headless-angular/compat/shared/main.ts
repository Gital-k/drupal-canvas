import {
  bootstrapApplication,
  provideClientHydration,
} from '@angular/platform-browser';
import { provideRouter } from '@angular/router';

import { App, Shell } from './app';

bootstrapApplication(Shell, {
  providers: [
    provideClientHydration(),
    provideRouter([{ path: '**', component: App }]),
  ],
}).catch(console.error);
