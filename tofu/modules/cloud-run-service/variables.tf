variable "project_id" {
  type        = string
  description = "GCP project the service is deployed into."
}

variable "region" {
  type        = string
  description = "Cloud Run region, e.g. us-central1."
}

variable "service_name" {
  type        = string
  description = "Cloud Run service id. Also the tenant identifier for this deploy."
}

variable "image" {
  type        = string
  description = <<-EOT
    Fully-qualified image reference (Artifact Registry URI) to deploy.

    This module does not build or push images, and does not wire git-integration/CD — ticket 04
    found Cloud Run's "continuously deploy from a repository" feature is Console/internal-only, not
    composable via the documented public API. The caller (or whatever runs before it in the apply
    pipeline) is responsible for the image existing at this reference before apply.
  EOT
}

variable "env_vars" {
  type        = map(string)
  description = "Plain (non-secret) environment variables for the service."
  default     = {}
}

variable "secret_env_vars" {
  type = map(object({
    secret_id = string
    version   = optional(string, "latest")
  }))
  description = "Environment variables sourced from Secret Manager secrets, e.g. the tenant DB credential from the tenant-database module's secret_id output."
  default     = {}
}

variable "cloudsql_connection_names" {
  type        = list(string)
  description = "Cloud SQL instance connection names to attach via the built-in Auth Proxy Unix socket (ticket 01's finding)."
  default     = []
}

variable "service_account_email" {
  type        = string
  description = "Runtime service account for the Cloud Run service. Defaults to the project's default compute SA if null."
  default     = null
}

variable "allow_unauthenticated" {
  type        = bool
  description = "Whether the service is publicly invokable. True for a beam-tier public site."
  default     = true
}

variable "cpu" {
  type    = string
  default = "1"
}

variable "memory" {
  type    = string
  default = "512Mi"
}

variable "min_instance_count" {
  type        = number
  description = "0 keeps idle tenants cost-free at the price of cold starts."
  default     = 0
}

variable "max_instance_count" {
  type    = number
  default = 3
}
