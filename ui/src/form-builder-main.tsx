import { createRoot } from 'react-dom/client';
import { Provider } from 'react-redux';
import { Theme } from '@radix-ui/themes';
import { configureStore } from '@reduxjs/toolkit';
import { setupListeners } from '@reduxjs/toolkit/query';

import { FormBuilderApp } from '@/components/formBuilder/FormBuilderApp';
import formBuilderSlice from '@/features/formBuilder/formBuilderSlice';
import { canvasFormApi } from '@/services/canvasFormApi';

import '@radix-ui/themes/styles.css';
import '@/styles/index.css';

const store = configureStore({
  reducer: {
    formBuilder: formBuilderSlice.reducer,
    [canvasFormApi.reducerPath]: canvasFormApi.reducer,
  },
  middleware: (getDefaultMiddleware) =>
    getDefaultMiddleware().concat(canvasFormApi.middleware),
});

setupListeners(store.dispatch);

const container = document.getElementById('canvas-form-builder');
if (container) {
  const root = createRoot(container);
  root.render(
    <Provider store={store}>
      <Theme
        accentColor="blue"
        hasBackground={false}
        panelBackground="solid"
        appearance="light"
      >
        <FormBuilderApp />
      </Theme>
    </Provider>,
  );
}
