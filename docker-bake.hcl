variable "IMAGE" {
	default = "heussd/fivefilters-full-text-rss"
}

group "default" {
	targets = ["all"]
}

target "all" {
  tags = ["${IMAGE}:latest", "${IMAGE}:3.8.1"]
	platforms = ["linux/amd64", "linux/arm64", "linux/arm/v7", "linux/arm/v6"]
}

target "amd64" {
	tags = ["${IMAGE}"]
	platforms = ["linux/amd64"]
}
