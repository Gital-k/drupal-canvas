export type WorkspaceStatus = 'draft' | 'in_review' | 'scheduled' | 'published';

export interface WorkspaceReviewer {
  id: string;
  type: 'user' | 'group';
  name: string;
  avatarUrl?: string;
}

export interface Workspace {
  id: string;
  name: string;
  status: WorkspaceStatus;
  isMain: boolean;
  sourceId?: string;
  pendingChangesCount: number;
  reviewers?: WorkspaceReviewer[];
  scheduledAt?: string;
  hasConflicts?: boolean;
  conflictDetails?: {
    count: number;
    conflictingUsers: Pick<WorkspaceReviewer, 'id' | 'avatarUrl'>[];
  };
}

export interface WorkspacePendingChange {
  id: string;
  entityType: string;
  entityId: string;
  label: string;
  updated: number;
  owner: { id: string; name: string; avatarUrl?: string };
}

export interface CreateWorkspacePayload {
  name: string;
  sourceId?: string;
}

export type PublishAction = 'publish' | 'schedule' | 'review';

export interface PublishActionPayload {
  action: PublishAction;
  scheduledAt?: string;
  reviewerIds?: string[];
}
