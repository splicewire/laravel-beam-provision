resource "google_sql_database_instance" "this" {
  project             = var.project_id
  name                = "tenant-${var.tenant_id}"
  region              = var.region
  database_version    = var.database_version
  deletion_protection = var.deletion_protection

  settings {
    tier = var.tier
    // Found live (ticket 10): GCP now defaults new Cloud SQL instances to ENTERPRISE_PLUS, which
    // rejects legacy shared-core tiers like the pilot-cost `db-f1-micro` default below
    // ("Invalid Tier for ENTERPRISE_PLUS Edition"). Pinned explicitly rather than switching the
    // default tier to a pricier ENTERPRISE_PLUS one, to keep ticket 07's low-cost-pilot intent.
    edition = "ENTERPRISE"

    ip_configuration {
      ipv4_enabled    = var.ipv4_enabled
      private_network = var.network

      dynamic "authorized_networks" {
        for_each = var.ipv4_enabled ? var.authorized_networks : []
        content {
          name  = authorized_networks.value.name
          value = authorized_networks.value.cidr
        }
      }
    }
  }
}

resource "google_sql_database" "this" {
  project  = var.project_id
  instance = google_sql_database_instance.this.name
  name     = "tenant_${var.tenant_id}"
}

resource "random_password" "db_user" {
  length  = 32
  special = false
}

resource "google_sql_user" "this" {
  project  = var.project_id
  instance = google_sql_database_instance.this.name
  name     = "tenant_${var.tenant_id}"
  password = random_password.db_user.result
}

resource "google_secret_manager_secret" "db_credential" {
  project   = var.project_id
  secret_id = "tenant-${var.tenant_id}-db-credential"

  replication {
    auto {}
  }
}

resource "google_secret_manager_secret_version" "db_credential" {
  secret = google_secret_manager_secret.db_credential.id
  secret_data = jsonencode({
    connection_name = google_sql_database_instance.this.connection_name
    database        = google_sql_database.this.name
    username        = google_sql_user.this.name
    password        = random_password.db_user.result
  })
}

resource "google_secret_manager_secret_iam_member" "db_credential_accessor" {
  project   = var.project_id
  secret_id = google_secret_manager_secret.db_credential.secret_id
  role      = "roles/secretmanager.secretAccessor"
  member    = var.secret_accessor_member
}
