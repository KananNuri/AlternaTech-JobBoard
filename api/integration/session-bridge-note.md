# Session bridge

`users.id` is the account identity. `people.id` is the applicant/recruiter profile identity.

The integrated session stores both: `account_id` is the user ID; legacy `user_id` is the person ID expected by Charles's existing handlers. Never interchange these IDs. `currentUser()` returns canonical `id` and a separate `person_id` and revalidates the account, role and absolute expiry against the database before protected routes.

The new router intercepts password login/session/logout to use `users.password_hash`. The existing Charles auth files remain unchanged for review/history and are not the integrated runtime entry points. Both password and Google sign-in call `signIn()` and create the same session/profile. Pre-integration sessions are invalidated rather than silently granting privileges.
