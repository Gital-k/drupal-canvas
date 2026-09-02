import { useNavigate } from 'react-router-dom';
import { LockClosedIcon } from '@radix-ui/react-icons';
import { Button, Callout, Flex } from '@radix-ui/themes';

import type React from 'react';

/**
 * Shown instead of the editor when the layout may not be fetched.
 *
 * A page can be listed in the Pages panel without being editable: folders of
 * pages carry their own edit permission, and published pages are visible to
 * anyone who may see content. Retrying such a request can never succeed, so no
 * retry is offered — only the way back.
 *
 * @see \Drupal\canvas\FolderPermissions
 */
const NoEditAccessWarning: React.FC = () => {
  const navigate = useNavigate();
  return (
    <Flex align="center" justify="center" width="100%">
      <Flex maxWidth="400px">
        <Callout.Root color="amber" data-testid="canvas-no-edit-access">
          <Callout.Icon>
            <LockClosedIcon />
          </Callout.Icon>
          <Callout.Text>
            You do not have permission to edit this page. Ask a site
            administrator if you need access to it.
          </Callout.Text>
          <Button mt="2" onClick={() => navigate('/', { replace: true })}>
            Back to pages
          </Button>
        </Callout.Root>
      </Flex>
    </Flex>
  );
};

export default NoEditAccessWarning;
