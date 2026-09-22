import { canvasPageResolver } from '@drupal-canvas/headless-angular';

import { App } from './app';
import { IntegrationHost, IntegrationPage } from './integration';

import type { Routes } from '@angular/router';

export const routes: Routes = [
  {
    path: '',
    pathMatch: 'full',
    component: IntegrationPage,
    resolve: { canvas: canvasPageResolver },
  },
  { path: 'host', component: IntegrationHost },
  {
    path: 'integration',
    children: [
      {
        path: '**',
        component: IntegrationPage,
        resolve: { canvas: canvasPageResolver },
        runGuardsAndResolvers: 'always',
      },
    ],
  },
  {
    path: 'api/canvas/component-preview',
    component: IntegrationPage,
    resolve: { canvas: canvasPageResolver },
  },
  { path: '**', component: App },
];
