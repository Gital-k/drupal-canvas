import { isComponentYmlFile } from '../utils/components.js';
import { getYAMLStringValue } from '../utils/yaml.js';

import type { Rule as EslintRule } from 'eslint';
import type { AST } from 'yaml-eslint-parser';

function extractProps(
  propsNode: AST.YAMLPair,
): Array<{ id: string; title: string | null; node: AST.YAMLPair }> {
  // Get properties mapping.
  if (!propsNode.value || propsNode.value.type !== 'YAMLMapping') {
    return [];
  }
  const propsMapping = propsNode.value as AST.YAMLMapping;
  const propertiesPair = propsMapping.pairs.find(
    (p) => getYAMLStringValue(p.key) === 'properties',
  );
  if (
    !propertiesPair ||
    !propertiesPair.value ||
    propertiesPair.value.type !== 'YAMLMapping'
  ) {
    return [];
  }
  const propertiesMapping = propertiesPair.value as AST.YAMLMapping;

  // Extract props from properties mapping.
  const props: Array<{ id: string; title: string | null; node: AST.YAMLPair }> =
    [];
  for (const pair of propertiesMapping.pairs) {
    const propId = getYAMLStringValue(pair.key);
    if (!propId) continue;

    if (!pair.value || pair.value.type !== 'YAMLMapping') continue;

    const propMapping = pair.value as AST.YAMLMapping;
    const titlePair = propMapping.pairs.find(
      (p) => getYAMLStringValue(p.key) === 'title',
    );

    let title = null;
    if (titlePair) {
      title = getYAMLStringValue(titlePair.value);
    }

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
