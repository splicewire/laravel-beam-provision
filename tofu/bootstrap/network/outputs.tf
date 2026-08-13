output "network_self_link" {
  value       = google_compute_network.tenant.self_link
  description = "Self-link for the `network` variable tenant-database (ticket 07) and any future Cloud Run VPC connectivity expect."
}

output "network_id" {
  value = google_compute_network.tenant.id
}

output "subnet_self_link" {
  value = google_compute_subnetwork.tenant_primary.self_link
}

output "peering_range_name" {
  value = google_compute_global_address.private_services_range.name
}
