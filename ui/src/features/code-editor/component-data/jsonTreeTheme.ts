// Shared `react-json-tree` base16 theme for read-only JSON display in the
// code editor. Matches the editors' githubLight CodeMirror theme.
export const jsonTreeTheme = {
  scheme: 'github-light-custom',
  author: 'canvas',
  base00: '#ffffff',
  base01: '#f6f8fa',
  base02: '#e1e4e8',
  base03: '#d1d5da',
  base04: '#6a737d',
  base05: '#24292e',
  base06: '#6e7781',
  base07: '#116329',
  base08: '#d73a49',
  base09: '#e36209',
  base0A: '#005cc5',
  base0B: '#6f42c1',
  base0C: '#22863a',
  base0D: '#0366d6',
  base0E: '#032f62',
  base0F: '#b31d28',
};

export const jsonTreeThemeProp = {
  extend: jsonTreeTheme,
  tree: {
    fontSize: 'var(--font-size-1)',
    // Let the tree's background come from whatever wraps it (e.g. a panel's
    // own gray background) instead of always painting its own white one,
    // which otherwise shows up as a mismatched box-in-a-box.
    backgroundColor: 'transparent',
  },
};
