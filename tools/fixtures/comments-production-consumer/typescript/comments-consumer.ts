const createComment = {
    body: 'A production comment',
} satisfies Nvl.Comments.Data.Mutations.CreateCommentData;

const moderateComment = {
    status: 'approved',
    expectedRevision: 1,
} satisfies Nvl.Comments.Data.Mutations.ModerateCommentData;

const reportComment = {
    reason: 'consumer-review',
} satisfies Nvl.Comments.Data.Mutations.ReportCommentData;

const updateComment = {
    body: 'Updated production comment',
    expectedRevision: 1,
} satisfies Nvl.Comments.Data.Mutations.UpdateCommentData;

function renderPublicComment(comment: Nvl.Comments.Data.PublicCommentData): string {
    return comment.author?.displayName ?? 'Anonymous';
}

function inspectManagementComment(comment: Nvl.Comments.Data.CommentManagementData): string | null | undefined {
    return comment.actorId;
}

void [
    createComment,
    moderateComment,
    reportComment,
    updateComment,
    renderPublicComment,
    inspectManagementComment,
];
