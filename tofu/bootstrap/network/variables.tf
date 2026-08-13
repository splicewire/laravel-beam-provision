variable "project_id" {
  type        = string
  description = "GCP project to provision the dedicated tenant network into."
  default     = "splicewire"
}

variable "region" {
  type        = string
  description = "Region for the tenant network's primary subnet — matches the state bucket and Cloud Build connections' us-central1 (ticket 02/06)."
  default     = "us-central1"
}

variable "network_name" {
  type        = string
  description = "Name of the dedicated custom-mode VPC for tenant-database traffic (replaces `default` per ticket 11's resolution)."
  default     = "beam-tenant-network"
}

variable "subnet_name" {
  type        = string
  description = "Primary subnet name — for future Cloud Run VPC connectivity (connector or Direct VPC egress) to reach private-IP tenant databases. Not used by the peering itself."
  default     = "beam-tenant-subnet-us-central1"
}

variable "subnet_cidr" {
  type        = string
  description = "CIDR for the primary subnet. Distinct address space from the private-services-access range below — this is for our own resources (e.g. a future Cloud Run VPC connector), not Google-managed services."
  default     = "10.10.0.0/20"
}

variable "peering_range_name" {
  type        = string
  description = "Name of the reserved global address range handed to the private-services-access peering."
  default     = "beam-tenant-private-services-range"
}

variable "peering_prefix_length" {
  type        = number
  description = "Prefix length of the reserved range for Google-managed services (Cloud SQL, etc.). /16 chosen per ticket 11's resolution — reservation is free regardless of size, so sized for reseller scale rather than the pilot's actual near-term need."
  default     = 16
}
