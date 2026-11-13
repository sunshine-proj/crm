// Document registry display helpers. The API decides permissions, numbering and status; these only label and shape data.

export type DocumentStatus = "draft" | "in_approval" | "approved" | "rejected" | "withdrawn" | "registered" | "in_force" | "superseded" | "archived";
export type Confidentiality = "general" | "confidential";

export type DocumentTypeInfo = {
  id: number;
  code: string;
  name: string;
  description: string | null;
  number_pattern: string;
  number_example: string;
  requires_approval: boolean;
  default_confidentiality: Confidentiality;
  retention_years: number | null;
  required_fields: string[];
  active: boolean;
};

export type DocumentVersion = {
  number: number;
  original_name: string;
  mime_type: string;
  size_bytes: number;
  sha256: string;
  uploaded_by: string;
  comment: string | null;
  previewable: boolean;
  has_text: boolean;
  created_at: string;
};

export type DocumentPermissions = {
  can_manage: boolean;
  can_edit: boolean;
  can_check_out: boolean;
  can_check_in: boolean;
  can_register: boolean;
  can_archive: boolean;
  can_delete: boolean;
};

export type DocumentSummary = {
  id: number;
  type: { id: number; code: string; name: string };
  title: string;
  status: DocumentStatus;
  confidentiality: Confidentiality;
  registration_number: string | null;
  registered_at: string | null;
  author: { id: number; email: string };
  owner_unit: { id: number; name: string } | null;
  tags: string[];
  effective_on: string | null;
  expires_on: string | null;
  version_count: number;
  current_version: DocumentVersion | null;
  checked_out_by: { id: number; email: string } | null;
  created_at: string;
  updated_at: string;
  permissions: DocumentPermissions;
};

export type DocumentDetail = DocumentSummary & {
  summary: string | null;
  review_on: string | null;
  registered_by: string | null;
  archived_at: string | null;
  checked_out_at: string | null;
  client: { id: number; title: string } | null;
  project: { id: number; title: string } | null;
  deal: { id: number; title: string } | null;
  readers: { id: number; email: string }[];
  missing_required_fields: string[];
  versions: DocumentVersion[];
  attachments: { id: number; original_name: string; mime_type: string; size_bytes: number; sha256: string; uploaded_by: string; created_at: string }[];
  links: { id: number; kind: LinkKind; direction: "outgoing" | "incoming"; document: { id: number; title: string; registration_number: string | null; status: DocumentStatus }; created_by: string; created_at: string }[];
  events: { id: number; action: string; actor: string; details: Record<string, string | number | boolean | null>; created_at: string }[];
};

export type LinkKind = "replaces" | "amends" | "refers_to";

export type DocumentFilters = { q: string; typeId: number | null; status: DocumentStatus | ""; ownerUnitId: number | null; tag: string; mine: boolean; from: string; to: string };

export const statusLabels: Record<DocumentStatus, string> = {
  draft: "Draft",
  in_approval: "In approval",
  approved: "Approved",
  rejected: "Rejected",
  withdrawn: "Withdrawn",
  registered: "Registered",
  in_force: "In force",
  superseded: "Superseded",
  archived: "Archived",
};

/** Visual tone of a status pill: work in progress, done, attention needed, or finished. */
export function statusTone(status: DocumentStatus): "progress" | "done" | "attention" | "closed" {
  if (status === "in_force" || status === "registered" || status === "approved") return "done";
  if (status === "rejected") return "attention";
  if (status === "superseded" || status === "archived" || status === "withdrawn") return "closed";
  return "progress";
}

export const linkKindLabels: Record<LinkKind, { outgoing: string; incoming: string }> = {
  replaces: { outgoing: "Replaces", incoming: "Replaced by" },
  amends: { outgoing: "Amends", incoming: "Amended by" },
  refers_to: { outgoing: "Refers to", incoming: "Referred to by" },
};

export const requirableFields: { field: string; label: string }[] = [
  { field: "summary", label: "Summary" },
  { field: "owner_unit", label: "Owner unit" },
  { field: "effective_on", label: "Effective date" },
  { field: "expires_on", label: "Expiry date" },
  { field: "review_on", label: "Review date" },
  { field: "client", label: "Client" },
  { field: "project", label: "Project" },
];

export function fieldLabel(field: string): string {
  return requirableFields.find((item) => item.field === field)?.label ?? field;
}

const eventLabels: Record<string, string> = {
  created: "Created",
  updated: "Details changed",
  version_added: "New version",
  returned_to_draft: "Returned to draft",
  checked_out: "Checked out",
  checked_in: "Checked in",
  lock_overridden: "Lock overridden",
  registered: "Registered",
  archived: "Archived",
  readers_changed: "Readers changed",
  attachment_added: "Attachment added",
  attachment_removed: "Attachment removed",
  link_added: "Link added",
  link_removed: "Link removed",
  downloaded: "Downloaded",
};

export function eventLabel(action: string): string {
  return eventLabels[action] ?? action.replace(/_/g, " ");
}

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(Math.round((bytes / 1024) * 10) / 10).toString()} KB`;
  return `${(Math.round((bytes / (1024 * 1024)) * 10) / 10).toString()} MB`;
}

/** Query string for GET /dms/documents; empty filters are left out. */
export function documentQuery(filters: DocumentFilters, limit: number, offset: number): string {
  const params = new URLSearchParams();
  if (filters.q.trim()) params.set("q", filters.q.trim());
  if (filters.typeId !== null) params.set("type_id", String(filters.typeId));
  if (filters.status) params.set("status", filters.status);
  if (filters.ownerUnitId !== null) params.set("owner_unit_id", String(filters.ownerUnitId));
  if (filters.tag.trim()) params.set("tag", filters.tag.trim().toLocaleLowerCase());
  if (filters.mine) params.set("mine", "1");
  if (filters.from) params.set("from", filters.from);
  if (filters.to) params.set("to", filters.to);
  params.set("limit", String(limit));
  params.set("offset", String(offset));
  return params.toString();
}

/** Splits comma-separated tag input into clean, unique lower-case tags. */
export function parseTags(input: string): string[] {
  return [...new Set(input.split(",").map((tag) => tag.trim().toLocaleLowerCase()).filter(Boolean))];
}

export function emptyFilters(): DocumentFilters {
  return { q: "", typeId: null, status: "", ownerUnitId: null, tag: "", mine: false, from: "", to: "" };
}
