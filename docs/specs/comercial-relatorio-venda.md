# Relatório da Central de envios também na seção Indicações, e os textos dele no catálogo

Desenho 05 VS 21.10.26: o mesmo Relatório (L300-305, L502-514, L698-717) aparece abaixo da Central também na seção
"Indicações do BlueJus" (`secCentral`, modo venda, L627-645). Continua a F3d (`comercial-relatorio.md`), a F3e
(`comercial-marcas.md`) e o modo venda da b5 (#223, `comercial-e-emails.md` §10). Frente da 9b,
`comercial-relatorio-venda`. Sem migration. Risco BAIXO. Pedido do coordenador e ponto de entrada combinado com a b5
em 21/10/2026.

## Estado da frente

- 21/10: spec; implementação começando.
- 21/10: Parte 1 feita (commit local "Mostra o Relatório também na seção Indicações"): o include sai do
  `{% if not venda %}` e fica na raiz de cada modo; o parcial ganhou `secao` (em `data-mc-relatorio="com|ind"` e no
  sufixo dos ids) e `recursos`; o JS inicia uma instância por raiz (de novo no `DOMContentLoaded`, porque o script vem
  com a seção Comercial, antes da raiz da venda no HTML). Teste novo `DuasInstanciasDoRelatorioTest`.
- 21/10: Parte 2 feita (commit local "Leva os textos do Relatório ao catálogo de idioma"): bloco `comercial_relatorio`
  no catálogo (Twig e `comercial_relatorio.js`), área `comercial_relatorio` no `templates-extraidos.json` (permitidos:
  "B" do avatar, "Content-Type" e "Escape"; "CSV" não precisou, só aparece dentro de frase do catálogo). O JS passou a
  iniciar só com o documento pronto (o `bj-traducao.js` vem no fim do base). O HTML das duas seções foi comparado antes
  e depois: só mudaram os ids com sufixo, o `data-mc-relatorio="com|ind"`, os tokens e o JSON do catálogo.

## Parte 1: duas instâncias

1. **Entrada** (combinado com a b5): no `_comercial.html.twig`, o include do relatório sai do `{% if not venda %}` e
   fica dentro da raiz de cada modo (`.mc[data-modo]`), depois da Central:
   `{{ include('master/_comercial_relatorio.html.twig', {secao: venda ? 'ind' : 'com', recursos: not venda}) }}`.
   Na seção Comercial ele continua no mesmo lugar visual de hoje (depois das Automações e dos KPIs, como já está).
   `recursos: false` não repete o `<link>`, o `<script>` nem o catálogo do JS (a seção Comercial está sempre no DOM:
   o painel inclui as três seções, as fechadas com `hidden`).
2. **A lista é a mesma nas duas** (o dc usa o mesmo `H`; a campanha não guarda a origem): mesmas rotas, abas, marcas
   e CSV. Nenhuma mudança no servidor.
3. **`comercial-relatorio.js` multi-instância:** um init por `[data-mc-relatorio]` (idempotente: marca a raiz como
   iniciada), estado, timers e seleção por instância; ids do HTML com sufixo da seção (`-com` / `-ind`) para os
   `aria-labelledby`/`id` não repetirem. Os ouvintes de `document` (Esc do menu e do visualizador,
   `mc:campanha-confirmada`) passam a agir só na instância envolvida (Esc: a que tem menu ou visualizador aberto); a
   `mc:campanha-confirmada` recarrega todas. A carga ao abrir a seção vale para a seção de cada instância.
4. **Duplicar:** o evento `mc:duplicar-campanha` passa a ser disparado na raiz do relatório, com `bubbles: true`
   (continua cancelável, mesmo `detail`). Sem ninguém tratando, o preenchimento usa os `[data-mc-cp-campo]` de
   `relatorio.closest('.mc[data-modo]')`, ou seja, o compositor da mesma seção. Hoje ninguém escuta o evento.
5. Marcar ou cancelar numa instância recarrega a outra também (as duas mostram a mesma lista).

## Parte 2: textos no catálogo (molde do #228, `idioma-do-sistema.md` §8)

- Área nova no catálogo `translations/messages+intl-icu.pt_BR.yaml`: bloco `comercial_relatorio` (Twig) e
  `comercial_relatorio.js` (JS, só `{x}`), sem mexer nos blocos de outras áreas.
- `_comercial_relatorio.html.twig` com `|trans`; o JS lê `window.BJ_T.t('comercial_relatorio.js....')`, com o
  catálogo entregue pelo `{{ catalogo_js('comercial_relatorio.js') }}` só na instância com `recursos`.
- O parcial e o JS entram em `tests/Arquitetura/templates-extraidos.json` (área `comercial_relatorio`, com os
  `permitidos` necessários: siglas como CSV).
- **Fica de fora:** o texto que vem do PHP (rótulos de estado, KPIs, dica e leitura do `MetricasDaCampanha`, recusas
  dos UseCases), como nas outras áreas.
- **Sem mudança visível:** o HTML do relatório nas duas seções antes e depois é o mesmo (fora tokens e o JSON do
  catálogo).
