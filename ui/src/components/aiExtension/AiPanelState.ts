export const AI_PANEL_STATE_KEY = 'canvas_ai_panel_state';

// Load AI panel state from localStorage.
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
