output "connection_name" {
  value       = google_sql_database_instance.this.connection_name
  description = "Cloud SQL instance connection name, for the Auth Proxy Unix socket (ticket 01's finding) — pass into cloud-run-service's cloudsql_connection_names."
}

output "database_name" {
  value = google_sql_database.this.name
}

output "secret_id" {
  value       = google_secret_manager_secret.db_credential.secret_id
  description = "Secret Manager secret id holding the generated credential (JSON: connection_name/database/username/password). No plaintext output — read via secret_env_vars on cloud-run-service."
}

output "public_ip_address" {
  value       = var.ipv4_enabled ? google_sql_database_instance.this.public_ip_address : null
  description = "The instance's public IPv4 address, for a consumer that can't reach it over private IP (ticket 14/15). Null when ipv4_enabled = false."
}
