#!/usr/bin/env bash
# Gera o pacote de distribuicao/revisao A PARTIR DO GIT (git archive).
#
# Nunca zipe a pasta do projeto: um pacote anterior feito assim levou o
# backend/.env (APP_KEY e as senhas dos dois papeis do banco) e um log com
# caminhos locais. git archive so inclui o que esta COMMITADO, entao .env,
# logs, vendor/, caches e .ferramentas/ ficam de fora por construcao.
#
# Uso (Git Bash no Windows, ou qualquer bash):
#   scripts/empacotar.sh            # empacota o HEAD
#   scripts/empacotar.sh <commit>   # empacota outro commit/tag
#
# Saida: entregas/cleison-<commit>-<data>.zip + .sha256 (entregas/ e ignorada).
set -euo pipefail

raiz="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$raiz"

rev="${1:-HEAD}"
# frontend entra pelo git: frontend/node_modules (ignorado) e qualquer .env
# ficam de fora por construcao, e as conferencias abaixo recusam se algum
# deles for versionado por engano.
caminhos=(backend frontend docs scripts)

git rev-parse --verify --quiet "${rev}^{commit}" >/dev/null \
  || { echo "ERRO: '${rev}' nao e um commit." >&2; exit 1; }

# Empacotando o HEAD, mudanca nao commitada ficaria de fora sem ninguem
# perceber. Recusa em vez de gerar um pacote diferente do que se ve.
if [[ "$rev" == "HEAD" ]] && [[ -n "$(git status --porcelain -- "${caminhos[@]}")" ]]; then
  echo "ERRO: ha mudancas nao commitadas em ${caminhos[*]}:" >&2
  git status --short -- "${caminhos[@]}" >&2
  echo "Commite (ou descarte) antes de empacotar." >&2
  exit 1
fi

# Conferencia 1: nomes que nunca podem ir no pacote.
# .env e qualquer .env.* (staging, dev...), menos os dois exemplos (conferidos a parte).
proibidos='(^|/)\.env($|\.(testing|production|local|staging|dev|development|prod)$)|\.log$|(^|/)vendor/|(^|/)node_modules/|\.phpunit\.result\.cache$|storage/framework/views/[^/]+\.php$|bootstrap/cache/[^/]+\.php$|(^|/)\.ferramentas/|pg-credenciais'
if git ls-tree -r --name-only "$rev" -- "${caminhos[@]}" | grep -E "$proibidos"; then
  echo "ERRO: os arquivos acima estao versionados e nao podem ser distribuidos." >&2
  echo "Tire do git com: git rm --cached <arquivo> (e rotacione o que vazou)." >&2
  exit 1
fi

# Conferencia 2: conteudo com cara de segredo (so placeholders sao aceitos).
segredos='APP_KEY=base64:|^[[:space:]]*(DB_PASSWORD|DB_MIGRACAO_PASSWORD)=[^[:space:]#]+|^[[:space:]]*DB(_MIGRACAO)?_URL=[a-z]+://[^:@[:space:]]+:[^@[:space:]]+@|base64:[A-Za-z0-9+/]{20,}|[A-Za-z]:[/\\]Users[/\\]'
# O proprio script contem os padroes; fica fora so desta conferencia.
if git grep -nIE "$segredos" "$rev" -- "${caminhos[@]}" ':(exclude)scripts/empacotar.sh'; then
  echo "ERRO: possivel segredo ou caminho local nas linhas acima. Pacote NAO gerado." >&2
  exit 1
fi

curto="$(git rev-parse --short "$rev")"
mkdir -p entregas
saida="entregas/cleison-${curto}-$(date +%Y-%m-%d).zip"

git archive --format=zip --prefix=cleison/ -o "$saida" "$rev" -- "${caminhos[@]}"

# Conferencia 3: o zip gerado, arquivo por arquivo (defesa em profundidade).
if unzip -Z1 "$saida" | sed 's|^cleison/||' | grep -E "$proibidos"; then
  rm -f "$saida"
  echo "ERRO: o zip saiu com os arquivos acima. Pacote apagado." >&2
  exit 1
fi

(cd entregas && sha256sum "$(basename "$saida")" > "$(basename "$saida").sha256")

echo "Pacote: $saida"
echo "SHA-256: $(cut -d' ' -f1 "$saida.sha256")"
echo "Conteudo: ${caminhos[*]} do commit ${curto} (sem .env, logs, vendor/, node_modules/ e caches)."
