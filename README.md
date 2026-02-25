This is highly experimental chatbot and agent orchestration module for Drupal. It is intended for testing and development purposes only, and should not be used in production environments. The APIs, tools, and agents provided by this module are subject to frequent breaking changes as we iterate on the design and implementation.

### Install via ddev
```
ddev composer config repositories.ai_agents_orchestrator vcs https://github.com/ivanboring/ai_agents_orchestrator
ddev composer require ivanboring/ai_agents_orchestrator:dev-main
ddev exec "cd web/modules/custom/ai_agents_orchestrator/js && npm install && npm run build"
ddev drush pm:en ai_agents_orchestrator
```
