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
  default     = null
  description = <<-EOT
    Self-link of the VPC with an existing private-services-access peering, e.g.
    "projects/<project>/global/networks/default".

    Required for the private-IP consumer (the sibling map's Cloud-Run-hosted service) — this
    module assumes the peering already exists, it does not create one. Checked live (2026-08-12):
    the splicewire project has no private-services-access peering yet. That's a one-time,
    project-level, shared prerequisite bootstrapped once outside this module (same shape as
    ticket 06's state bucket) — not something a per-tenant apply should attempt to create, since
    concurrent tenant applies would race to create the same global peering.

    Leave unset (null) for a public-IP consumer (`ipv4_enabled = true`) — no VPC peering is
    needed when the instance isn't reached over private IP.
  EOT
}

variable "ipv4_enabled" {
  type        = bool
  default     = false
  description = <<-EOT
    Give the instance a public IP. Default false preserves the sibling map's existing
    private-IP-only consumer unchanged. Set true for a consumer that can't reach a private-IP
    instance (ticket 14: splicewire-app's `plesk2.frameworks.fit` deploy target sits entirely
    outside GCP) — pair with `authorized_networks` to allowlist who can reach it.
  EOT
}

variable "authorized_networks" {
  type = list(object({
    name = string
    cidr = string
  }))
  default     = []
  description = <<-EOT
    IP allowlist for the public IP (Cloud SQL's Authorized Networks) — the network-boundary
    replacement for the private-IP isolation `ipv4_enabled = false` would otherwise give.
    Ignored when `ipv4_enabled = false`. Empty by default: an `ipv4_enabled = true` instance
    with no entries here is reachable from nowhere until entries are added, not open to the
    world.
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
  description = <<-EOT
    IAM member (e.g. "serviceAccount:...") granted access to this tenant's generated credential
    secret. For the private-IP, Cloud-Run-hosted consumer this is that service's own runtime
    identity. Still required for a public-IP, no-Cloud-Run consumer (ticket 15) — someone still
    has to read the secret to hand the credential to the tenant/caller — but the identity is a
    different shape (no Cloud Run runtime SA exists to default to); ticket 16 settles what that
    identity actually is for `GcpCloudSqlDestination`.
  EOT
}
