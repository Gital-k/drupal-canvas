import { RuleTester } from 'eslint';
import yamlParser from 'yaml-eslint-parser';

import rule from '../src/rules/component-prop-names.js';

const testRunner = new RuleTester({
  languageOptions: {
    parser: yamlParser,
  },
});

testRunner.run('component-prop-names rule', rule, {
  valid: [
    {
      name: 'should pass when prop id is camelCase version of title',
      code: `
        name: Button
        machineName: button
        props:
          type: object
          properties:
            title:
              type: string
              title: Title
            id:
              type: string
              title: ID
            longPropName:
              type: string
              title: Long Prop Name
      `,
      filename: '/components/button/component.yml',
    },
    {
      name: 'should pass for components without props',
      code: `
        name: Button
        machineName: button
        props:
          type: object
          properties: {}
      `,
      filename: '/components/button/component.yml',
    },
    {
      name: 'should pass for components without props key',
      code: `
        name: Button
        machineName: button
      `,
      filename: '/components/button/component.yml',
    },
    {
      name: 'should not be applied to non-component yml files',
      code: `
        name: Button
        machineName: button
        props:
          type: object
          properties:
            title:
              type: string
              title: Button Title
      `,
      filename: '/components/button/button.yml',
    },
    {
      name: 'allows prop machine name to differ from camelCase of title (Code Component / SDC flexibility)',
      code: `
        name: Button
        machineName: button
        props:
          type: object
          properties:
            title:
              type: string
              title: Button Title
      `,
      filename: '/components/button/button.component.yml',
    },
    {
      name: 'allows prop id that is not camelCase of title in component.yml',
      code: `
        name: Button
        machineName: button
        props:
          type: object
          properties:
            title:
              type: string
              title: Button Title
        `,
      filename: '/components/card/component.yml',
    },
  ],
  invalid: [
    {
      name: 'should fail when prop is missing a title',
      code: `
        name: Button
        machineName: button
        props:
          type: object
          properties:
            title:
              type: string
        `,
      filename: '/components/card/component.yml',
      errors: [
        {
          message: 'Prop "title" is missing a title.',
          line: 7,
        },
      ],
    },
  ],
});
