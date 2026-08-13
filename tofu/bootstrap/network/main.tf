provider "google" {
  project = var.project_id
  region  = var.region
}

# Dedicated custom-mode VPC for tenant-database traffic — ticket 11's resolution: `default`'s
# auto-created subnets were rejected in favor of splitting tenant-data networking out from day one,
# since nothing tenant-facing lives on `default` yet and moving it later (re-peering, re-pointing
# live Cloud SQL instances) is far more painful than provisioning it correctly now.
resource "google_compute_network" "tenant" {
  project                 = var.project_id
  name                    = var.network_name
  auto_create_subnetworks = false
}

# Primary subnet, reserved for our own resources (e.g. a future Cloud Run VPC connector or Direct
# VPC egress config — see the map's "Not yet specified": how Cloud Run itself reaches this network
# is ticket 10's problem, not this ticket's). Not consumed by the private-services-access peering
# below, which allocates from its own separate range.
resource "google_compute_subnetwork" "tenant_primary" {
  project                  = var.project_id
  name                     = var.subnet_name
  region                   = var.region
  network                  = google_compute_network.tenant.id
  ip_cidr_range            = var.subnet_cidr
  private_ip_google_access = true
}

# Reserved range for Google-managed services (Cloud SQL's private IP lives here). /16 per ticket
# 11's resolution — reserving address space is free, so sized for reseller scale up front rather
# than revisited later.
resource "google_compute_global_address" "private_services_range" {
  project       = var.project_id
  name          = var.peering_range_name
  purpose       = "VPC_PEERING"
  address_type  = "INTERNAL"
  prefix_length = var.peering_prefix_length
  network       = google_compute_network.tenant.id
}

# The peering itself — the actual prerequisite tenant-database (ticket 07) needs before its
# `ipv4_enabled = false` / `private_network = var.network` Cloud SQL instances can provision.
resource "google_service_networking_connection" "private_vpc_connection" {
  network                 = google_compute_network.tenant.id
  service                 = "servicenetworking.googleapis.com"
  reserved_peering_ranges = [google_compute_global_address.private_services_range.name]
}
