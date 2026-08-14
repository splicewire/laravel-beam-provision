resource "google_cloud_run_v2_service" "this" {
  name                = var.service_name
  project             = var.project_id
  location            = var.region
  ingress             = "INGRESS_TRAFFIC_ALL"
  deletion_protection = false

  // A separate, service-level scaling block from `template.scaling` below (found live, ticket 09:
  // leaving this undeclared causes a perpetual apply-detected-drift — the API returns a concrete
  // scaling block on read that an absent config block never reconciles with, so every re-apply
  // reports a change even with nothing to actually change). Declared explicitly and pinned to
  // AUTOMATIC so it's stable, not merely silenced via `lifecycle.ignore_changes`.
  scaling {
    scaling_mode       = "AUTOMATIC"
    min_instance_count = var.min_instance_count
  }

  template {
    service_account = var.service_account_email

    scaling {
      min_instance_count = var.min_instance_count
      max_instance_count = var.max_instance_count
    }

    // Found live (ticket 10, the map's own flagged fog item): the built-in Cloud SQL Auth Proxy
    // (ticket 01) still needs a real network path to a private-IP instance — without Direct VPC
    // egress the proxy's dial to the instance's private IP times out. PRIVATE_RANGES_ONLY (not
    // ALL_TRAFFIC) since only RFC1918-range Cloud SQL traffic needs to leave through the VPC.
    dynamic "vpc_access" {
      for_each = var.network != null ? [1] : []
      content {
        network_interfaces {
          network    = var.network
          subnetwork = var.subnetwork
        }
        egress = "PRIVATE_RANGES_ONLY"
      }
    }

    containers {
      image = var.image

      resources {
        limits = {
          cpu    = var.cpu
          memory = var.memory
        }
      }

      dynamic "env" {
        for_each = var.env_vars
        content {
          name  = env.key
          value = env.value
        }
      }

      dynamic "env" {
        for_each = var.secret_env_vars
        content {
          name = env.key
          value_source {
            secret_key_ref {
              secret  = env.value.secret_id
              version = env.value.version
            }
          }
        }
      }

      dynamic "volume_mounts" {
        for_each = length(var.cloudsql_connection_names) > 0 ? [1] : []
        content {
          name       = "cloudsql"
          mount_path = "/cloudsql"
        }
      }
    }

    dynamic "volumes" {
      for_each = length(var.cloudsql_connection_names) > 0 ? [1] : []
      content {
        name = "cloudsql"
        cloud_sql_instance {
          instances = var.cloudsql_connection_names
        }
      }
    }
  }
}

resource "google_cloud_run_v2_service_iam_member" "public_invoker" {
  count    = var.allow_unauthenticated ? 1 : 0
  project  = var.project_id
  location = var.region
  name     = google_cloud_run_v2_service.this.name
  role     = "roles/run.invoker"
  member   = "allUsers"
}
