# Nova Category Automation

Shopware 6 plugin: assigns products **permanently** to categories whose **part type code** matches a product property – no dynamic product groups. Codes stay invisible in the storefront.

- **Category code:** custom field "Teileart-Code" (e.g. `621`), several codes separated by commas; a code also matches as prefix (`62` covers 620–629).
- **Product code:** product custom fields `nova_teileart` / `nova_weitere_teilearten` (comma-separated), optionally property values; leading digits count (`621 Brake shoes`).
- **On save** (ERP sync, administration, import) products are assigned to matching categories and outdated assignments removed. Only categories with a code are managed.
- **Hidden properties:** the configured property groups are set to not filterable / not visible on the product page.
- **Rebuild:** switch "Reassign all products on save" runs in the background in batches of 200 (requires the message queue).

Configuration: *Extensions → My extensions → Nova Category Automation → Configure* (all sales channels).

License: MIT
