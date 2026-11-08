#!/bin/bash
# Publica o plugin no GLPI do laboratório e instala/atualiza.
#   tools/deploy.sh                      -> GLPI 12 do lab (/var/www/glpi)
#   tools/deploy.sh /var/www/glpi11test  -> outro GLPI (ex.: o 11 de teste)
set -euo pipefail
HOST="${VMO_HOST:-evandro@192.168.15.45}"
GLPI="${1:-/var/www/glpi}"
cd "$(dirname "$0")/.."
COPYFILE_DISABLE=1 tar --exclude=docs --exclude=.DS_Store --exclude=.git -cf - . \
  | ssh "$HOST" "sudo rm -rf $GLPI/plugins/vmo.new && sudo mkdir -p $GLPI/plugins/vmo.new && sudo tar -xf - -C $GLPI/plugins/vmo.new \
     && sudo rm -rf $GLPI/plugins/vmo && sudo mv $GLPI/plugins/vmo.new $GLPI/plugins/vmo && sudo chown -R www-data:www-data $GLPI/plugins/vmo \
     && cd $GLPI && (sudo -u www-data php bin/console plugin:install vmo --username=glpi -n || true) && sudo -u www-data php bin/console plugin:activate vmo -n && sudo -u www-data php bin/console plugin:list | grep -i vmo"
