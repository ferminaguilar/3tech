cd ${DOCROOT}/modules/contrib/mercury_editor/tests/cypress
npm run cy:run -- --config baseUrl=$TUGBOAT_DEFAULT_SERVICE_URL --spec ./cypress/e2e/mercury-editor/ --env drushCommand="$DRUPAL_COMPOSER_ROOT/vendor/bin/drush \$COMMAND"
