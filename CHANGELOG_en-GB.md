# 1.2.0
- Rebuild always uses the current category codes (the background worker kept the codes of its first run in memory)
- After the update, a rebuild of all products is started once automatically (only if active)
- At most one rebuild runs at a time; saving again with unchanged codes does not start a second one, changed codes supersede the running one
- Rebuild pages by auto increment instead of offset
- Saving a product only re-indexes the category tree, and product, translation and property writes of one save are evaluated once
- Assignments are written with system scope (no ACL errors for restricted integrations)
- Variants are evaluated via their parent and no longer get own assignments
- Removed assignments, products without code losing categories (warning) and rebuild start/end are logged to Settings → System → Event logs

# 1.1.2
- Own plugin icon (previously showed the Nova Upload icon by mistake)

# 1.1.1
- German configuration labels now shown in the German administration

# 1.1.0
- Codes also read from product custom fields nova_teileart / nova_weitere_teilearten; properties optional

# 1.0.0
- First version: permanent category assignment by part type code, hidden properties, background rebuild
