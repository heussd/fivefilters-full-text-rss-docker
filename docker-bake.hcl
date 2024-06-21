variable "IMAGE" {
	default = "heussd/fivefilters-full-text-rss:latest"
}

group "default" {
	targets = ["all"]
}

target "all" {
	tags = ["${IMAGE}"]
	platforms = ["linux/amd64", "linux/arm64", "linux/arm/v7", "linux/arm/v6"]
}

target "amd64" {
	tags = ["${IMAGE}"]
	platforms = ["linux/amd64"]
}
