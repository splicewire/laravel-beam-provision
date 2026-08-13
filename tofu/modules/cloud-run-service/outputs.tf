output "service_url" {
  value       = google_cloud_run_v2_service.this.uri
  description = "The default *.run.app URL. TLS is zero-touch for this URL (ticket 01's finding)."
}

output "service_name" {
  value = google_cloud_run_v2_service.this.name
}
