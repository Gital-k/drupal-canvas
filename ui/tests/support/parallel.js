import fs from 'fs';
import path from 'path';

const NODE_INDEX = Number(process.env.CI_NODE_INDEX || 1);
const NODE_TOTAL = Number(process.env.CI_NODE_TOTAL || 1);
const TEST_FOLDER = path.join(process.cwd(), 'tests/e2e');
const SLOW_SPEC = 'templates-linking.cy.js';
const SLOW_SPEC_NODE = 5;

const walk = (dir) => {
  let files = fs.readdirSync(dir);
  files = files.map((file) => {
    const filePath = path.join(dir, file);
    const stats = fs.statSync(filePath);
    if (stats.isDirectory()) {
      return walk(filePath);
    }
    if (stats.isFile() && filePath.match(/\.cy\.js$/)) {
      return filePath;
    }
  });

  return files.reduce((all, folderContents) => all.concat(folderContents), []);
};

const getSpecFiles = () => {
  const allFiles = walk(TEST_FOLDER).sort().filter(Boolean);
  const slowSpec = allFiles.find((f) => f.endsWith(SLOW_SPEC));
  const otherSpecs = allFiles.filter((f) => !f.endsWith(SLOW_SPEC));

  if (NODE_INDEX === SLOW_SPEC_NODE) {
    return slowSpec ? [slowSpec] : [];
  }

  // Distribute remaining files across nodes 1..N-1
  const remainingNodes = NODE_TOTAL - 1;
  return otherSpecs.filter(
    (_, index) => index % remainingNodes === NODE_INDEX - 1,
  );
};

console.log(getSpecFiles().join(','));
