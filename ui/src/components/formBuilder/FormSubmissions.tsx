import { Cross2Icon, DownloadIcon } from '@radix-ui/react-icons';
import {
  Box,
  Button,
  Flex,
  IconButton,
  ScrollArea,
  Table,
  Text,
} from '@radix-ui/themes';

import {
  useDeleteSubmissionMutation,
  useGetSubmissionsQuery,
} from '@/services/canvasFormApi';

interface FormSubmissionsProps {
  formId: string;
  formLabel: string;
}

export function FormSubmissions({ formId, formLabel }: FormSubmissionsProps) {
  const { data: submissions = [], isLoading } = useGetSubmissionsQuery(formId, {
    skip: !formId,
  });
  const [deleteSubmission] = useDeleteSubmissionMutation();

  const valueColumns = Array.from(
    new Set(submissions.flatMap((s) => Object.keys(s.values ?? {}))),
  );

  const csvUrl = `/admin/content/canvas-form-submissions/${formId}/export`;

  if (isLoading) {
    return (
      <Flex align="center" justify="center" style={{ height: '100%' }}>
        <Text size="1" color="gray">
          Loading submissions…
        </Text>
      </Flex>
    );
  }

  return (
    <Flex direction="column" style={{ height: '100%', overflow: 'hidden' }}>
      <Flex
        align="center"
        px="3"
        py="2"
        gap="3"
        style={{ borderBottom: '1px solid var(--gray-5)', flexShrink: 0 }}
      >
        <Text size="2" weight="medium">
          {submissions.length} submission{submissions.length !== 1 ? 's' : ''}
        </Text>
        <Button size="1" variant="ghost" asChild>
          <a href={csvUrl} download>
            <DownloadIcon />
            Export CSV
          </a>
        </Button>
      </Flex>

      {submissions.length === 0 ? (
        <Flex align="center" justify="center" style={{ flex: 1 }}>
          <Text size="1" color="gray">
            No submissions yet.
          </Text>
        </Flex>
      ) : (
        <ScrollArea style={{ flex: 1 }}>
          <Box p="2">
            <Table.Root size="1" variant="surface">
              <Table.Header>
                <Table.Row>
                  <Table.ColumnHeaderCell>Date</Table.ColumnHeaderCell>
                  <Table.ColumnHeaderCell>IP</Table.ColumnHeaderCell>
                  {valueColumns.map((col) => (
                    <Table.ColumnHeaderCell key={col}>
                      {col}
                    </Table.ColumnHeaderCell>
                  ))}
                  <Table.ColumnHeaderCell />
                </Table.Row>
              </Table.Header>
              <Table.Body>
                {submissions.map((submission) => (
                  <Table.Row key={submission.id}>
                    <Table.Cell style={{ whiteSpace: 'nowrap' }}>
                      {new Date(
                        submission.submitted_at * 1000,
                      ).toLocaleString()}
                    </Table.Cell>
                    <Table.Cell>{submission.ip}</Table.Cell>
                    {valueColumns.map((col) => {
                      const val = submission.values?.[col] ?? '';
                      return (
                        <Table.Cell key={col}>
                          {Array.isArray(val) ? val.join(', ') : String(val)}
                        </Table.Cell>
                      );
                    })}
                    <Table.Cell>
                      <IconButton
                        size="1"
                        variant="ghost"
                        color="red"
                        onClick={() =>
                          deleteSubmission({
                            formId,
                            submissionId: submission.id,
                          })
                        }
                        aria-label="Delete submission"
                      >
                        <Cross2Icon />
                      </IconButton>
                    </Table.Cell>
                  </Table.Row>
                ))}
              </Table.Body>
            </Table.Root>
          </Box>
        </ScrollArea>
      )}
    </Flex>
  );
}
