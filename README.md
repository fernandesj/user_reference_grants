# User Reference Grants

User Reference Grants gives **view** access to a piece of content to the users
referenced in its user reference fields. For example, in a "Case" content type
with "Teacher" and "Reviewer" fields, each teacher and reviewer can see only
their own cases.

- Only **view** access is granted, never update or delete.
- Only **published** content is shared.
- It uses the core **node grants** system, so access also applies to Views
  listings, entity queries and search, not only to the node page.
- Grants are **added** to those of other node access modules, such as
  Content Access. Existing access is not changed.
- Optionally, for each reference field, you can **limit the fields** those
  users can see (for example, a teacher sees the schedule but not the internal
  notes).

For a full description of the module, visit the
[project page](https://www.drupal.org/project/user_reference_grants).

To submit bug reports and feature suggestions, or to track changes, visit the
[issue queue](https://www.drupal.org/project/issues/user_reference_grants).


## Table of contents

- Requirements
- Installation
- Configuration
- How it works
- Similar modules
- Maintainers


## Requirements

This module requires only Drupal core (node and user modules), version 10.3 or
later, 11 or 12.


## Installation

Install as you would normally install a contributed Drupal module:

```bash
composer require drupal/user_reference_grants
drush en user_reference_grants
```

For further information, see
[Installing Drupal Modules](https://www.drupal.org/docs/extending-drupal/installing-drupal-modules).

Enabling the module changes no access: it starts with no content type
configured.


## Configuration

1. Go to **Administration » Configuration » People » User reference grants**
   (`/admin/config/people/user-reference-grants`).
2. For each content type, check the user reference fields whose users should
   be able to view the content.
3. Optionally, check **Limit the fields these users can see** and select the
   visible fields. Title, author and other base fields are always visible.
4. Give the **Bypass user reference field limits** permission to staff roles
   that must always see all fields.
5. Rebuild content access permissions
   (`/admin/reports/status/rebuild`, or
   `drush php:eval "node_access_rebuild(TRUE);"`). On large sites, do it
   off-peak and after a database backup.

Referenced users also need the core **View published content** permission.

**Important:** if another node access module (such as Content Access) uses a
priority higher than 0 on the same content type, Drupal ignores this module's
grants. Keep all node access modules at priority 0.

Field visibility only applies to users who are referenced in the content. Users
who can see the content for other reasons (roles, Content Access) keep seeing
the fields they could already see.


## How it works

- `hook_node_access_records()`: when a published node of a configured type is
  saved, one view grant is written per referenced user (realm
  `user_reference_grants`, grant ID = user ID).
- `hook_node_grants()`: each authenticated user holds the grant with their own
  user ID, for the view operation.
- `hook_entity_field_access()`: hides the fields not selected for the
  relationship(s) through which the user is referenced. If a user is
  referenced in several fields, the most permissive one applies.

Hooks are implemented as OOP hooks (`#[Hook]`, Drupal 11.1+), with
`#[LegacyHook]` wrappers for Drupal 10.

To uninstall, run `drush pmu user_reference_grants` and rebuild content access
permissions.


## Similar modules

- [Access by Reference](https://www.drupal.org/project/access_by_ref): grants
  view, update or delete access through several kinds of references, checked
  when a node is accessed. User Reference Grants writes node grants, so
  listings are filtered too, and it can limit visible fields.
- [Node Access by Field Role](https://www.drupal.org/project/node_access_by_field_role):
  restricts node visibility by role and user reference fields. User Reference
  Grants only adds view access and leaves existing access unchanged.
- [Node access user reference](https://www.drupal.org/project/nodeaccess_userreference):
  the classic module for this use case, without a supported stable release for
  current Drupal versions.


## Maintainers

- Centro de Formação Vale do Minho - [valedominho.com](https://valedominho.com)
