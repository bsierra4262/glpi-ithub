#!/usr/bin/env bash
# Despliegue del plugin en la Mesa de Ayuda (GLPI 12 en el hosting de Hostinger).
#
#   bin/deploy.sh          copia plugin/ithub a GLPI/plugins/ithub (con respaldo del anterior)
#   bin/deploy.sh --test   además corre test/glpi-servidor.php en el servidor
#
# No toca la configuración (GLPI/config/ithub.php) ni ningún archivo de GLPI. La primera vez,
# después de copiarlo: php bin/console plugin:install ithub --username=admin && plugin:activate ithub
# (con un respaldo de la base antes: ~/backups/glpi-<fecha>.sql.gz).
set -euo pipefail

SSH_HOST="u566451014@151.106.96.54"
SSH_OPTS=(-i "$HOME/.ssh/intellihelp_deploy" -p 65002 -o BatchMode=yes)
GLPI='domains/intellihelp.tech/public_html/GLPI'
PHP=/opt/alt/php83/usr/bin/php
cd "$(dirname "$0")/.."

TS=$(date +%Y%m%d-%H%M%S)
tar czf - -C plugin ithub | ssh "${SSH_OPTS[@]}" "$SSH_HOST" "set -e
  cd ~/$GLPI/plugins
  mkdir -p ~/backups
  if [ -d ithub ]; then tar czf ~/backups/glpi-plugin-ithub-$TS.tgz ithub; fi
  rm -rf ithub.nuevo && mkdir ithub.nuevo && tar xzf - -C ithub.nuevo
  rm -rf ithub.viejo && { [ -d ithub ] && mv ithub ithub.viejo || true; } && mv ithub.nuevo/ithub ithub
  rm -rf ithub.nuevo ithub.viejo
  echo \"plugin copiado (respaldo: ~/backups/glpi-plugin-ithub-$TS.tgz)\""

if [[ "${1:-}" == "--test" ]]; then
  scp -q -i "$HOME/.ssh/intellihelp_deploy" -P 65002 test/glpi-servidor.php "$SSH_HOST:glpi-ithub-prueba.php"
  ssh "${SSH_OPTS[@]}" "$SSH_HOST" "$PHP ~/glpi-ithub-prueba.php; rm -f ~/glpi-ithub-prueba.php"
fi

# Verificación HTTP: el login de siempre carga y el ingreso con ITHub arranca con PKCE.
curl -s https://ayuda.intellihelp.tech/ | grep -q 'name="login_password"' && echo "login de GLPI: ok"
curl -s -o /dev/null -w '%{redirect_url}\n' https://ayuda.intellihelp.tech/plugins/ithub/front/login.php \
  | grep -q 'code_challenge_method=S256' && echo "ingreso con ITHub: ok"
