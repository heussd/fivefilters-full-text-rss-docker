# Docker Image for fivefilters Full-Text RSS service

> [!CAUTION]
> **This project is discontinued and no longer maintained.**
>
> The image is based on PHP 5, which is end-of-life and vulnerable to the actively exploited [PHP2Shell](https://www.sophos.com/en-us/security-advisories/sophos-sa-20261021-php2shell) remote code execution vulnerabilities (CVE-2026-109618, CVE-2026-109619). Fixes are only available for PHP 8.2 and newer, and the last freely published version of Full-Text RSS does not run on PHP 8.
>
> **Do not expose this image to the internet. Please migrate away from it.** For a maintained version, see [fivefilters.org](https://www.fivefilters.org/full-text-rss/). Details: [issue #9](https://github.com/heussd/fivefilters-full-text-rss-docker/issues/9).

![Number of Image Pulls](https://img.shields.io/docker/pulls/heussd/fivefilters-full-text-rss)
![Image size](https://img.shields.io/docker/image-size/heussd/fivefilters-full-text-rss/latest)

This is a containerized version of [fivefilters full-text-rss](https://www.fivefilters.org/full-text-rss/), which retrieves the full-text of individual articles or complete full-text RSS feeds.

Not affiliated with [fivefilters.org](http://fivefilters.org/). The Dockerfile is licensed under [MIT conditions](LICENSE).

The Docker image includes [site-specific article extraction rules](https://github.com/fivefilters/ftr-site-config) as of the last build. Images are no longer rebuilt.

## User Guide

- Use the following [compose.yml](compose.yml)

```yaml
services:
  fullfeedrss:
    image: "heussd/fivefilters-full-text-rss:latest"
    mem_limit: 2G
    restart: always
    environment:
      # Leave empty to disable admin section
      - FTR_ADMIN_PASSWORD=
    volumes:
      - "rss-cache:/var/www/html/cache/rss"
      - "./custom:/var/www/html/site_config/custom"
    ports:
      - "80:80"
volumes:
  rss-cache:
```

- Start it with `docker compose up`
- Visit [http://localhost:80](http://localhost:80) for the integrated web UI

![](webui.png)

- Interesting endpoints (see tab [Request & Response](http://localhost/#request)):
  - Article extraction: `http://localhost/extract.php?url=[url]`
  - Feed conversion: `http://localhost/makefulltextfeed.php?url=[url]`
- See [calls.http](calls.http) for example calls
