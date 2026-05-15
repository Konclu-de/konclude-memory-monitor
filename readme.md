markdown
# Konclude Memory Monitor

A WordPress plugin that tracks per-plugin memory usage, logs high-memory requests,
identifies problematic plugins and themes, and sends email alerts when memory runs high.

Built by [Konclu.de](https://konclu.de) (Archie Makuwa).

---

## Features

- **Per-plugin memory delta tracking** — measures memory consumed by each plugin at load time
- **Lifecycle snapshots** — records memory at every major WordPress boot stage
- **Request log** — captures peak memory, request type, URI, and plugin deltas per request
- **Plugin Stats page** — aggregates and ranks plugins by average memory footprint across all logged requests
- **Theme delta tracking** — measures memory consumed during active theme setup
- **Email alerts** — notifies one or more recipients when peak memory exceeds a threshold
- **Alert cooldown** — transient-based rate limiting prevents inbox flooding
- **Log all requests** toggle — captures every request for short diagnosis sessions
- **Top-level admin menu** — accessible directly from the WordPress dashboard sidebar

---

## Requirements

| Requirement | Minimum |
|-------------|---------|
| WordPress   | 6.0     |
| PHP         | 7.4     |

---

## Installation

1. Download or clone this repository into your `wp-content/plugins/` directory
2. Log in to your WordPress admin panel
3. Go to **Plugins → Installed Plugins**
4. Activate **Konclude Memory Monitor**
5. Navigate to **Memory Monitor → Settings** to configure your thresholds and alert recipients

---

## Usage

### Request Log

**Memory Monitor → Request Log**

Displays the last 200 captured requests. Each row shows:

| Column | Description |
|--------|-------------|
| Date / Time | When the request was captured |
| Type | `frontend`, `admin`, `ajax`, `rest`, or `cron` |
| Request URI | The URL path of the request |
| Peak Memory | Highest memory reading during the request with a % of PHP limit bar |
| PHP Limit | The `memory_limit` value active during the request |
| Top Plugin Deltas | The 5 plugins that consumed the most memory at load time |
| Theme Δ | Memory consumed during active theme setup |
| Lifecycle | Collapsible table of memory readings at each WordPress boot stage |

---

### Plugin Stats

**Memory Monitor → Plugin Stats**

Aggregates load-time memory deltas across all logged requests and ranks plugins
from highest to lowest average footprint.

| Column | Description |
|--------|-------------|
| Plugin | Plugin folder/file path |
| Seen in | Number of logged requests the plugin appeared in |
| Avg load Δ | Average memory consumed at include time |
| Max load Δ | Highest single-request load-time delta recorded |
| Total load Δ | Sum of all load-time deltas across all requests |
| Relative footprint | Colour-coded bar scaled to the worst offender |

> **Note:** Load-time deltas measure memory allocated when each plugin's main file is
> included. Plugins that defer all work to `init` or later hooks will show a smaller
> delta here but may still contribute significantly to peak memory. Cross-reference
> with the Lifecycle column in the Request Log for the full picture.

---

### Settings

**Memory Monitor → Settings**

#### General

| Setting | Description |
|---------|-------------|
| Enable monitoring | Master on/off switch |
| Log every request | Captures all requests regardless of memory usage. Good for short diagnosis sessions |
| Log threshold (MB) | Only used when *Log every request* is off. Requests at or above this peak are logged. Minimum: 16 MB. Default: 64 MB |

#### Email Alerts

| Setting | Description |
|---------|-------------|
| Enable email alerts | Master on/off switch for alerts |
| Alert recipients | Comma-separated list of email addresses to notify |
| Alert threshold (MB) | Send an alert when peak memory reaches or exceeds this value. Default: 128 MB |
| Cooldown between alerts | Minimum minutes between alert emails to prevent flooding. Default: 60 minutes |

---

## How Memory Tracking Works

WordPress loads plugins sequentially. At each step we record memory before and after:

- `muplugins_loaded` — baseline snapshot taken before regular plugins load
- `plugin_loaded` — fires after every plugin file is included, delta is recorded
- `plugins_loaded` — snapshot after all plugins are loaded
- `after_setup_theme` — snapshot after theme setup, theme delta calculated from here
- `init` — snapshot at init
- `wp_loaded` — snapshot when WordPress and all plugins are fully loaded
- `template_redirect` — snapshot just before the template is served
- `shutdown` — peak is recorded, log entry is written, alert fired if threshold exceeded

Each plugin delta is the difference in allocated memory immediately before and after
that plugin's file is included. Deltas are stored in MB and aggregated in the
Plugin Stats page.

---

## Email Alert Format

When peak memory exceeds the alert threshold and the cooldown has expired, an email
is dispatched to all configured recipients containing:

- Site name and URL
- Request URI and type
- Peak memory and percentage of PHP limit
- Top 10 plugins by load-time delta
- Active theme setup delta
- Full lifecycle snapshot table
- Direct link to the Request Log

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

---

## License

[GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html)

---

## Author

**Archie Makuwa** — [konclu.de](https://konclu.de)