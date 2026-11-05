# Nova Category Automation

Shopware 6 plugin: assigns products **permanently** to categories whose **part type code** matches a product property – no dynamic product groups. Codes stay invisible in the storefront.

- **Category code:** custom field "Teileart-Code" (e.g. `621`), several codes separated by commas; a code also matches as prefix (`62` covers 620–629). Single-digit codes (`1` covers 1–1999) are allowed, e.g. for a collection category “All parts” with `1,2,3,4,5,6,7,9`; they are reported as a warning in the event log when a rebuild starts.
- **Product code:** product custom fields `nova_teileart` / `nova_weitere_teilearten` (comma-separated), optionally property values; leading digits count (`621 Brake shoes`).
- **On save** (ERP sync, administration, import) products are assigned to matching categories and outdated assignments removed. Only categories with a code are managed.
- **Hidden properties:** the configured property groups are set to not filterable / not visible on the product page.
- **Rebuild:** switch "Reassign all products on save" runs in the background in batches of 200 (requires the message queue), always with the current codes; at most one run at a time. Started once automatically only when updating from a version before 1.2.0; later updates do not reassign anything unattended. An aborted run is logged as an error. The stored switch resets itself; reload the page to see it.
- **Variants** are evaluated via their parent product and inherit its categories.
- **Log:** removed assignments and rebuild start/end appear under Settings → System → Event logs.

Configuration: *Extensions → My extensions → Nova Category Automation → Configure* (all sales channels).

License: MIT

## How this plugin was made

This plugin was developed with the help of AI (Claude by Anthropic). The requirements come from running several Shopware shops day to day; the code was written and revised with AI. It is checked with automated tests and static analysis (where present in the repository), regular security reviews of the code and in production use in the author's own shops. Still: use at your own risk and without warranty (see license). Please report bugs and suggestions as an issue. More background: [SW6-Nova-Plugins](https://github.com/DrdotHouse2106/SW6-Nova-Plugins).
