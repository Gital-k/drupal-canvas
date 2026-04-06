import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
  AI_PANEL_STATE_KEY,
  loadAiPanelOpenState,
  saveAiPanelState,
} from '@/components/aiExtension/AiPanelState';

describe('AiPanelState', () => {
  beforeEach(() => {
    window.localStorage.clear();
  });

  describe('loadAiPanelOpenState', () => {
    it('returns false when localStorage is empty', () => {
      expect(loadAiPanelOpenState()).toBe(false);
    });

    it('returns true when saved state has isOpen true', () => {
      localStorage.setItem(
        AI_PANEL_STATE_KEY,
        JSON.stringify({ isOpen: true, shouldClearHistory: false }),
      );
      expect(loadAiPanelOpenState()).toBe(true);
    });

    it('returns false when saved state has isOpen false', () => {
      localStorage.setItem(
        AI_PANEL_STATE_KEY,
        JSON.stringify({ isOpen: false, shouldClearHistory: true }),
      );
      expect(loadAiPanelOpenState()).toBe(false);
    });

    it('returns false when saved state has no isOpen property', () => {
      localStorage.setItem(
        AI_PANEL_STATE_KEY,
        JSON.stringify({ shouldClearHistory: true }),
      );
      expect(loadAiPanelOpenState()).toBe(false);
    });

    it('returns false when localStorage contains invalid JSON', () => {
      localStorage.setItem(AI_PANEL_STATE_KEY, 'not valid json');
      const spy = vi.spyOn(console, 'warn').mockImplementation(() => {});
      expect(loadAiPanelOpenState()).toBe(false);
      expect(spy).toHaveBeenCalled();
    });
  });

  describe('saveAiPanelState', () => {
    it('saves isOpen and shouldClearHistory to localStorage', () => {
      saveAiPanelState(true, true);
      const saved = JSON.parse(
        localStorage.getItem(AI_PANEL_STATE_KEY) as string,
      );
      expect(saved).toEqual({ isOpen: true, shouldClearHistory: true });
    });

    it('saves closed state with shouldClearHistory false', () => {
      saveAiPanelState(false, false);
      const saved = JSON.parse(
        localStorage.getItem(AI_PANEL_STATE_KEY) as string,
      );
      expect(saved).toEqual({ isOpen: false, shouldClearHistory: false });
    });

    it('overwrites previously saved state', () => {
      saveAiPanelState(true, true);
      saveAiPanelState(false, false);
      const saved = JSON.parse(
        localStorage.getItem(AI_PANEL_STATE_KEY) as string,
      );
      expect(saved).toEqual({ isOpen: false, shouldClearHistory: false });
    });
  });

  describe('round-trip', () => {
    it('saves and loads state correctly', () => {
      saveAiPanelState(true, false);
      expect(loadAiPanelOpenState()).toBe(true);

      saveAiPanelState(false, true);
      expect(loadAiPanelOpenState()).toBe(false);
    });
  });
});
