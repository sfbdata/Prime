# Catálogo pt_BR: um arquivo por bloco

Um arquivo por área: `translations/<bloco>/messages+intl-icu.pt_BR.yaml`. O domínio continua
`messages` (ICU), então nenhum `|trans`, `BJ_T.t()` ou `trans()` muda: o Symfony varre as subpastas e
funde tudo. Este LEIA-ME não é lido pelo tradutor (só arquivo `domínio.locale.formato` entra).

Regras (o `CatalogoPorBlocoTest` confere):
- O arquivo da pasta `x/` tem um único bloco de topo, `x:`. Bloco repetido em dois arquivos se fundiria em silêncio.
- Área nova = pasta nova com um arquivo novo (zero conflito). Área existente = edite só o arquivo dela.
- Nada de `messages+intl-icu.pt_BR.yaml` na raiz de `translations/`.
- Teste que precisa ler o catálogo usa `App\Tests\Support\CatalogoPtBr` (`carregar()` devolve o array fundido).

Formato do catálogo, a fonte do texto da tela (spec docs/specs/idioma-do-sistema.md):
- ICU: parâmetro é `{nome}`; apóstrofo se escreve `''`. Chave do JS (sob `<area>.js`) vai crua ao navegador: só usa
  `{nome}`, sem plural, e o apóstrofo fica simples (lá ninguém desfaz o `''`).
- Não entram: nomes dos idiomas (cada um no próprio idioma), nomes de pessoas, números, CPF/CNPJ, citações de lei.

Quem ficou com chaves novas no arquivo único antigo: `scripts/traducao-rebaixar.py` (instruções no topo do script).
