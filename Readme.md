# Docker Image for fivefilters Full-Text RSS service

[![Build, test and push Docker images](https://github.com/heussd/fivefilters-full-text-rss-docker/actions/workflows/build-and-push.yml/badge.svg)](https://github.com/heussd/fivefilters-full-text-rss-docker/actions/workflows/build-and-push.yml)
![Last build date](https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fapi.github.com%2Frepos%2Fheussd%2Ffivefilters-full-text-rss-docker%2Factions%2Fworkflows%2F4311790%2Fruns%3Fstatus%3Dcompleted%26per_page%3D1&query=%24.workflow_runs%5B0%5D.run_started_at&label=last%20build)
![Number of Image Pulls](https://img.shields.io/docker/pulls/heussd/fivefilters-full-text-rss)
![Image size](https://img.shields.io/docker/image-size/heussd/fivefilters-full-text-rss/latest)

This is a containerized version of [fivefilters full-text-rss](https://www.fivefilters.org/full-text-rss/), which retrieves the full-text of individual articles or complete full-text RSS feeds.

Not affiliated with [fivefilters.org](http://fivefilters.org/). The Dockerfile is licensed under [MIT conditions](LICENSE).

The Docker image includes [site-specific article extraction rules](https://github.com/fivefilters/ftr-site-config) and is [rebuilt weekly](https://github.com/heussd/fivefilters-full-text-rss-docker/blob/master/.github/workflows/build-and-push.yml#L8) with the latest rules. For best results, pull the updated image regularly.

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
      # FlareSolverr URL
      - FTR_FLARESOLVERR_URL=http://flaresolverr:8191
      # Optional challenge timeout in milliseconds
      - FTR_FLARESOLVERR_TIMEOUT=60000
    volumes:
      - "rss-cache:/var/www/html/cache/rss"
      - "./custom:/var/www/html/site_config/custom"
    ports:
      - "80:80"

  flaresolverr:
    # DockerHub mirror flaresolverr/flaresolverr:latest
    image: ghcr.io/flaresolverr/flaresolverr:latest
    container_name: flaresolverr
    environment:
      - LOG_LEVEL=${LOG_LEVEL:-info}
      - LOG_FILE=${LOG_FILE:-none}
      - LOG_HTML=${LOG_HTML:-false}
      - CAPTCHA_SOLVER=${CAPTCHA_SOLVER:-none}
      - TZ=Europe/London
    ports:
      - "${PORT:-8191}:8191"
    volumes:
      - /var/lib/flaresolver:/config
    restart: unless-stopped
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

## FlareSolverr

[FlareSolverr](https://github.com/Flaresolverr/Flaresolverr) is a proxy server to bypass Cloudflare and DDoS-GUARD protection.

To disable it, clear `FTR_FLARESOLVERR_URL` and remove the `flaresolverr` service in the compose file. 

For an existing instance, set `FTR_FLARESOLVERR_URL=flaresolverr_url` and remove the bundled service.


Check **FlareSolverr** in the web UI or use:

```text
fivefilters_url/makefulltextfeed.php?url=rss_feed_url&flaresolverr=1
```

URL-encode `rss_feed_url`. This fetches both the feed and its article pages.
`/extract.php` uses FlareSolverr automatically when configured; `flaresolverr=0` disables it for a request.
