# crm
test crm

## bundles
composer require symfony/security-bundle
composer require symfony/orm-pack
<!-- composer require symfony/rate-limiter -->
composer require lexik/jwt-authentication-bundle

composer require --dev symfony/profiler-pack
composer require --dev symfony/maker-bundle

## Install

### Rsyslog
In php container execute commands
```
chmod +x bootstrap/setup-rsyslog-audit.sh
bootstrap/setup-rsyslog-audit.sh

chmod 600 /etc/logrotate.d/app-audit
```

### Debug
docker compose exec php composer show doctrine/dbal doctrine/doctrine-migrations-bundle --format=json
docker compose exec php composer update doctrine/dbal --with-dependencies --no-interaction
docker compose exec php composer show doctrine/doctrine-migrations-bundle --all --no-interaction
docker compose exec php composer show doctrine/migrations 4.0.x-dev --available --no-interaction
=> "doctrine/dbal": "^4.5@dev",

docker compose exec php composer update doctrine/dbal --with-all-dependencies --no-interaction
docker compose exec php bin/console doctrine:migrations:diff --no-interaction


### Reformat files
awk 'length($0) > 120 { print FNR ":" length($0) ":" $0 }' frontend/src/*.ts frontend/src/*.vue frontend/src/components/*.vue

find . -type f \( -name '*.vue' -o -name '*.ts' -o -name '*.css' -o -name '*.html' \) -print0 | xargs -0 awk 'length($0) > 120 { count++; print FILENAME ":" FNR ":" length($0) } END { print "TOTAL:" count }'

command -v prettier || true; find . -maxdepth 2 -type f -name 'prettier*' -o -name '.prettierrc*'

npx --yes prettier@3.6.2 --print-width 120 --write src

## commands
bin/console make:migration
bin/console cache:clear
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate --no-interaction

php bin/console lexik:jwt:generate-keypair


php bin/console messenger:consume async_incoming

php bin/console app:import-file /var/www/html/static/archive/file.csv

