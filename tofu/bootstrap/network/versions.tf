terraform {
  backend "gcs" {
    bucket = "splicewire-beam-tofu-state"
    prefix = "bootstrap/network"
  }

  required_providers {
    google = {
      source  = "hashicorp/google"
      version = "~> 6.0"
    }
  }
}
