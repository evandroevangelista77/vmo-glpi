#!/usr/bin/env bash
# Atualiza o VMO a partir do GitHub, direto no servidor do GLPI (nada depende de um Mac).
# Rode NO SERVIDOR:   sudo bash tools/deploy.sh      (ou:  sudo glpi-atualizar-plugins vmo)
# O script glpi-atualizar-plugins fica em:
#   https://github.com/evandroevangelista77/dashboard-datacenter-360/blob/main/tools/glpi-atualizar-plugins.sh
set -euo pipefail
if command -v glpi-atualizar-plugins >/dev/null 2>&1; then
  exec glpi-atualizar-plugins vmo
fi
curl -fsSL https://raw.githubusercontent.com/evandroevangelista77/dashboard-datacenter-360/main/tools/glpi-atualizar-plugins.sh | bash -s -- vmo
