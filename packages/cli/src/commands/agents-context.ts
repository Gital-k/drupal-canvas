import chalk from 'chalk';
import * as p from '@clack/prompts';

import { createApiService, ensureAuthConfig } from '../services/api';
import {
  formatAgentsContextProviders,
  pullAgentsContext,
} from '../utils/agents-context';
import { updateConfigFromOptions } from '../utils/command-helpers';

import type { Command } from 'commander';

interface AgentsContextOptions {
  all?: boolean;
  clientId?: string;
  clientSecret?: string;
  siteUrl?: string;
  scope?: string;
}

export function agentsContextCommand(program: Command): void {
  const command = program
    .command('agents-context')
    .description('pull context for local AI agents from the Drupal site')
    .option('--client-id <id>', 'Client ID')
    .option('--client-secret <secret>', 'Client Secret')
    .option('--site-url <url>', 'Site URL')
    .option('--scope <scope>', 'Scope')
    .option('--all', 'Execute all default context providers')
    .argument('[provider]', 'Context provider to execute')
    .argument('[providerArgs...]', 'Arguments passed to the selected provider')
    .addHelpText(
      'after',
      ({ command }) => `\n${formatAgentsContextProviders(command)}`,
    )
    .action(
      async (
        provider: string | undefined,
        providerArgs: string[],
        options: AgentsContextOptions,
      ) => {
        try {
          if (!provider && !options.all) {
            command.outputHelp();
            return;
          }
          if (provider && options.all) {
            throw new Error(
              'Cannot use --all with a provider. Use either --all or a provider key.',
            );
          }

          const configOptions = { ...options };
          delete configOptions.all;
          updateConfigFromOptions(configOptions);

          await ensureAuthConfig();

          const apiService = await createApiService();
          const projectRoot = process.cwd();

          await pullAgentsContext(
            apiService,
            projectRoot,
            provider,
            providerArgs,
            (message) => process.stdout.write(`${message}\n`),
            undefined,
            options.all === true,
          );
        } catch (error) {
          if (error instanceof Error) {
            p.log.error(chalk.red(`Error: ${error.message}`));
          } else {
            p.log.error(chalk.red(`Unknown error: ${String(error)}`));
          }
          process.exit(1);
        }
      },
    );
}
