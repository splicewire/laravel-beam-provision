variable "project_id" {
  type        = string
  description = "GCP project the instance is provisioned into."
}

variable "region" {
  type        = string
  description = "Cloud SQL region, e.g. us-central1."
}

variable "tenant_id" {
  type        = string
  description = "Opaque, URL-safe tenant identifier — same id used for the GCS state prefix (ticket 06). Not assumed to be a UUID/ULID/slug specifically."
}

variable "network" {
  type        = string
  description = <<-EOT
    Self-link of the VPC with an existing private-services-access peering, e.g.
    "projects/<project>/global/networks/default".

    This module assumes the peering already exists — it does not create one. Checked live
    (2026-08-12): the splicewire project has no private-services-access peering yet. That's a
    one-time, project-level, shared prerequisite bootstrapped once outside this module (same shape
    as ticket 06's state bucket) — not something a per-tenant apply should attempt to create, since
    concurrent tenant applies would race to create the same global peering.
  EOT
}

variable "database_version" {
  type    = string
  default = "POSTGRES_16"
}

variable "tier" {
  type        = string
  description = "Cloud SQL machine tier, e.g. db-f1-micro for a low-cost pilot."
  default     = "db-f1-micro"
}

variable "deletion_protection" {
  type        = bool
  description = "Must be false for teardown() to actually delete the instance (ticket 04's invariant #8)."
  default     = false
}

variable "secret_accessor_member" {
  type        = string
  description = "IAM member (e.g. \"serviceAccount:...\") granted access to this tenant's generated credential secret — typically the Cloud Run service's runtime identity."
}
