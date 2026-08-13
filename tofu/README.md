# tofu/

Hand-authored OpenTofu modules for the GCP provisioning substrate
(`gcp-cloud-run-provisioning` map, ticket 07). Two modules, one per resource shape:

- `modules/cloud-run-service` — a Cloud Run v2 service
- `modules/tenant-database` — a network-isolated Cloud SQL Postgres instance + database + user +
  a Secret Manager-held credential

## These are child modules, not root configs

Neither module declares a `terraform`/`backend` block or a `provider` block — OpenTofu doesn't
allow child modules to configure their own backend, and a shared provider config belongs to
whatever calls the module. Ticket 07's original question read as if the modules themselves needed
a `backend "gcs"` block; empirically that's not how OpenTofu is structured. The backend lives in
the **per-tenant root config** — the small `.tf.json` `laravel-beam-provision` renders (ticket 08's
`TerraformSchemaHydrator` → `DataClassGenerator` round-trip, ticket 09's apply mechanism) — which
looks roughly like:

```hcl
terraform {
  backend "gcs" {
    # bucket + prefix supplied via -backend-config at `tofu init` time,
    # never literals here — see ticket 06.
  }
  required_providers {
    google = {
      source  = "hashicorp/google"
      version = "~> 6.0"
    }
  }
}

provider "google" {
  project = var.project_id
  region  = var.region
}

module "cloud_run_service" {
  source = "../../tofu/modules/cloud-run-service" # or a pinned git ref once versioned
  # ...tenant-specific vars, rendered by ticket 08's hydrator
}

module "tenant_database" {
  source = "../../tofu/modules/tenant-database"
  # ...
}
```

That root config doesn't exist yet — it's ticket 08/09's job to render/apply it. This directory
only holds the two modules it calls.

## Network prerequisite for `tenant-database`

`tenant-database` assumes a private-services-access VPC peering already exists for Cloud SQL — it
does not create one. Checked live against the `splicewire` project (2026-08-12): Compute Engine API
and Service Networking API were both disabled (enabled here to check), a `default` auto-mode VPC
network exists, but `gcloud services vpc-peerings list --network=default --project=splicewire`
returned no peerings. That peering is a one-time, project-level, shared prerequisite — not
something a per-tenant `tofu apply` should attempt to create (concurrent tenant applies racing to
create the same global peering is exactly the kind of shared-mutable-state bug per-tenant state
isolation (ticket 06) is designed to avoid). Bootstrapping it is a new ticket, same shape as ticket
06's bucket bootstrap.
