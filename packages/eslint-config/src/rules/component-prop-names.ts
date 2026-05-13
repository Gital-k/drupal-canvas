import { isComponentYmlFile } from '../utils/components.js';
import {
  getYAMLMappingPair,
  getYAMLStringValue,
  isYAMLMapping,
} from '../utils/yaml.js';

import type { Rule as EslintRule } from 'eslint';
import type { AST } from 'yaml-eslint-parser';

function extractProps(
  propsNode: AST.YAMLPair,
): Array<{ id: string; title: string | null; node: AST.YAMLPair }> {
  // Get properties mapping.
  if (!isYAMLMapping(propsNode.value)) {
    return [];
  }
  const propertiesValue = getYAMLMappingPair(
    propsNode.value,
    'properties',
  )?.value;
  if (!isYAMLMapping(propertiesValue)) {
    return [];
  }

  // Extract props from properties mapping.
  const props: Array<{ id: string; title: string | null; node: AST.YAMLPair }> =
    [];
  for (const pair of propertiesValue.pairs) {
    const propId = getYAMLStringValue(pair.key);
    if (!propId) continue;

    if (!isYAMLMapping(pair.value)) continue;

    let title = null;
    title = getYAMLStringValue(getYAMLMappingPair(pair.value, 'title')?.value);

    props.push({
      id: propId,
      title: title,
      node: pair,
    });
  }

  return props;
}

const rule: EslintRule.RuleModule = {
  meta: {
    type: 'problem',
    docs: {
      description:
        'Validates that each component prop in YAML has a title (SDC `title` field)',
    },
  },
  create(context: EslintRule.RuleContext): EslintRule.RuleListener {
    if (!isComponentYmlFile(context.filename)) {
      return {};
    }

    return {
      YAMLPair(node: AST.YAMLPair) {
        const keyName = getYAMLStringValue(node.key);
        if (keyName !== 'props') {
          return;
        }

        const props = extractProps(node);
        if (props.length === 0) {
          return;
        }

        for (const prop of props) {
          if (!prop.title) {
            context.report({
              node: prop.node,
              message: `Prop "${prop.id}" is missing a title.`,
            });
          }
        }
      },
    };
  },
};

export default rule;
