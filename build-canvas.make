api = 2
core = 7.x
; Include the definition for how to build Drupal core directly, including patches:
includes[] = drupal-org-core.make
; Download the canvas install profile and recursively build all its dependencies:
projects[canvas][version] = 1.x-dev
