# Arquivos quentes

Arquivos que mais exigiram resolução manual de conflito nas fusões do master para branches
(medição de 23 e 24/10/2026). Com várias sessões em paralelo, são eles que travam um PR.

## Regra

Antes de editar um arquivo da tabela abaixo, avise a coordenação em uma linha:
"vou tocar em X para Y". A coordenação responde com os PRs abertos que já mexem no mesmo arquivo
e, se houver, quem entra primeiro. Quem toca depois atualiza a branch logo após o merge de quem
entrou antes (`git merge --no-edit origin/master`).

## Como saber quem mais mexe no arquivo

Sem script, só com o `gh`:

```bash
gh pr list --state open --limit 200         # lista os PRs abertos e os números
gh pr view <N> --json files --jq '.files[].path'   # arquivos que o PR <N> altera
```

Para varrer todos de uma vez e procurar um arquivo:

```bash
for n in $(gh pr list --state open --limit 200 --json number --jq '.[].number'); do
  gh pr view "$n" --json number,title,files \
    --jq 'select(.files[].path == "app/config/services.yaml") | "#\(.number) \(.title)"'
done
```

Troque o caminho do `select` pelo arquivo que você vai tocar. Saída vazia quer dizer que nenhum
PR aberto mexe nele. Sem o `--limit`, o `gh pr list` devolve só 30 PRs. O `gh pr view` também
corta a lista de arquivos de um PR muito grande (em torno de 100), então, num PR grande, confira
o arquivo na aba "Files changed".

A consulta só enxerga PRs já abertos. Alterações locais ainda não publicadas não aparecem. O aviso à
coordenação continua necessário, mesmo com a saída vazia.

## Tabela

| Arquivo | Resoluções manuais | Cuidado |
|---|--:|---|
| app/translations/messages+intl-icu.pt_BR.yaml | 32 | **deixou de ser quente em 26/10/2026**: virou um arquivo por bloco em `app/translations/<bloco>/` (ver `app/translations/LEIA-ME.md`). Área nova = pasta nova. Chaves antigas na sua branch: `scripts/traducao-rebaixar.py` |
| app/templates/pasta/_cabecalho.html.twig, show.html.twig, app/public/css/pasta-show.css | 5, 4, 5 | só uma sessão por vez na tela da pasta |
| app/config/services.yaml | 5 | |
| app/tests/Shared/Functional/TenantAwareCoberturaTest.php (teto) | 4 | o teto sobe a cada entidade nova; trazer o master antes de subir |
| app/tests/Auditoria/Functional/AuditavelCoberturaTest.php (teto) | 4 | idem |
| app/tests/Shared/Unit/MapaDeChaveParaCaminhoLocalTest.php, app/src/Shared/Armazenamento/ResolvedorDeCaminhoLocal.php | 4, 4 | categorias de arquivo do R2 |
| app/src/Tenant/UseCase/PurgarEscritorioUseCase.php, app/src/Controller/TarefaController.php | 4, 4 | |
| app/tests/Arquitetura/templates-extraidos.json | 3 | |
| migrations (app/migrations) | | número pedido à coordenação |

## Medição repetível

```bash
git log origin/master --merges --grep="Merge remote-tracking branch 'origin/master'"
git diff-tree --cc --name-only <sha-da-fusão>
```

Rode o `diff-tree` em cada fusão listada: os arquivos que aparecem repetidamente são os quentes.
Atualize a tabela quando a medição mudar.
