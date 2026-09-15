// Single source of truth for turning an axios error into a user-facing
// string: prefers the first 422 validation error (over the generic top-level
// message, since that's the more specific/actionable one), then the
// top-level `message`, then a caller-supplied fallback. Previously this exact
// three-line extraction was copy-pasted in every form's catch block.
export function getErrorMessage(error, fallback) {
  const fieldErrors = error?.response?.data?.errors
  const firstFieldError = fieldErrors ? Object.values(fieldErrors).flat()[0] : null

  return firstFieldError ?? error?.response?.data?.message ?? fallback
}
