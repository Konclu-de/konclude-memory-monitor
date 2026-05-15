# Changelog

All notable changes to Konclude Memory Monitor will be documented in this file.

---

## [2.0.0] — 2026-05-15

### Added

#### Per-Plugin Memory Tracking
- Hooked into `plugin_loaded` to record memory before and after every plugin file is included
- Each plugin now carries a genuine **load-time memory delta** rather than a simple presence in the active plugin list
- Lifecycle snapshots across `muplugins_loaded`, `plugins_loaded`, `after_setup_theme`, `init`, `wp_loaded`,
and `template_redirect` to expose where memory actually jumps for plugins that defer work to later hooks

#### Admin UI — Three Dedicated Pages
- **Request Log** — displays every captured request showing peak memory, percentage of PHP limit, top 5 plugin
deltas, theme setup delta, and a collapsible lifecycle breakdown per request
- **Plugin Stats** — aggregates load-time deltas across all log entries, ranks worst offenders by average delta,
and renders a colour-coded relative footprint bar per plugin
- **Settings** — dedicated settings page replacing the inline form, covering log threshold, log-all toggle,
alert recipients, alert threshold, and cooldown

#### Email Alerts
- Configurable recipient list supporting multiple comma-separated email addresses
- Independent **alert threshold** separate from the log threshold
- Transient-based cooldown between alerts to prevent inbox flooding during sustained high-memory periods
- Alert email body includes peak memory, percentage of limit, top 10 plugin load-time deltas, active theme
setup delta, and full lifecycle snapshots

#### Menu
- Moved from **Tools** submenu (`add_management_page`) to a **top-level admin menu** entry (`add_menu_page`)
at position `3`, just below Dashboard
- Uses `dashicons-performance` icon
- Three named submenu entries: Request Log, Plugin Stats, Settings

#### Logging
- Added **Log all requests** toggle to capture every request regardless of memory usage,
for short diagnosis sessions
- Maximum log retention increased from **100** to **200** entries
- Each log entry now stores plugin deltas, theme delta, lifecycle snapshots, and request type
alongside existing fields

### Changed
- Settings option key updated from `amm_memory_settings` to `kmm_settings` and log key
from `amm_memory_log` to `kmm_log` for namespace consistency
- Threshold minimum remains **16 MB**; default lowered from **128 MB** to **64 MB**
to catch more requests out of the box
- Request type detection logic unchanged but now displayed as a colour-coded pill badge in the log table

### Removed
- Plugin list column (plain active plugin dump) replaced by actionable per-plugin memory delta data
- Settings form embedded inside the log page — moved to its own dedicated Settings subpage

---

## [1.0.0] — Initial Release

### Added
- Shutdown hook to capture peak memory at end of each request
- Threshold-based logging to WordPress options
- Request type detection (`frontend`, `admin`, `ajax`, `rest`, `cron`)
- Log table under **Tools → Memory Monitor** showing date, type, URI, peak MB, memory limit,
plugin count, and active plugin list
- Settings form for threshold and enable/disable toggle
- Clear log action with nonce verification
- Multisite support for network-active pluginso

