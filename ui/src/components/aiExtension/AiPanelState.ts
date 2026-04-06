export const AI_PANEL_STATE_KEY = 'canvas_ai_panel_state';

// Load AI panel open state from localStorage.
export const loadAiPanelOpenState = (): boolean => {
  try {
    const saved = localStorage.getItem(AI_PANEL_STATE_KEY);
    if (saved) {
      const state = JSON.parse(saved);
      return state.isOpen || false;
    }
  } catch (e) {
    console.warn('Failed to load AI panel state from localStorage:', e);
  }
  return false;
};

// Save AI panel state to localStorage.
export const saveAiPanelState = (
  isOpen: boolean,
  shouldClearHistory: boolean,
): void => {
  try {
    localStorage.setItem(
      AI_PANEL_STATE_KEY,
      JSON.stringify({ isOpen, shouldClearHistory }),
    );
  } catch (e) {
    console.warn('Failed to save AI panel state to localStorage:', e);
  }
};
