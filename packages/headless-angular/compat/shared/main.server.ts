import {
  bootstrapApplication,
  provideClientHydration,
} from '@angular/platform-browser';
import { provideRouter } from '@angular/router';
import { provideServerRendering, RenderMode, withRoutes } from '@angular/ssr';

import { App, Shell } from './app';

import type { BootstrapContext } from '@angular/platform-browser';

export default (context: BootstrapContext) =>
  bootstrapApplication(
    Shell,
    {
      providers: [
        provideClientHydration(),
        provideRouter([{ path: '**', component: App }]),
        provideServerRendering(
          withRoutes([{ path: '**', renderMode: RenderMode.Server }]),
        ),
      ],
    },
    context,
  );
